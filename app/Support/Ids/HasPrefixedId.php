<?php

declare(strict_types=1);

namespace App\Support\Ids;

use Illuminate\Support\Str;

/**
 * Opaque, prefixed, non-sequential string primary keys (`usr_01j9…`, `ord_01j9…`).
 *
 * The contract treats ids as opaque strings, so they are generated here from a
 * ULID (time-ordered for index locality, 80 bits of randomness) and stored as
 * the primary key. Models declare their prefix with `protected static string $idPrefix`.
 */
trait HasPrefixedId
{
    public static function bootHasPrefixedId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = static::newPrefixedId();
            }
        });
    }

    public function initializeHasPrefixedId(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public static function newPrefixedId(): string
    {
        return static::$idPrefix.'_'.strtolower((string) Str::ulid());
    }

    public static function idPrefix(): string
    {
        return static::$idPrefix;
    }
}
