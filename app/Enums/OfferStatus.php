<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.2. `completed` once the accepted offer has been paid for. */
enum OfferStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Countered = 'countered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

    public static function transitions(): array
    {
        return [
            'pending' => [self::Accepted, self::Declined, self::Countered, self::Cancelled, self::Expired],
            'accepted' => [self::Completed, self::Expired, self::Cancelled],
            'countered' => [],
            'declined' => [],
            'cancelled' => [],
            'expired' => [],
            'completed' => [],
        ];
    }
}
