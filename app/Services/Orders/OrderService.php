<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\EscrowStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\ListingService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\PlatformSettings;
use App\Services\Trading\SwapService;
use App\Services\Wallet\WalletService;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order lifecycle (handoff §8.4) and escrow (§8.6). All state changes lock the order row
 * and validate the transition through OrderStatus/EscrowStatus; illegal moves are 409.
 */
final class OrderService
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly WalletService $wallets,
        private readonly ListingService $listings,
        private readonly NotificationService $notifications,
        private readonly PlatformSettings $settings,
        private readonly RiskAssessor $risk,
        private readonly AuditLogger $audit,
    ) {}

    /** `POST /orders` — re-quotes server-side, reserves stock for 15 minutes. */
    public function create(User $buyer, array $input): Order
    {
        if (! $buyer->canTrade()) {
            throw ApiException::forbidden('Buying is restricted on your account. Contact support.');
        }

        return DB::transaction(function () use ($buyer, $input) {
            $quote = $this->checkout->quote($buyer, $input, lock: true);
            if ($quote->selectedShipping !== null && $quote->address === null) {
                throw ApiException::validation(['address_id' => 'Choose a delivery address.']);
            }

            $risk = $this->risk->assess($buyer, $quote->total);

            $order = new Order([
                'number' => ReferenceGenerator::unique(Order::class, 'number', fn () => ReferenceGenerator::orderNumber()),
                'buyer_id' => $buyer->id,
                'seller_id' => $quote->seller->id,
                'offer_id' => $quote->offer?->id,
                'promo_code_id' => $quote->promo?->id,
                'currency' => $quote->total->currency->value,
                'items_amount' => $quote->items->amount,
                'freight_amount' => $quote->freight->amount,
                'buyer_protection_amount' => $quote->buyerProtection->amount,
                'duties_amount' => $quote->duties?->amount,
                'discount_amount' => $quote->discount->amount,
                'total_amount' => $quote->total->amount,
                'platform_fee_bps' => $quote->platformFeeBps,
                'platform_fee_amount' => $quote->platformFee->amount,
                'seller_net_amount' => $quote->sellerNet->amount,
                'shipping_address' => $quote->address?->snapshot(),
                'shipping_option' => $quote->selectedShipping,
                'risk' => $risk['level'],
                'risk_signals' => $risk['signals'] ?: null,
                'reserved_until' => now()->addMinutes((int) config('platform.orders.reservation_minutes')),
                'route' => $this->route($quote),
            ]);
            $order->save();

            foreach ($quote->lines as $line) {
                /** @var Listing $listing */
                $listing = $line['listing'];
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'listing_id' => $listing->id,
                    'title' => $listing->title,
                    'listing_slug' => $listing->slug,
                    'image_media_id' => $listing->images->first()?->id,
                    'condition' => $listing->condition?->value,
                    'format' => $listing->format->value,
                    'size' => $line['size'],
                    'size_from_fit_kit' => $line['size_from_fit_kit'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price']->amount,
                    'total' => $line['unit_price']->amount * $line['quantity'],
                ]);
                $listing->increment('reserved_quantity', $line['quantity']);
            }

            $this->event($order, 'placed', 'Order placed', 'Awaiting payment', $buyer);
            $this->audit->log('ORDER.CREATED', $order, $quote->total->format(), actor: $buyer);

            return $order;
        });
    }

    /**
     * Applies a verified successful payment. Returns false when the order can no longer
     * accept it (already paid by another attempt, cancelled or expired) — the caller must
     * refund that payment.
     */
    public function markPaid(Order $order, Payment $payment): bool
    {
        $this->assertInTransaction();
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($order->status !== OrderStatus::PendingPayment) {
            // Already paid by another attempt, or cancelled/expired: this money must go back.
            return false;
        }

        $order->status = OrderStatus::AwaitingDispatch;
        $order->escrow_status = EscrowStatus::Held;
        $order->paid_at = now();
        $order->reserved_until = null;
        if ($order->requiresShipping()) {
            $order->dispatch_due_at = now()->addDays($this->settings->dispatchSlaDays());
        }
        $order->save();

        foreach ($order->items()->get() as $item) {
            $listing = Listing::query()->lockForUpdate()->find($item->listing_id);
            if ($listing === null) {
                continue;
            }
            $listing->stock = max(0, $listing->stock - $item->quantity);
            $listing->reserved_quantity = max(0, $listing->reserved_quantity - $item->quantity);
            $listing->sold_count += $item->quantity;
            if ($listing->stock === 0 && $listing->status !== ListingStatus::Sold) {
                $target = $order->kind === 'swap_leg' ? ListingStatus::Swapped : ListingStatus::Sold;
                if ($listing->status->canTransitionTo($target)) {
                    $listing->status = $target;
                }
                $listing->reserved_for_user_id = null;
                $listing->reserved_until = null;
                $listing->reserved_reason = null;
            }
            $listing->save();
        }

        if ($order->offer_id) {
            $order->offer?->forceFill(['status' => OfferStatus::Completed])->save();
        }
        if ($order->promo_code_id) {
            PromoCode::query()->whereKey($order->promo_code_id)->increment('times_used');
        }
        CartItem::query()->where('user_id', $order->buyer_id)->whereIn('listing_id', $order->items()->pluck('listing_id'))->delete();
        $order->seller()->increment('sales_count');

        $this->wallets->holdInEscrow($order);
        $this->event($order, 'payment_secured', 'Payment secured in escrow', $order->total()->format());
        if (! $order->requiresShipping()) {
            // Digital goods: delivered on payment; the dispute window starts now.
            $this->markDeliveredInternal($order, null, 'Download available');
        }
        $this->audit->log('ORDER.PAID', $order, "{$payment->gateway} {$payment->reference}", 'success', actor: null);

        DB::afterCommit(function () use ($order) {
            $order->refresh();
            $data = $this->notificationData($order);
            $this->notifications->notify($order->buyer, 'order.paid', $data, "order.paid:{$order->id}");
            $this->notifications->notify($order->seller, 'order.new_sale', $data, "order.new_sale:{$order->id}");
        });

        return true;
    }

    /** Seller adds carrier + tracking (VB7 "mark dispatched"). */
    public function dispatch(Order $order, User $seller, array $data): Order
    {
        return DB::transaction(function () use ($order, $seller, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->isSeller($seller)) {
                throw ApiException::notFound('Order not found.');
            }
            $order->status->assertCanTransitionTo(OrderStatus::InTransit, 'This order cannot be dispatched now.');
            if ($order->status !== OrderStatus::AwaitingDispatch) {
                throw ApiException::invalidTransition('This order cannot be dispatched now.');
            }

            $days = $order->shipping_option['estimated_days'] ?? ['min' => 3, 'max' => 10];
            $order->forceFill([
                'status' => OrderStatus::InTransit,
                'carrier' => $data['carrier'],
                'tracking_number' => $data['tracking_number'],
                'tracking_url' => $data['tracking_url'] ?? null,
                'dispatched_at' => now(),
                'estimated_delivery_from' => now()->addDays((int) ($days['min'] ?? 3)),
                'estimated_delivery_to' => now()->addDays((int) ($days['max'] ?? 10)),
            ])->save();

            $this->event($order, 'dispatched', 'Seller dispatched · wheels up', $data['carrier'].' · '.$data['tracking_number'], $seller);
            $this->audit->log('ORDER.DISPATCHED', $order, $data['carrier'], actor: $seller);

            DB::afterCommit(fn () => $this->notifications->notify($order->buyer, 'order.dispatched', $this->notificationData($order), "order.dispatched:{$order->id}"));

            return $order;
        });
    }

    /** Seller/carrier tracking updates (addition: `POST /orders/{id}/tracking`). */
    public function updateTracking(Order $order, User $actor, string $status, ?string $detail): Order
    {
        return DB::transaction(function () use ($order, $actor, $status, $detail) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $target = $status === 'delivered' ? OrderStatus::Delivered : OrderStatus::OutForDelivery;
            if (! in_array($order->status, [OrderStatus::InTransit, OrderStatus::OutForDelivery], true) || ! $order->status->canTransitionTo($target)) {
                throw ApiException::invalidTransition('Tracking can only be updated while the order is in transit.');
            }

            if ($target === OrderStatus::Delivered) {
                $this->markDeliveredInternal($order, $actor, $detail);
            } else {
                $order->status = OrderStatus::OutForDelivery;
                $order->save();
                $this->event($order, 'out_for_delivery', 'On final approach', $detail, $actor);
            }

            return $order;
        });
    }

    /** Buyer confirms the item landed as described (VB8); escrow moves toward release. */
    public function confirmDelivery(Order $order, ?User $buyer): Order
    {
        return DB::transaction(function () use ($order, $buyer) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($buyer !== null && ! $order->isBuyer($buyer)) {
                throw ApiException::notFound('Order not found.');
            }
            if (! in_array($order->status, [OrderStatus::InTransit, OrderStatus::OutForDelivery, OrderStatus::Delivered], true)) {
                throw ApiException::invalidTransition('This order cannot be confirmed yet.');
            }
            if ($order->escrow_status !== EscrowStatus::Held) {
                throw ApiException::invalidTransition('Escrow for this order is on hold. Our team will be in touch.');
            }

            if ($order->status !== OrderStatus::Delivered) {
                $this->markDeliveredInternal($order, $buyer, 'Confirmed by buyer');
            }

            $order->status = OrderStatus::Completed;
            $order->completed_at = now();
            $order->escrow_status = EscrowStatus::Releasing;
            $order->funds_available_at = $this->fundsAvailableAt($order);
            $order->save();

            $this->wallets->releaseToPending($order);
            $order->escrow_status = EscrowStatus::Released;
            $order->save();

            $this->event($order, 'completed', $buyer ? 'Buyer confirmed goods landed' : 'Escrow auto-released', 'Escrow released to seller', $buyer);
            $this->audit->log('ORDER.COMPLETED', $order, $buyer ? 'Confirmed by buyer' : 'Auto-release', 'success', actor: $buyer);

            DB::afterCommit(function () use ($order) {
                $this->notifications->notify($order->seller, 'order.completed', $this->notificationData($order), "order.completed:{$order->id}");
                app(SwapService::class)->legCompleted($order);
            });

            return $order;
        });
    }

    /**
     * Closes a disputed order in the seller's favour (fully or after a partial refund):
     * remaining proceeds move from frozen escrow toward release; the dispute window is over.
     */
    public function completeAfterDispute(Order $order, ?User $actor, bool $partial): Order
    {
        $this->assertInTransaction();
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);

        $target = $partial ? OrderStatus::PartiallyRefunded : OrderStatus::Completed;
        if ($order->status->canTransitionTo($target)) {
            $order->status = $target;
        }
        $order->completed_at ??= now();
        $order->dispute_window_ends_at = now();

        if (in_array($order->escrow_status, [EscrowStatus::Frozen, EscrowStatus::Held, EscrowStatus::OnHold], true)) {
            $order->escrow_status = EscrowStatus::Releasing;
            $order->save();
            $this->wallets->releaseToPending($order);
            $order->escrow_status = EscrowStatus::Released;
        }
        $order->funds_available_at = $this->fundsAvailableAt($order);
        $order->save();

        $this->event($order, 'completed', 'Dispute resolved', $partial ? 'Partial refund issued; remainder released' : 'Escrow released to seller', $actor);

        return $order;
    }

    /** Moves completed-order proceeds to `available` once the dispute window / new-seller hold has passed. */
    public function settleFunds(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $blocked = $order->funds_settled_at !== null
                || $order->funds_available_at === null
                || $order->funds_available_at->isFuture()
                || ! in_array($order->status, [OrderStatus::Completed, OrderStatus::PartiallyRefunded], true)
                || $order->disputes()->whereIn('status', ['open', 'awaiting_seller', 'under_review', 'appealed'])->exists();
            if ($blocked) {
                return false;
            }

            $this->wallets->makeAvailable($order);
            $order->funds_settled_at = now();
            $order->save();

            DB::afterCommit(fn () => $this->notifications->notify($order->seller, 'payout.available', [
                'amount' => $order->money($order->seller_net_amount)->format(),
                'number' => $order->number,
            ], "payout.available:{$order->id}"));

            return true;
        });
    }

    /** Cancel before dispatch (buyer or seller). Paid orders are refunded in full. */
    public function cancel(Order $order, User $actor, string $reason, bool $byStaff = false): Order
    {
        $order = DB::transaction(function () use ($order, $actor, $reason, $byStaff) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $byStaff && ! $order->isParty($actor)) {
                throw ApiException::notFound('Order not found.');
            }
            if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::Paid, OrderStatus::AwaitingDispatch], true)) {
                throw ApiException::invalidTransition('Orders can only be cancelled before dispatch.');
            }

            $wasPaid = $order->status !== OrderStatus::PendingPayment;
            $this->releaseStock($order, $wasPaid);

            $order->status = OrderStatus::Cancelled;
            $order->cancelled_at = now();
            $order->cancellation_reason = mb_substr($reason, 0, 255);
            $order->reserved_until = null;
            $order->save();

            Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                ->update(['status' => PaymentStatus::Cancelled->value, 'failure_reason' => 'Order cancelled']);

            $this->event($order, 'cancelled', 'Order cancelled', $reason, $actor);
            $this->audit->log('ORDER.CANCELLED', $order, $reason, 'warning', actor: $actor);

            return [$order, $wasPaid];
        });

        [$order, $wasPaid] = $order;
        if ($wasPaid) {
            app(RefundService::class)->refundOrder($order, $order->total_amount - $order->refunded_amount, 'order_cancelled', 'Cancelled before dispatch', $actor, approved: true);
        }

        $data = $this->notificationData($order) + ['reason' => $reason];
        $this->notifications->notify($order->buyer, 'order.cancelled', $data, "order.cancelled:{$order->id}:buyer");
        $this->notifications->notify($order->seller, 'order.cancelled', $data, "order.cancelled:{$order->id}:seller");

        return $order->fresh();
    }

    /** Scheduler: unpaid orders past their 15-minute reservation are cancelled and stock released. */
    public function expireUnpaid(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::PendingPayment || $order->reserved_until === null || $order->reserved_until->isFuture()) {
                return;
            }
            // A payment the gateway is still processing keeps the reservation alive.
            $processing = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)
                ->where('status', PaymentStatus::Processing->value)->exists();
            if ($processing) {
                return;
            }

            $this->releaseStock($order, false);
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Payment not completed in time', 'reserved_until' => null])->save();
            Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)
                ->where('status', PaymentStatus::Pending->value)->update(['status' => PaymentStatus::Expired->value]);
            $this->event($order, 'cancelled', 'Order expired', 'Payment not completed in time');
        });
    }

    /** Staff risk hold: freezes escrow and remembers the previous state. */
    public function hold(Order $order, User $staff, string $reason): Order
    {
        return DB::transaction(function () use ($order, $staff, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === OrderStatus::OnHold) {
                throw ApiException::invalidTransition('This order is already on hold.');
            }
            $order->status->assertCanTransitionTo(OrderStatus::OnHold, 'This order cannot be put on hold.');

            $before = ['status' => $order->status->value, 'escrow_status' => $order->escrow_status->value];
            $order->status_before_hold = $order->status;
            $order->status = OrderStatus::OnHold;
            if ($order->escrow_status === EscrowStatus::Held) {
                $order->escrow_status_before_hold = $order->escrow_status;
                $order->escrow_status = EscrowStatus::OnHold;
            }
            $order->hold_reason = mb_substr($reason, 0, 255);
            $order->save();

            $this->event($order, 'on_hold', 'Order on hold', 'Under review by our team', $staff);
            $this->audit->log('ORDER.HELD', $order, $reason, 'warning', $before, ['status' => 'on_hold'], actor: $staff);

            return $order;
        });
    }

    /** Staff release of a hold (back to the previous state) or a force-release of escrow. */
    public function releaseHold(Order $order, User $staff): Order
    {
        return DB::transaction(function () use ($order, $staff) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::OnHold) {
                $order->status = $order->status_before_hold ?? OrderStatus::AwaitingDispatch;
                $order->escrow_status = $order->escrow_status_before_hold ?? $order->escrow_status;
                if ($order->escrow_status === EscrowStatus::OnHold) {
                    $order->escrow_status = EscrowStatus::Held;
                }
                $order->status_before_hold = null;
                $order->escrow_status_before_hold = null;
                $order->hold_reason = null;
                $order->save();
                $this->event($order, 'released', 'Hold released', null, $staff);
                $this->audit->log('ORDER.HOLD_RELEASED', $order, null, 'info', actor: $staff);

                return $order;
            }

            // Force-release escrow to the seller (e.g. buyer unresponsive after delivery).
            if (! in_array($order->status, [OrderStatus::InTransit, OrderStatus::OutForDelivery, OrderStatus::Delivered], true)
                || $order->escrow_status !== EscrowStatus::Held) {
                throw ApiException::invalidTransition('Escrow can only be force-released for orders in transit or delivered.');
            }

            $this->audit->log('ESCROW.FORCE_RELEASED', $order, null, 'warning', actor: $staff);

            return $this->confirmDelivery($order, null);
        });
    }

    /** Scheduler: delivered orders past the auto-release window complete automatically. */
    public function autoRelease(Order $order): void
    {
        if ($order->status === OrderStatus::Delivered && $order->auto_release_at !== null && $order->auto_release_at->isPast()
            && $order->escrow_status === EscrowStatus::Held) {
            $this->confirmDelivery($order, null);
        }
    }

    public function event(Order $order, string $code, string $label, ?string $detail = null, ?User $actor = null): void
    {
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'code' => $code,
            'label' => $label,
            'detail' => $detail ? mb_substr($detail, 0, 255) : null,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    public function notificationData(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'number' => $order->number,
            'total' => $order->total()->format(),
            'net' => $order->money($order->seller_net_amount)->format(),
            'dispatch_due' => $order->dispatch_due_at?->format('D j M') ?? 'soon',
            'carrier' => (string) $order->carrier,
            'tracking_number' => (string) $order->tracking_number,
            'window' => $this->settings->autoReleaseHours().'h',
        ];
    }

    private function markDeliveredInternal(Order $order, ?User $actor, ?string $detail): void
    {
        $order->status = OrderStatus::Delivered;
        $order->delivered_at = now();
        $order->auto_release_at = now()->addHours($this->settings->autoReleaseHours());
        $order->dispute_window_ends_at = now()->addHours($this->settings->disputeWindowHours());
        $order->save();
        $this->event($order, 'delivered', 'Landed', $detail, $actor);

        DB::afterCommit(fn () => $this->notifications->notify($order->buyer, 'order.delivered', $this->notificationData($order), "order.delivered:{$order->id}"));
    }

    private function fundsAvailableAt(Order $order): Carbon
    {
        $at = $order->dispute_window_ends_at && $order->dispute_window_ends_at->isFuture() ? $order->dispute_window_ends_at->copy() : now();

        $seller = $order->seller;
        $isNewSeller = $seller->created_at->gt(now()->subDays(30))
            || Order::query()->where('seller_id', $seller->id)->whereNotNull('funds_settled_at')->doesntExist();
        if ($isNewSeller) {
            $hold = ($order->paid_at ?? now())->copy()->addDays($this->settings->newSellerPayoutHoldDays());
            $at = $hold->gt($at) ? $hold : $at;
        }

        return $at;
    }

    /** Returns reserved/sold units to the listings of a cancelled order. */
    private function releaseStock(Order $order, bool $wasPaid): void
    {
        foreach ($order->items()->get() as $item) {
            $listing = Listing::query()->lockForUpdate()->find($item->listing_id);
            if ($listing === null) {
                continue;
            }
            if ($wasPaid) {
                $listing->stock += $item->quantity;
                $listing->sold_count = max(0, $listing->sold_count - $item->quantity);
                if (in_array($listing->status, [ListingStatus::Sold, ListingStatus::Swapped], true)) {
                    $listing->status = ListingStatus::Active;
                }
            } else {
                $listing->reserved_quantity = max(0, $listing->reserved_quantity - $item->quantity);
            }
            $listing->save();
            if ($order->offer_id === null && $listing->status === ListingStatus::Reserved && $listing->reserved_reason === null) {
                $this->listings->releaseReservation($listing);
            }
        }
        if ($wasPaid) {
            $order->seller()->where('sales_count', '>', 0)->decrement('sales_count');
        }
    }

    private function route(CheckoutQuote $quote): ?array
    {
        $from = $quote->lines[0]['listing']->location['airport_code'] ?? null;

        return $from ? ['from' => $from, 'to' => null, 'progress' => 0] : null;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('markPaid must run inside a transaction.');
        }
    }
}
