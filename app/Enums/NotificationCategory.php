<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationCategory: string
{
    case Orders = 'orders';
    case OffersSwaps = 'offers_swaps';
    case Messages = 'messages';
    case Account = 'account';
    case Disputes = 'disputes';
    case Payouts = 'payouts';
    case Reviews = 'reviews';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Order updates',
            self::OffersSwaps => 'Offers & swaps',
            self::Messages => 'Messages',
            self::Account => 'Account & security',
            self::Disputes => 'Disputes',
            self::Payouts => 'Payouts & wallet',
            self::Reviews => 'Reviews',
            self::Marketing => 'News & promotions',
        };
    }

    /** Categories shown in the preference matrix (VB11). */
    public static function configurable(): array
    {
        return [self::OffersSwaps, self::Orders, self::Messages, self::Payouts, self::Reviews, self::Marketing];
    }

    /** Transactional categories whose emails can never be switched off (§3). */
    public function emailAlwaysOn(): bool
    {
        return in_array($this, [self::Orders, self::Account, self::Disputes, self::Payouts], true);
    }
}
