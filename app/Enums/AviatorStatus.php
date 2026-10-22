<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

enum AviatorStatus: string
{
    use HasTransitions;

    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function transitions(): array
    {
        return [
            'not_submitted' => [self::Pending],
            'pending' => [self::Approved, self::Rejected],
            'rejected' => [self::Pending],
            'approved' => [],
        ];
    }
}
