<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DisputeStatus;
use App\Enums\ListingFormat;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\CheckoutQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Order as seen by its buyer or seller (VB6/VB7/VB9). `allowed_actions` is computed for
 * the viewer so the SPA never re-derives business rules.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** Tracker steps shown to the user, in order. */
    private const STEPS = [
        'payment_secured' => 'Payment secured in escrow',
        'dispatched' => 'Seller dispatched · wheels up',
        'out_for_delivery' => 'On final approach',
        'delivered' => 'Landed',
        'completed' => 'Escrow released',
    ];

    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $viewer = $request->user();
        $role = $order->isBuyer($viewer) ? 'buyer' : ($order->isSeller($viewer) ? 'seller' : 'staff');
        $payment = $order->successfulPayment ?? $order->latestPayment;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'kind' => $order->kind,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'escrow_status' => $order->escrow_status->value,
            'role' => $role,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'listing_id' => $item->listing_id,
                'listing_slug' => $item->listing_slug,
                'title' => $item->title,
                'image' => MediaAssetPresenter::make($item->image, $item->title),
                'condition' => $item->condition,
                'format' => $item->format,
                'size' => $item->size,
                'size_from_fit_kit' => (bool) $item->size_from_fit_kit,
                'quantity' => $item->quantity,
                'unit_price' => $order->money($item->unit_price)->toArray(),
                'total' => $order->money($item->total)->toArray(),
            ])->values()->all(),
            'totals' => [
                'items' => $order->money($order->items_amount)->toArray(),
                'freight' => $order->money($order->freight_amount)->toArray(),
                'buyer_protection' => $order->money($order->buyer_protection_amount)->toArray(),
                'duties' => $order->duties_amount !== null ? $order->money($order->duties_amount)->toArray() : null,
                'discount' => $order->discount_amount > 0 ? $order->money($order->discount_amount)->toArray() : null,
                'swap_fee' => $order->swap_fee_amount > 0 ? $order->money($order->swap_fee_amount)->toArray() : null,
                'cash_adjustment' => $order->cash_adjustment_amount > 0 ? $order->money($order->cash_adjustment_amount)->toArray() : null,
                'total' => $order->total()->toArray(),
                'refunded' => $order->refunded_amount > 0 ? $order->money($order->refunded_amount)->toArray() : null,
            ],
            'seller_payout' => $role === 'seller' ? [
                'platform_fee' => $order->money($order->platform_fee_amount)->toArray(),
                'you_receive' => $order->money($order->seller_net_amount)->toArray(),
                'available_at' => $order->funds_available_at?->toIso8601ZuluString(),
            ] : null,
            'seller' => SellerSummaryResource::fields($order->seller),
            'buyer' => [
                'id' => $order->buyer->id,
                'display_name' => $role === 'buyer' ? $order->buyer->displayName() : $order->buyer->shortName(),
                'initials' => $order->buyer->initials(),
            ],
            'shipping_address' => $order->shipping_address,
            'shipping' => $order->shipping_option ? CheckoutQuote::shippingOption($order->shipping_option, $order->currency) : null,
            'carrier' => $order->carrier ? [
                'name' => $order->carrier,
                'tracking_number' => $order->tracking_number,
                'tracking_url' => $order->tracking_url,
            ] : null,
            'route' => $order->route,
            'estimated_delivery' => $order->estimated_delivery_from ? [
                'from' => $order->estimated_delivery_from->toIso8601ZuluString(),
                'to' => $order->estimated_delivery_to?->toIso8601ZuluString(),
            ] : null,
            'tracking' => $this->tracking($order),
            'payment' => $payment ? [
                'gateway' => $payment->gateway,
                'status' => $payment->status->value,
                'reference' => $payment->reference,
                'method_label' => $payment->method_label,
            ] : null,
            'deadlines' => [
                'pay_by' => $order->status === OrderStatus::PendingPayment ? $order->reserved_until?->toIso8601ZuluString() : null,
                'dispatch_by' => $order->dispatch_due_at?->toIso8601ZuluString(),
                'auto_release_at' => $order->auto_release_at?->toIso8601ZuluString(),
                'dispute_window_ends_at' => $order->dispute_window_ends_at?->toIso8601ZuluString(),
            ],
            'dispute_id' => $order->disputes->sortByDesc('created_at')->first()?->id,
            'swap_request_id' => $order->swap_request_id,
            'allowed_actions' => self::allowedActions($order, $viewer),
            'placed_at' => $order->created_at?->toIso8601ZuluString(),
            'updated_at' => $order->updated_at?->toIso8601ZuluString(),
        ];
    }

    public static function allowedActions(Order $order, User $viewer): array
    {
        $status = $order->status;
        $actions = [];

        if ($order->isBuyer($viewer)) {
            $inWindow = $order->dispute_window_ends_at === null || $order->dispute_window_ends_at->isFuture();
            $hasActiveDispute = $order->disputes->contains(fn ($d) => in_array($d->status, DisputeStatus::active(), true));
            $actions = match ($status) {
                OrderStatus::PendingPayment => ['pay', 'cancel'],
                OrderStatus::Paid, OrderStatus::AwaitingDispatch => ['cancel', 'message'],
                OrderStatus::InTransit, OrderStatus::OutForDelivery => ['confirm_delivery', 'message'],
                OrderStatus::Delivered => array_values(array_filter(['confirm_delivery', $hasActiveDispute ? null : 'open_dispute', 'message'])),
                OrderStatus::Completed => array_values(array_filter([
                    $order->reviews->contains('author_id', $viewer->id) ? null : 'review',
                    $inWindow && ! $hasActiveDispute ? 'open_dispute' : null,
                    'message',
                ])),
                OrderStatus::Disputed => ['view_dispute', 'message'],
                default => ['message'],
            };
            $hasDigital = $order->items->contains(fn (OrderItem $i) => $i->format !== ListingFormat::Physical->value);
            if ($hasDigital && in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
                $actions[] = 'download';
            }
        } elseif ($order->isSeller($viewer)) {
            $actions = match ($status) {
                OrderStatus::Paid, OrderStatus::AwaitingDispatch => array_values(array_filter([
                    'mark_dispatched',
                    in_array($order->shipping_option['method'] ?? null, ['standard', 'express'], true) ? 'print_label' : null,
                    'cancel', 'message',
                ])),
                OrderStatus::InTransit, OrderStatus::OutForDelivery => ['update_tracking', 'message'],
                OrderStatus::Completed => array_values(array_filter([$order->reviews->contains('author_id', $viewer->id) ? null : 'review', 'message'])),
                OrderStatus::Disputed => ['view_dispute', 'message'],
                default => ['message'],
            };
        }

        return $actions;
    }

    private function tracking(Order $order): array
    {
        $events = $order->events->keyBy('code');
        $steps = [];
        $currentFound = false;

        foreach (self::STEPS as $code => $label) {
            /** @var OrderEvent|null $event */
            $event = $events->get($code) ?? ($code === 'dispatched' ? null : null);
            if ($code === 'out_for_delivery' && $event === null && $events->has('delivered')) {
                continue; // skipped straight to landed
            }
            $state = $event ? 'done' : ($currentFound ? 'upcoming' : 'current');
            if (! $event) {
                $currentFound = true;
            }
            $steps[] = [
                'id' => $event->id ?? 'step_'.$code,
                'code' => $code,
                'label' => $event->label ?? $label,
                'detail' => $event?->detail,
                'occurred_at' => $event?->occurred_at?->toIso8601ZuluString(),
                'state' => $state,
            ];
        }

        // Terminal off-path events (cancelled, refunded, disputed, on hold) are appended.
        foreach ($order->events as $event) {
            if (in_array($event->code, ['cancelled', 'refunded', 'disputed', 'on_hold', 'released'], true)) {
                $steps[] = ['id' => $event->id, 'code' => $event->code, 'label' => $event->label, 'detail' => $event->detail,
                    'occurred_at' => $event->occurred_at->toIso8601ZuluString(), 'state' => 'done'];
            }
        }

        return $steps;
    }
}
