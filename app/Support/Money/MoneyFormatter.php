<?php

declare(strict_types=1);

namespace App\Support\Money;

use NumberFormatter;

/**
 * Locale-aware `formatted` strings. The locale comes from `Accept-Language`
 * (see SetRequestLocale middleware) and falls back to the app locale.
 */
final class MoneyFormatter
{
    /** @var array<string, NumberFormatter> */
    private static array $formatters = [];

    public static function format(Money $money, ?string $locale = null): string
    {
        $locale ??= str_replace('-', '_', app()->getLocale());
        $formatter = self::$formatters[$locale] ??= new NumberFormatter($locale, NumberFormatter::CURRENCY);

        $major = $money->amount / $money->currency->minorPerMajor();
        $result = $formatter->formatCurrency($major, $money->currency->value);

        return $result === false ? $money->currency->value.' '.number_format($major, 2) : $result;
    }
}
