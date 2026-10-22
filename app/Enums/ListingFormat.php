<?php

declare(strict_types=1);

namespace App\Enums;

enum ListingFormat: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Print = 'print';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function requiresShipping(): bool
    {
        return $this !== self::Digital;
    }
}
