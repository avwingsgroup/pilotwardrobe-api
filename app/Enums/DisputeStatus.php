<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.7. */
enum DisputeStatus: string
{
    use HasTransitions;

    case Open = 'open';
    case AwaitingSeller = 'awaiting_seller';
    case UnderReview = 'under_review';
    case ResolvedBuyer = 'resolved_buyer';
    case ResolvedSeller = 'resolved_seller';
    case ResolvedSplit = 'resolved_split';
    case Withdrawn = 'withdrawn';
    case Appealed = 'appealed';

    public static function transitions(): array
    {
        $resolved = [self::ResolvedBuyer, self::ResolvedSeller, self::ResolvedSplit];

        return [
            'open' => [self::AwaitingSeller, self::UnderReview, self::Withdrawn, ...$resolved],
            'awaiting_seller' => [self::UnderReview, self::Withdrawn, ...$resolved],
            'under_review' => [self::Withdrawn, ...$resolved],
            'appealed' => $resolved,
            'resolved_buyer' => [self::Appealed],
            'resolved_seller' => [self::Appealed],
            'resolved_split' => [self::Appealed],
            'withdrawn' => [],
        ];
    }

    public static function active(): array
    {
        return [self::Open, self::AwaitingSeller, self::UnderReview, self::Appealed];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
