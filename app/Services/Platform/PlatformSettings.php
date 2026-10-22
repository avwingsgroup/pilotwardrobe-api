<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-editable platform configuration (AD12 escrow timings, AD20 markets,
 * gateways and feature flags). Values live in `platform_settings` (jsonb) with
 * code defaults from `config/platform.php`; reads are cached and busted on write.
 */
final class PlatformSettings
{
    private const CACHE_KEY = 'platform:settings';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => PlatformSetting::query()->pluck('value', 'key')->all());

        return $this->loaded = self::merge(config('platform.defaults'), $stored);
    }

    public function set(string $key, mixed $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Deep-merges associative arrays; lists (gateways, flags, markets) are replaced wholesale. */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($stored as $key => $value) {
            $mergeable = is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                && ! array_is_list($value) && ! array_is_list($defaults[$key]);
            $defaults[$key] = $mergeable ? self::merge($defaults[$key], $value) : $value;
        }

        return $defaults;
    }

    // ---------------------------------------------------------------- typed accessors

    public function autoReleaseHours(): int
    {
        return (int) $this->get('escrow.auto_release_hours');
    }

    public function dispatchSlaDays(): int
    {
        return (int) $this->get('escrow.dispatch_sla_days');
    }

    public function disputeWindowHours(): int
    {
        return (int) $this->get('escrow.dispute_window_hours');
    }

    public function newSellerPayoutHoldDays(): int
    {
        return (int) $this->get('escrow.new_seller_payout_hold_days');
    }

    public function featureEnabled(string $flag): bool
    {
        foreach ($this->get('feature_flags', []) as $f) {
            if (($f['key'] ?? null) === $flag) {
                return (bool) ($f['enabled'] ?? false);
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public function gateway(string $code): ?array
    {
        foreach ($this->get('gateways', []) as $gateway) {
            if (($gateway['code'] ?? null) === $code) {
                return $gateway;
            }
        }

        return null;
    }
}
