<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.5. */
enum PaymentStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Processing = 'processing';
    case Successful = 'successful';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public static function transitions(): array
    {
        return [
            'pending' => [self::Processing, self::Successful, self::Failed, self::Cancelled, self::Expired],
            'processing' => [self::Successful, self::Failed, self::Cancelled, self::Expired],
            'successful' => [self::Refunded, self::PartiallyRefunded],
            'partially_refunded' => [self::Refunded, self::PartiallyRefunded],
            // A late webhook can still confirm a payment that was locally expired; the
            // order handler decides whether to fulfil or auto-refund it.
            'expired' => [self::Successful],
            'failed' => [],
            'cancelled' => [],
            'refunded' => [],
        ];
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
