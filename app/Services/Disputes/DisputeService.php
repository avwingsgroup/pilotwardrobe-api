<?php

declare(strict_types=1);

namespace App\Services\Disputes;

use App\Enums\DisputeStatus;
use App\Enums\EscrowStatus;
use App\Enums\MediaType;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\DisputeProposal;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Orders\RefundService;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Buyer protection (VB14/VB15 parties, AD9 mediation; handoff §8.7).
 *
 * Opening a dispute freezes the seller's proceeds (escrow `frozen`, or — for an order already
 * completed inside the dispute window — keeps the pending-release funds from settling).
 * Resolutions execute the money movement immediately: release, partial or full refund.
 */
final class DisputeService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly OrderService $orders,
        private readonly RefundService $refunds,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{reason: string, requested: string, amount?: ?int, description: string, evidence_ids?: list<string>} $data */
    public function open(User $buyer, Order $order, array $data): Dispute
    {
        $dispute = DB::transaction(function () use ($buyer, $order, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->isBuyer($buyer)) {
                throw ApiException::notFound('Order not found.');
            }

            $inWindow = $order->status === OrderStatus::Delivered
                || ($order->status === OrderStatus::Completed && $order->dispute_window_ends_at?->isFuture() && $order->funds_settled_at === null)
                || (in_array($order->status, [OrderStatus::InTransit, OrderStatus::OutForDelivery], true)
                    && $data['reason'] === 'not_received' && $order->estimated_delivery_to?->isPast());
            if (! $inWindow) {
                throw ApiException::invalidTransition('A dispute can be opened once the item has landed, within the protection window.');
            }
            if ($order->disputes()->whereIn('status', array_map(fn ($s) => $s->value, DisputeStatus::active()))->exists()) {
                throw ApiException::invalidTransition('There is already an open dispute for this order.');
            }

            $frozen = $order->total_amount - $order->refunded_amount;
            $requestedAmount = match ($data['requested']) {
                'partial_refund' => (int) ($data['amount'] ?? 0),
                'full_refund', 'return_and_refund' => $frozen,
                default => null,
            };
            if ($data['requested'] === 'partial_refund' && ($requestedAmount <= 0 || $requestedAmount > $frozen)) {
                throw ApiException::validation(['amount' => 'Enter an amount up to '.$order->money($frozen)->format().'.']);
            }

            $dispute = Dispute::query()->create([
                'number' => ReferenceGenerator::unique(Dispute::class, 'number', fn () => ReferenceGenerator::prefixed('DS', 6)),
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'seller_id' => $order->seller_id,
                'reason' => $data['reason'],
                'requested' => $data['requested'],
                'requested_amount' => $requestedAmount,
                'currency' => $order->currency,
                'frozen_amount' => $frozen,
                'description' => trim($data['description']),
                'status' => DisputeStatus::AwaitingSeller,
                'previous_order_status' => $order->status->value,
                'respond_by' => now()->addHours((int) config('platform.disputes.seller_response_hours')),
                'decision_due_at' => now()->addWeekdays((int) config('platform.disputes.mediation_business_days') + 2),
            ]);

            foreach ($this->media->claim($buyer, $data['evidence_ids'] ?? [], [MediaType::DisputeEvidence], 'evidence_ids', $dispute) as $i => $file) {
                $file->attachTo($dispute, 'evidence', $i);
            }
            DisputeMessage::query()->create(['dispute_id' => $dispute->id, 'author_id' => $buyer->id, 'author_role' => 'buyer', 'body' => $dispute->description]);

            $order->status = OrderStatus::Disputed;
            if ($order->escrow_status === EscrowStatus::Held) {
                $order->escrow_status = EscrowStatus::Frozen;
            }
            $order->save();
            $this->orders->event($order, 'disputed', 'Dispute opened', Dispute::REASONS[$data['reason']] ?? $data['reason'], $buyer);
            $this->audit->log('DISPUTE.OPENED', $dispute, $data['reason'], 'warning', actor: $buyer);

            return $dispute;
        });

        $data = $this->data($dispute);
        $this->notifications->notify($dispute->seller, 'dispute.opened', $data, "dispute.opened:{$dispute->id}");
        $this->notifications->notify($dispute->buyer, 'dispute.received', $data, "dispute.received:{$dispute->id}");

        return $dispute;
    }

    /** Party statement / evidence. A seller reply moves the case to mediator review. */
    public function addMessage(Dispute $dispute, User $author, string $body, array $evidenceIds = [], bool $internal = false, bool $asMediator = false): Dispute
    {
        $dispute = DB::transaction(function () use ($dispute, $author, $body, $evidenceIds, $internal, $asMediator) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if (! $dispute->status->isActive()) {
                throw ApiException::invalidTransition('This dispute is closed.');
            }
            $role = $asMediator ? 'mediator' : ($author->id === $dispute->buyer_id ? 'buyer' : 'seller');

            $message = DisputeMessage::query()->create([
                'dispute_id' => $dispute->id,
                'author_id' => $author->id,
                'author_role' => $role,
                'body' => trim($body),
                'is_internal' => $internal && $asMediator,
            ]);
            $types = $asMediator ? [MediaType::DisputeEvidence, MediaType::Content] : [MediaType::DisputeEvidence];
            foreach ($this->media->claim($author, $evidenceIds, $types, 'evidence_ids', $message) as $i => $file) {
                $file->attachTo($message, 'evidence', $i);
            }

            if ($role === 'seller' && $dispute->status === DisputeStatus::AwaitingSeller) {
                $dispute->status = DisputeStatus::UnderReview;
            }
            if ($asMediator && $dispute->mediator_id === null) {
                $dispute->mediator_id = $author->id;
            }
            $dispute->save();

            return $dispute;
        });

        if (! ($internal && $asMediator)) {
            $recipients = $asMediator ? [$dispute->buyer, $dispute->seller] : [$author->id === $dispute->buyer_id ? $dispute->seller : $dispute->buyer];
            foreach ($recipients as $recipient) {
                $this->notifications->notify($recipient, 'dispute.updated', $this->data($dispute) + ['summary' => 'New message: '.mb_substr($body, 0, 100)],
                    "dispute.updated:{$dispute->id}:{$recipient->id}:".now()->format('YmdHis'));
            }
        }

        return $dispute;
    }

    /** Seller settlement proposal (addition: `POST /disputes/{id}/proposal`). */
    public function propose(Dispute $dispute, User $seller, string $kind, int $amount, ?string $summary): Dispute
    {
        $dispute = DB::transaction(function () use ($dispute, $seller, $kind, $amount, $summary) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($seller->id !== $dispute->seller_id) {
                throw ApiException::forbidden('Only the seller can propose a settlement.');
            }
            if (! $dispute->status->isActive()) {
                throw ApiException::invalidTransition('This dispute is closed.');
            }
            if ($amount <= 0 || $amount > $dispute->frozen_amount) {
                throw ApiException::validation(['amount' => 'Enter an amount up to '.$dispute->money($dispute->frozen_amount)->format().'.']);
            }

            DisputeProposal::query()->where('dispute_id', $dispute->id)->where('status', 'pending')->update(['status' => 'superseded']);
            DisputeProposal::query()->create([
                'dispute_id' => $dispute->id,
                'proposed_by' => $seller->id,
                'kind' => $kind,
                'amount' => $amount,
                'summary' => $summary ?: ($kind === 'return_and_refund'
                    ? 'Return the item for a refund of '.$dispute->money($amount)->format().'. Return label paid by seller.'
                    : 'Partial refund of '.$dispute->money($amount)->format().'; keep the item.'),
                'status' => 'pending',
            ]);
            if ($dispute->status === DisputeStatus::AwaitingSeller) {
                $dispute->status = DisputeStatus::UnderReview;
                $dispute->save();
            }

            return $dispute;
        });

        $this->notifications->notify($dispute->buyer, 'dispute.updated', $this->data($dispute) + ['summary' => 'The seller proposed a settlement.'], "dispute.proposal:{$dispute->id}:".now()->timestamp);

        return $dispute;
    }

    public function acceptProposal(Dispute $dispute, User $buyer): Dispute
    {
        $proposal = DB::transaction(function () use ($dispute, $buyer) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($buyer->id !== $dispute->buyer_id) {
                throw ApiException::forbidden('Only the buyer can accept the proposal.');
            }
            $proposal = DisputeProposal::query()->where('dispute_id', $dispute->id)->where('status', 'pending')->lockForUpdate()->latest()->first();
            if ($proposal === null || ! $dispute->status->isActive()) {
                throw ApiException::invalidTransition('There is no open proposal to accept.');
            }
            $proposal->forceFill(['status' => 'accepted', 'responded_at' => now()])->save();

            return $proposal;
        });

        return $this->resolve($dispute->fresh(), $proposal->amount, 'Seller proposal accepted', $buyer, 'proposal_accepted');
    }

    public function declineProposal(Dispute $dispute, User $buyer): Dispute
    {
        return DB::transaction(function () use ($dispute, $buyer) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($buyer->id !== $dispute->buyer_id) {
                throw ApiException::forbidden('Only the buyer can decline the proposal.');
            }
            $updated = DisputeProposal::query()->where('dispute_id', $dispute->id)->where('status', 'pending')->update(['status' => 'declined', 'responded_at' => now()]);
            if ($updated === 0) {
                throw ApiException::invalidTransition('There is no open proposal to decline.');
            }
            if ($dispute->status !== DisputeStatus::UnderReview && $dispute->status->canTransitionTo(DisputeStatus::UnderReview)) {
                $dispute->status = DisputeStatus::UnderReview;
                $dispute->save();
            }

            return $dispute;
        });
    }

    /** Buyer withdraws: escrow unfreezes and the order returns to where it was. */
    public function withdraw(Dispute $dispute, User $buyer): Dispute
    {
        return DB::transaction(function () use ($dispute, $buyer) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($buyer->id !== $dispute->buyer_id) {
                throw ApiException::forbidden('Only the buyer can withdraw the dispute.');
            }
            $dispute->status->assertCanTransitionTo(DisputeStatus::Withdrawn, 'This dispute is already closed.');

            $dispute->forceFill(['status' => DisputeStatus::Withdrawn, 'resolved_at' => now()])->save();
            $order = Order::query()->lockForUpdate()->findOrFail($dispute->order_id);
            $order->status = OrderStatus::from($dispute->previous_order_status ?? 'delivered');
            if ($order->escrow_status === EscrowStatus::Frozen) {
                $order->escrow_status = EscrowStatus::Held;
                // Give the buyer's clock back what the dispute consumed, at least 24 h.
                $order->auto_release_at = now()->addDay()->max($order->auto_release_at ?? now());
            }
            $order->save();
            $this->orders->event($order, 'released', 'Dispute withdrawn', null, $buyer);
            $this->audit->log('DISPUTE.WITHDRAWN', $dispute, null, actor: $buyer);

            return $dispute;
        });
    }

    /**
     * Executes an outcome: `$amountToBuyer` 0 releases everything to the seller; the full
     * frozen amount refunds the buyer; anything in between is a split.
     */
    public function resolve(Dispute $dispute, int $amountToBuyer, string $summary, ?User $actor, ?string $decision = null, ?string $policy = null): Dispute
    {
        $refundable = $dispute->frozen_amount;
        if ($amountToBuyer < 0 || $amountToBuyer > $refundable) {
            throw ApiException::validation(['amount_to_buyer' => 'Enter an amount up to '.$dispute->money($refundable)->format().'.']);
        }

        $status = match (true) {
            $amountToBuyer === 0 => DisputeStatus::ResolvedSeller,
            $amountToBuyer === $refundable => DisputeStatus::ResolvedBuyer,
            default => DisputeStatus::ResolvedSplit,
        };

        DB::transaction(function () use ($dispute, $amountToBuyer, $summary, $actor, $decision, $policy, $status) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            $dispute->status->assertCanTransitionTo($status, 'This dispute is already closed.');

            $dispute->forceFill([
                'status' => $status,
                'decision' => $decision,
                'amount_to_buyer' => $amountToBuyer,
                'policy_reference' => $policy,
                'resolved_at' => now(),
                'mediator_id' => $dispute->mediator_id ?? ($actor?->isStaff() ? $actor->id : null),
            ])->save();
            DisputeMessage::query()->create(['dispute_id' => $dispute->id, 'author_id' => $actor?->id, 'author_role' => 'system', 'body' => $summary]);

            $order = Order::query()->findOrFail($dispute->order_id);
            if ($amountToBuyer > 0) {
                $this->refunds->refundOrder($order, $amountToBuyer, 'dispute_'.$dispute->reason, "Dispute {$dispute->number}: {$summary}", $actor,
                    finalise: $status === DisputeStatus::ResolvedBuyer);
            }
            if ($status !== DisputeStatus::ResolvedBuyer) {
                $this->orders->completeAfterDispute($order->fresh(), $actor, $status === DisputeStatus::ResolvedSplit);
            }

            $this->audit->log('DISPUTE.RESOLVED', $dispute, "{$status->value}: ".$dispute->money($amountToBuyer)->format().' to buyer', 'warning', actor: $actor,
                metadata: ['decision' => $decision, 'policy_reference' => $policy]);
        });

        $dispute->refresh();
        foreach ([$dispute->buyer, $dispute->seller] as $party) {
            $this->notifications->notify($party, 'dispute.resolved', $this->data($dispute) + ['summary' => $summary], "dispute.resolved:{$dispute->id}:{$party->id}");
        }

        return $dispute;
    }

    /** Scheduler: seller missed the 48 h response window → mediator review. */
    public function escalateOverdue(Dispute $dispute): void
    {
        DB::transaction(function () use ($dispute) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($dispute->status === DisputeStatus::AwaitingSeller && $dispute->respond_by?->isPast()) {
                $dispute->forceFill(['status' => DisputeStatus::UnderReview])->save();
                DisputeMessage::query()->create(['dispute_id' => $dispute->id, 'author_role' => 'system',
                    'body' => 'The seller did not respond within 48 hours. A mediator will review the case.']);
            }
        });
    }

    public function data(Dispute $dispute): array
    {
        return [
            'dispute_id' => $dispute->id,
            'number' => $dispute->number,
            'order_number' => $dispute->order->number,
            'reason' => Dispute::REASONS[$dispute->reason] ?? $dispute->reason,
            'summary' => '',
        ];
    }
}
