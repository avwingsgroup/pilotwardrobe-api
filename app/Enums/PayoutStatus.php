<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

enum PayoutStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case OnHold = 'on_hold';
    case Rejected = 'rejected';

    public static function transitions(): array
    {
        return [
            'pending' => [self::Approved, self::AwaitingApproval, self::Processing, self::OnHold, self::Rejected],
            'awaiting_approval' => [self::Approved, self::OnHold, self::Rejected],
            'on_hold' => [self::AwaitingApproval, self::Approved, self::Rejected],
            'approved' => [self::Processing, self::OnHold, self::Rejected],
            'processing' => [self::Paid, self::Failed],
            'failed' => [self::Processing, self::Rejected],
            'paid' => [],
            'rejected' => [],
        ];
    }

    /** Funds stay reserved out of `available` while a payout is in any of these. */
    public static function reserving(): array
    {
        return [self::Pending, self::AwaitingApproval, self::Approved, self::Processing, self::OnHold, self::Failed];
    }
}
