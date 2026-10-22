<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.3. */
enum SwapStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Countered = 'countered';
    case Expired = 'expired';
    case Completed = 'completed';
    case Disputed = 'disputed';

    public static function transitions(): array
    {
        return [
            'pending' => [self::Accepted, self::Rejected, self::Cancelled, self::Countered, self::Expired],
            'accepted' => [self::Completed, self::Disputed, self::Cancelled],
            'completed' => [self::Disputed],
            'disputed' => [self::Completed],
            'rejected' => [],
            'cancelled' => [],
            'countered' => [],
            'expired' => [],
        ];
    }
}
