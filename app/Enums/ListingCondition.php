<?php

declare(strict_types=1);

namespace App\Enums;

enum ListingCondition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case VeryGood = 'very_good';
    case Good = 'good';
    case Fair = 'fair';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::LikeNew => 'Like new',
            self::VeryGood => 'Very good',
            self::Good => 'Good',
            self::Fair => 'Fair',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
