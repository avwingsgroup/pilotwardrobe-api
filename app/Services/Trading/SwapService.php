<?php

declare(strict_types=1);

namespace App\Services\Trading;

use App\Enums\ListingStatus;
use App\Enums\SwapStatus;
use App\Exceptions\ApiException;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SwapEvent;
use App\Models\SwapRequest;
use App\Models\SwapRequestItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\ListingService;
use App\Services\Messaging\ConversationService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Pricing\CommissionService;
use App\Support\Ids\ReferenceGenerator;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Item-for-item trades (V8, handoff §8.3). Accepting reserves every listing involved and
 * creates a linked pair of `swap_leg` orders in `pending_payment`: each side pays the
 * swap fee, freight for the item coming to them and (if it is the payer) the cash top-up.
 * Both legs run through escrow like any order.
 */
final class SwapService
{
    public function __construct(
        private readonly CommissionService $commission,
        private readonly ListingService $listings,
        private readonly ConversationService $conversations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $offeredIds */
    public function propose(User $proposer, Listing $target, array $offeredIds, int $cashAdjustment, string $currency, ?string $message): SwapRequest
    {
        if (! $proposer->isKycApproved()) {
            throw ApiException::kycRequired('Verify your identity to propose swaps.');
        }
        if ($target->seller_id === $proposer->id) {
            throw ApiException::conflict("You can't propose a swap for your own listing.");
        }
        if ($target->status !== ListingStatus::Active || ! $target->swappable) {
            throw ApiException::conflict('This listing is not open to swaps.');
        }
        if ($offeredIds === []) {
            throw ApiException::validation(['offered_listing_ids' => 'Choose at least one of your items to offer.']);
        }
        if ($currency !== $target->currency) {
            throw ApiException::validation(['cash_adjustment.currency' => "Use {$target->currency} for the cash adjustment."]);
        }

        $offered = Listing::query()->whereIn('id', $offeredIds)->get();
        foreach ($offeredIds as $i => $id) {
            $listing = $offered->firstWhere('id', $id);
            if ($listing === null || $listing->seller_id !== $proposer->id || $listing->status !== ListingStatus::Active) {
                throw ApiException::validation(["offered_listing_ids.{$i}" => 'You can only offer your own live listings.']);
            }
        }

        $value = Money::of((int) $offered->sum('price_amount'), $currency)->plus(Money::of(max(0, $cashAdjustment), $currency));
        $fee = $this->commission->swapFee($value)['fee'];

        $swap = DB::transaction(function () use ($proposer, $target, $offered, $cashAdjustment, $currency, $message, $fee) {
            $swap = SwapRequest::query()->create([
                'target_listing_id' => $target->id,
                'proposer_id' => $proposer->id,
                'counterparty_id' => $target->seller_id,
                'cash_adjustment_amount' => $cashAdjustment,
                'currency' => $currency,
                'swap_fee_amount' => $fee->amount,
                'message' => $message ? mb_substr(trim($message), 0, 500) : null,
                'status' => SwapStatus::Pending,
                'expires_at' => now()->addHours((int) config('platform.swaps.expiry_hours')),
            ]);
            foreach ($offered as $listing) {
                SwapRequestItem::query()->create(['swap_request_id' => $swap->id, 'listing_id' => $listing->id, 'estimated_value' => (int) $listing->price_amount]);
            }
            $summary = $this->summary($swap->fresh(['items.listing']));
            $this->event($swap, 'offer', 'Swap proposed · '.$summary, $proposer->shortName(), $proposer);
            $this->conversations->systemMessage($target, $proposer, 'swap', 'Swap: '.$summary, ['swap_request_id' => $swap->id]);

            return $swap;
        });

        $this->notifications->notify($target->seller, 'swap.proposed', [
            'swap_id' => $swap->id, 'title' => $target->title, 'counterparty' => $proposer->shortName(), 'summary' => $this->summary($swap),
        ], "swap.proposed:{$swap->id}");

        return $swap;
    }

    public function accept(SwapRequest $swap, User $actor): SwapRequest
    {
        $swap = DB::transaction(function () use ($swap, $actor) {
            $swap = $this->lockPending($swap, $actor, 'counterparty');

            $target = Listing::query()->lockForUpdate()->findOrFail($swap->target_listing_id);
            $offered = Listing::query()->lockForUpdate()->whereIn('id', $swap->items()->pluck('listing_id'))->get();
            foreach ([$target, ...$offered] as $listing) {
                if ($listing->status !== ListingStatus::Active || $listing->availableQuantity() < 1) {
                    throw ApiException::invalidTransition('One of the items in this swap is no longer available.');
                }
            }

            $proposer = $swap->proposer;
            $counterparty = $swap->counterparty;
            $hours = (int) config('platform.swaps.expiry_hours');
            $this->listings->reserveFor($target, $counterparty, $proposer, $hours, 'swap');
            foreach ($offered as $listing) {
                $this->listings->reserveFor($listing, $proposer, $counterparty, $hours, 'swap');
            }

            // Leg A: proposer receives the target item. Leg B: counterparty receives the offered items.
            $cash = $swap->cash_adjustment_amount;
            $this->createLeg($swap, $proposer, $counterparty, [$target], max(0, $cash));
            $this->createLeg($swap, $counterparty, $proposer, $offered->all(), max(0, -$cash));

            $swap->forceFill(['status' => SwapStatus::Accepted, 'responded_at' => now()])->save();
            $this->event($swap, 'accepted', 'Swap accepted', 'Both sides pay their leg to start shipping', $actor);
            $this->audit->log('SWAP.ACCEPTED', $swap, $this->summary($swap), actor: $actor);

            return $swap;
        });

        foreach ([$swap->proposer, $swap->counterparty] as $party) {
            $this->notifications->notify($party, 'swap.accepted', ['swap_id' => $swap->id, 'title' => $swap->targetListing->title], "swap.accepted:{$swap->id}:{$party->id}");
        }

        return $swap;
    }

    public function reject(SwapRequest $swap, User $actor): SwapRequest
    {
        $swap = DB::transaction(function () use ($swap, $actor) {
            $swap = $this->lockPending($swap, $actor, 'counterparty');
            $swap->forceFill(['status' => SwapStatus::Rejected, 'responded_at' => now()])->save();
            $this->event($swap, 'rejected', 'Swap declined', null, $actor);

            return $swap;
        });

        $this->notifications->notify($swap->proposer, 'swap.rejected', ['swap_id' => $swap->id, 'title' => $swap->targetListing->title], "swap.rejected:{$swap->id}");

        return $swap;
    }

    public function cancel(SwapRequest $swap, User $actor): SwapRequest
    {
        return DB::transaction(function () use ($swap, $actor) {
            $swap = $this->lockPending($swap, $actor, 'proposer');
            $swap->forceFill(['status' => SwapStatus::Cancelled, 'responded_at' => now()])->save();
            $this->event($swap, 'cancelled', 'Swap withdrawn', null, $actor);

            return $swap;
        });
    }

    public function expire(SwapRequest $swap): void
    {
        $expired = DB::transaction(function () use ($swap) {
            $swap = SwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
            if ($swap->status !== SwapStatus::Pending || $swap->expires_at->isFuture()) {
                return null;
            }
            $swap->forceFill(['status' => SwapStatus::Expired])->save();
            $this->event($swap, 'expired', 'Swap expired');

            return $swap;
        });

        if ($expired) {
            foreach ([$expired->proposer, $expired->counterparty] as $party) {
                $this->notifications->notify($party, 'swap.expired', ['swap_id' => $expired->id, 'title' => $expired->targetListing->title], "swap.expired:{$expired->id}:{$party->id}");
            }
        }
    }

    /** Called when a leg order completes: the swap completes when both legs have. */
    public function legCompleted(Order $order): void
    {
        if (! $order->swap_request_id) {
            return;
        }
        DB::transaction(function () use ($order) {
            $swap = SwapRequest::query()->lockForUpdate()->find($order->swap_request_id);
            if ($swap && $swap->status === SwapStatus::Accepted
                && $swap->orders()->where('status', '!=', 'completed')->doesntExist()) {
                $swap->forceFill(['status' => SwapStatus::Completed])->save();
                $this->event($swap, 'completed', 'Swap completed');
            }
        });
    }

    public static function allowedActions(SwapRequest $swap, User $viewer): array
    {
        $actions = [];
        if ($swap->status === SwapStatus::Pending) {
            $actions = $viewer->id === $swap->counterparty_id ? ['accept', 'reject'] : ['cancel'];
        }
        if ($swap->status === SwapStatus::Accepted) {
            $actions[] = 'pay';
        }
        $actions[] = 'message';

        return $actions;
    }

    public function summary(SwapRequest $swap): string
    {
        $titles = $swap->items->map(fn ($i) => $i->listing?->title)->filter()->implode(' + ');
        $cash = $swap->cash_adjustment_amount;

        return $titles.($cash > 0 ? ' + '.Money::of($cash, $swap->currency)->format() : ($cash < 0 ? ' − '.Money::of(-$cash, $swap->currency)->format() : ''));
    }

    /** @param list<Listing> $receives */
    private function createLeg(SwapRequest $swap, User $buyer, User $seller, array $receives, int $cash): Order
    {
        $first = $receives[0];
        $shipping = $first->shipping[0] ?? null;
        $freight = (int) ($shipping['price_amount'] ?? 0);
        $fee = $swap->swap_fee_amount;
        $sellerFreight = ($shipping['method'] ?? null) === 'self' ? $freight : 0;

        $order = Order::query()->create([
            'number' => ReferenceGenerator::unique(Order::class, 'number', fn () => ReferenceGenerator::orderNumber()),
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'kind' => 'swap_leg',
            'swap_request_id' => $swap->id,
            'currency' => $swap->currency,
            'items_amount' => 0,
            'freight_amount' => $freight,
            'swap_fee_amount' => $fee,
            'cash_adjustment_amount' => $cash,
            'total_amount' => $freight + $fee + $cash,
            'platform_fee_bps' => 0,
            'platform_fee_amount' => $fee,
            'seller_net_amount' => $cash + $sellerFreight,
            'shipping_address' => $buyer->addresses()->where('is_default', true)->first()?->snapshot(),
            'shipping_option' => $shipping,
            // Swap legs wait for both payments; give them the swap expiry rather than 15 minutes.
            'reserved_until' => now()->addHours((int) config('platform.swaps.expiry_hours')),
        ]);

        foreach ($receives as $listing) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'listing_id' => $listing->id,
                'title' => $listing->title,
                'listing_slug' => $listing->slug,
                'image_media_id' => $listing->images()->value('id'),
                'condition' => $listing->condition?->value,
                'format' => $listing->format->value,
                'quantity' => 1,
                'unit_price' => 0,
                'total' => 0,
            ]);
            $listing->increment('reserved_quantity');
        }

        app(OrderService::class)->event($order, 'placed', 'Swap leg created', 'Pay freight, fee and any cash top-up', $buyer);

        return $order;
    }

    private function lockPending(SwapRequest $swap, User $actor, string $role): SwapRequest
    {
        $swap = SwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
        if (! $swap->isParty($actor)) {
            throw ApiException::notFound('Swap not found.');
        }
        $expected = $role === 'counterparty' ? $swap->counterparty_id : $swap->proposer_id;
        if ($actor->id !== $expected) {
            throw ApiException::forbidden($role === 'counterparty' ? 'Only the receiving seller can respond to this swap.' : 'Only the proposer can withdraw this swap.');
        }
        if ($swap->status !== SwapStatus::Pending || $swap->expires_at->isPast()) {
            throw ApiException::invalidTransition('This swap is no longer pending.');
        }

        return $swap;
    }

    private function event(SwapRequest $swap, string $kind, string $label, ?string $detail = null, ?User $actor = null): void
    {
        SwapEvent::query()->create([
            'swap_request_id' => $swap->id,
            'kind' => $kind,
            'label' => mb_substr($label, 0, 160),
            'detail' => $detail,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }
}
