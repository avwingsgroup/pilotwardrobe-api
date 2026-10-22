<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dispute case (VB15) for a party, or the AD9 mediation view for staff (`forStaff()`),
 * which adds internal notes and both parties' details. Evidence uses short-lived signed URLs.
 *
 * @mixin Dispute
 */
final class DisputeResource extends JsonResource
{
    private bool $staff = false;

    public function forStaff(): self
    {
        $this->staff = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Dispute $dispute */
        $dispute = $this->resource;
        $viewer = $request->user();
        $role = $this->staff ? 'staff' : ($viewer->id === $dispute->buyer_id ? 'buyer' : 'seller');
        $order = $dispute->order;
        $item = $order->items->first();
        $proposal = $dispute->latestProposal;

        $messages = $dispute->messages
            ->filter(fn (DisputeMessage $m) => $this->staff || ! $m->is_internal)
            ->map(fn (DisputeMessage $m) => [
                'id' => $m->id,
                'author' => [
                    'display_name' => $m->author_role === 'system' ? 'Pilotwardrobe' : ($m->author_id === $viewer->id ? 'You' : ($m->author?->shortName() ?? 'Mediator')),
                    'initials' => $m->author?->initials() ?? 'PW',
                    'role' => $m->author_role,
                ],
                'body' => $m->body,
                'internal' => $this->staff ? $m->is_internal : null,
                'evidence' => MediaAssetPresenter::many($m->evidence),
                'created_at' => $m->created_at?->toIso8601ZuluString(),
            ])->values()->all();

        $out = [
            'id' => $dispute->id,
            'number' => $dispute->number,
            'order_id' => $dispute->order_id,
            'order_number' => $order->number,
            'reason' => $dispute->reason,
            'reason_label' => Dispute::REASONS[$dispute->reason] ?? $dispute->reason,
            'status' => $dispute->status->value,
            'requested' => $dispute->requested,
            'requested_amount' => $dispute->money($dispute->requested_amount)?->toArray(),
            'escrow_frozen' => $dispute->money($dispute->frozen_amount)?->toArray(),
            'mediator' => $dispute->mediator ? ['display_name' => $dispute->mediator->shortName(), 'initials' => $dispute->mediator->initials()] : null,
            'opened_at' => $dispute->created_at?->toIso8601ZuluString(),
            'respond_by' => $dispute->respond_by?->toIso8601ZuluString(),
            'decision_due_at' => $dispute->decision_due_at?->toIso8601ZuluString(),
            'resolved_at' => $dispute->resolved_at?->toIso8601ZuluString(),
            'decision' => $dispute->decision,
            'amount_to_buyer' => $dispute->money($dispute->amount_to_buyer)?->toArray(),
            'policy_reference' => $dispute->policy_reference,
            'description' => $dispute->description,
            'evidence' => MediaAssetPresenter::many($dispute->evidence),
            'messages' => $messages,
            'steps' => $this->steps($dispute),
            'seller_proposal' => $proposal ? [
                'id' => $proposal->id,
                'kind' => $proposal->kind,
                'summary' => $proposal->summary,
                'amount' => $dispute->money($proposal->amount)->toArray(),
                'status' => $proposal->status,
            ] : null,
            'viewer_role' => $role,
            'allowed_actions' => $this->allowedActions($dispute, $role),
            'item' => $item ? [
                'title' => $item->title.($item->size ? ' — size '.$item->size : ''),
                'image' => MediaAssetPresenter::make($item->image, $item->title),
                'price' => $order->money($item->total)->toArray(),
            ] : null,
            'counterparty_name' => $role === 'buyer' ? $dispute->seller->shortName() : $dispute->buyer->shortName(),
        ];

        if ($this->staff) {
            $out['buyer'] = ['id' => $dispute->buyer->id, 'display_name' => $dispute->buyer->displayName(), 'email' => $dispute->buyer->email];
            $out['seller'] = ['id' => $dispute->seller->id, 'display_name' => $dispute->seller->displayName(), 'email' => $dispute->seller->email];
            $out['order_total'] = $order->total()->toArray();
        }

        return $out;
    }

    private function steps(Dispute $dispute): array
    {
        $sellerReplied = $dispute->messages->contains(fn ($m) => $m->author_role === 'seller') || $dispute->proposals->isNotEmpty();
        $resolved = ! $dispute->status->isActive();

        return [
            ['label' => 'Dispute opened', 'detail' => 'escrow frozen', 'state' => 'done'],
            ['label' => 'Seller responded', 'detail' => $sellerReplied ? null : 'within 48h', 'state' => $sellerReplied ? 'done' : ($resolved ? 'skipped' : 'current')],
            ['label' => 'Mediator review', 'detail' => 'up to 5 business days', 'state' => $resolved ? 'done' : ($dispute->status === DisputeStatus::UnderReview ? 'current' : 'upcoming')],
            ['label' => 'Decision', 'detail' => $resolved ? str_replace('_', ' ', $dispute->status->value) : null, 'state' => $resolved ? 'done' : 'upcoming'],
        ];
    }

    private function allowedActions(Dispute $dispute, string $role): array
    {
        if (! $dispute->status->isActive()) {
            return [];
        }
        $pendingProposal = $dispute->latestProposal?->status === 'pending';

        return match ($role) {
            'buyer' => array_values(array_filter(['reply', 'add_evidence', 'withdraw', $pendingProposal ? 'accept_proposal' : null, $pendingProposal ? 'decline_proposal' : null])),
            'seller' => ['reply', 'add_evidence', 'propose_settlement'],
            default => ['message', 'decide'],
        };
    }
}
