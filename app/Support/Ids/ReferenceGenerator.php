<?php

declare(strict_types=1);

namespace App\Support\Ids;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Human-facing business references (order numbers, payment references, case numbers).
 *
 * References are random rather than sequential so they leak neither volume nor
 * neighbouring records. Uniqueness is enforced by a database unique index; this
 * generator also pre-checks and retries so collisions never surface as errors.
 */
final class ReferenceGenerator
{
    /** Crockford base32 without the ambiguous I, L, O, U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param  class-string<Model>  $model
     */
    public static function unique(string $model, string $column, callable $make, int $attempts = 8): string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $candidate = $make();
            if (! $model::query()->withoutGlobalScopes()->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("Could not generate a unique {$column} after {$attempts} attempts.");
    }

    public static function digits(int $length): string
    {
        $out = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }

        return $out;
    }

    public static function code(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /** `PW-48213907` */
    public static function orderNumber(): string
    {
        return 'PW-'.self::digits(8);
    }

    /** `PW-2026-7KQ2X9M4TB` — sent to gateways as the merchant reference. */
    public static function paymentReference(): string
    {
        return 'PW-'.now()->format('Y').'-'.self::code(10);
    }

    public static function prefixed(string $prefix, int $digits = 6): string
    {
        return $prefix.'-'.self::digits($digits);
    }
}
