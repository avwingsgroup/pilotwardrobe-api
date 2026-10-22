<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Currencies the platform trades in (contract §1.6). All amounts are stored as
 * integer minor units; `exponent()` says how many minor units make one major unit.
 */
enum Currency: string
{
    case USD = 'USD';
    case NGN = 'NGN';
    case GHS = 'GHS';
    case GBP = 'GBP';
    case EUR = 'EUR';

    public function exponent(): int
    {
        return 2;
    }

    public function minorPerMajor(): int
    {
        return 10 ** $this->exponent();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
