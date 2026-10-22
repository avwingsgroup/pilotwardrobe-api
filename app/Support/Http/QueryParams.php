<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared query-string conventions (§1.7): `page`, `per_page` (default 20, max 100),
 * comma lists, `true/false` booleans and admin `sort=field|-field`.
 */
final class QueryParams
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        $value = (int) $request->query('per_page', (string) $default);

        return max(1, min(self::MAX_PER_PAGE, $value ?: $default));
    }

    /** @return list<string> */
    public static function list(Request $request, string $key): array
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return [];
        }
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $parts), fn ($v) => $v !== ''));
    }

    public static function bool(Request $request, string $key): ?bool
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public static function int(Request $request, string $key): ?int
    {
        $raw = $request->query($key);

        return is_numeric($raw) ? (int) $raw : null;
    }

    public static function search(Request $request): ?string
    {
        $value = trim((string) $request->query('search', ''));

        return $value === '' ? null : mb_substr($value, 0, 120);
    }

    /**
     * Applies `sort=field` / `sort=-field` limited to an allow-list mapping public names to columns.
     *
     * @param  array<string, string>  $allowed  public sort key => column
     */
    public static function applySort(Builder $query, Request $request, array $allowed, string $default = '-created_at'): Builder
    {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! isset($allowed[$key])) {
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
            $key = ltrim($default, '-');
        }

        $column = $allowed[$key] ?? $key;

        return $query->orderBy($column, $direction)->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }
}
