<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;

/** Human labels for sessions ("Chrome on macOS") and a coarse device fingerprint for new-device alerts. */
final class DeviceDescriber
{
    public static function label(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if (preg_match('/Pilotwardrobe\/?\s*(iOS|Android)?\s*([\d.]+)?/i', $ua, $m)) {
            return trim('Pilotwardrobe '.($m[1] ?? 'app').' '.($m[2] ?? ''));
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Chromium') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$os}";
    }

    /** Coarse location from a CDN header when available; we do not ship a GeoIP database. */
    public static function location(Request $request): ?string
    {
        $country = $request->header('CF-IPCountry') ?? $request->header('X-Country-Code');
        if (! $country || strlen($country) !== 2) {
            return null;
        }
        $city = $request->header('CF-IPCity');

        return trim(($city ? $city.', ' : '').strtoupper($country));
    }

    /** Device label + /24 network: stable across reloads, changes on a genuinely new device or network. */
    public static function fingerprint(Request $request): string
    {
        $ip = (string) $request->ip();
        $network = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 4)) : implode('.', array_slice(explode('.', $ip), 0, 3));

        return hash('sha256', self::label($request->userAgent()).'|'.$network);
    }
}
