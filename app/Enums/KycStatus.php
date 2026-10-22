<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.8 plus `more_info` (AD5 "request info"). */
enum KycStatus: string
{
    use HasTransitions;

    case NotStarted = 'not_started';
    case Pending = 'pending';
    case MoreInfo = 'more_info';
    case Escalated = 'escalated';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public static function transitions(): array
    {
        return [
            'not_started' => [self::Pending],
            'pending' => [self::Approved, self::Rejected, self::Escalated, self::MoreInfo],
            'escalated' => [self::Approved, self::Rejected, self::MoreInfo],
            'more_info' => [self::Pending],
            'rejected' => [self::Pending],
            'approved' => [self::Expired],
            'expired' => [self::Pending],
        ];
    }

    /** States in which the user may (re)submit personal details and documents. */
    public function isEditableByUser(): bool
    {
        return in_array($this, [self::NotStarted, self::MoreInfo, self::Rejected, self::Expired], true);
    }

    public function isInReview(): bool
    {
        return $this === self::Pending || $this === self::Escalated;
    }
}
