<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * Minor ↔ major unit conversion for PSPs that take decimal amounts
 * (Flutterwave, Monnify, PayPal). Integer arithmetic only — no floats on the way out.
 */
final class Amounts
{
    public static function toMajor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }

    /** Parses a PSP decimal amount ("1234.5", 1234.50) into minor units, rounding half-up. */
    public static function toMinor(string|int|float $major): int
    {
        $string = is_float($major) ? number_format($major, 2, '.', '') : trim((string) $major);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $string, $m)) {
            return 0;
        }
        $fraction = str_pad(substr(($m[3] ?? '').'000', 0, 3), 3, '0');
        $minor = ((int) $m[2]) * 100 + intdiv((int) $fraction + 5, 10);

        return $m[1] === '-' ? -$minor : $minor;
    }
}
