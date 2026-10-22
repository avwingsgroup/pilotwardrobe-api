<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.4. `on_hold` remembers the previous state in `orders.status_before_hold`. */
enum OrderStatus: string
{
    use HasTransitions;

    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case AwaitingDispatch = 'awaiting_dispatch';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case OnHold = 'on_hold';

    public static function transitions(): array
    {
        $hold = self::OnHold;

        return [
            'pending_payment' => [self::Paid, self::AwaitingDispatch, self::Delivered, self::Cancelled, $hold],
            'paid' => [self::AwaitingDispatch, self::Cancelled, self::Refunded, $hold],
            'awaiting_dispatch' => [self::InTransit, self::Delivered, self::Cancelled, self::Refunded, $hold],
            'in_transit' => [self::OutForDelivery, self::Delivered, self::Refunded, $hold],
            'out_for_delivery' => [self::Delivered, self::Refunded, $hold],
            'delivered' => [self::Completed, self::Disputed, self::Refunded, $hold],
            'completed' => [self::Disputed, self::Refunded, self::PartiallyRefunded],
            'disputed' => [self::Completed, self::Refunded, self::PartiallyRefunded],
            'on_hold' => [self::PendingPayment, self::Paid, self::AwaitingDispatch, self::InTransit, self::OutForDelivery, self::Delivered, self::Completed, self::Refunded, self::Cancelled],
            'cancelled' => [],
            'refunded' => [],
            'partially_refunded' => [],
        ];
    }

    /** Orders that still need someone to act (counters, account-deletion checks). */
    public static function open(): array
    {
        return [self::PendingPayment, self::Paid, self::AwaitingDispatch, self::InTransit, self::OutForDelivery, self::Delivered, self::Disputed, self::OnHold];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::AwaitingDispatch => 'Awaiting wheels-up',
            self::InTransit => 'Airborne',
            self::OutForDelivery => 'On approach',
            self::Delivered => 'Landed',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed, self::Delivered => 'success',
            self::InTransit, self::OutForDelivery, self::Paid => 'info',
            self::PendingPayment, self::AwaitingDispatch, self::OnHold => 'warning',
            self::Disputed, self::Cancelled, self::Refunded, self::PartiallyRefunded => 'danger',
        };
    }
}
