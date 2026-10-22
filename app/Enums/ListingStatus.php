<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.1. */
enum ListingStatus: string
{
    use HasTransitions;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case NeedsChanges = 'needs_changes';
    case Active = 'active';
    case Paused = 'paused';
    case Reserved = 'reserved';
    case Unavailable = 'unavailable';
    case Sold = 'sold';
    case Swapped = 'swapped';
    case Archived = 'archived';
    case Rejected = 'rejected';
    case Removed = 'removed';

    public static function transitions(): array
    {
        return [
            'draft' => [self::PendingReview, self::Active],
            'pending_review' => [self::Active, self::NeedsChanges, self::Rejected, self::Removed],
            'needs_changes' => [self::PendingReview, self::Removed],
            'active' => [self::Paused, self::Reserved, self::Archived, self::Sold, self::Swapped, self::Unavailable, self::Removed, self::PendingReview],
            'paused' => [self::Active, self::Archived, self::Sold, self::Removed],
            'reserved' => [self::Active, self::Sold, self::Swapped, self::Removed],
            'unavailable' => [self::Active, self::Archived, self::Removed],
            'sold' => [self::Active],
            'archived' => [self::Active],
            'swapped' => [],
            'rejected' => [],
            'removed' => [],
        ];
    }

    /** Visible in public search and on public listing pages. */
    public static function publiclyVisible(): array
    {
        return [self::Active, self::Reserved];
    }

    /** Counted towards the 3-live-listing cap for sellers without approved KYC. */
    public static function live(): array
    {
        return [self::Active, self::Reserved, self::Paused, self::PendingReview, self::Unavailable];
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Live',
            self::PendingReview => 'In review',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Reserved, self::PendingReview => 'info',
            self::Paused, self::NeedsChanges, self::Unavailable => 'warning',
            self::Removed, self::Rejected => 'danger',
            default => 'neutral',
        };
    }
}
