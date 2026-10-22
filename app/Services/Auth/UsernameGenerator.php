<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;

final class UsernameGenerator
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9_]{1,28}[a-z0-9])?$/';

    /** Words that must never be usernames (routes, roles, impersonation risks). */
    private const RESERVED = ['admin', 'administrator', 'support', 'help', 'pilotwardrobe', 'staff', 'root', 'system',
        'api', 'auth', 'hangar', 'marketplace', 'seller', 'sellers', 'me', 'settings', 'security', 'official', 'moderator'];

    public function generate(string $first, string $last): string
    {
        $base = Str::of(Str::ascii($first.'_'.$last))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(24, '')->toString();
        $base = $base === '' ? 'pilot' : $base;

        $candidate = $base;
        for ($i = 0; $i < 20; $i++) {
            if ($this->isAvailable($candidate)) {
                return $candidate;
            }
            $candidate = $base.'_'.random_int(10, 9999);
        }

        return $base.'_'.Str::lower(Str::random(6));
    }

    public function isAvailable(string $username): bool
    {
        $username = mb_strtolower($username);
        if (! preg_match(self::PATTERN, $username) || in_array($username, self::RESERVED, true)) {
            return false;
        }

        return ! User::withTrashed()->whereRaw('lower(username) = ?', [$username])->exists();
    }

    public function suggest(string $username): string
    {
        $base = Str::of($username)->lower()->replaceMatches('/[^a-z0-9_]+/', '')->limit(24, '')->trim('_')->toString() ?: 'pilot';
        for ($i = 0; $i < 20; $i++) {
            $candidate = $base.'_'.random_int(1, 999);
            if ($this->isAvailable($candidate)) {
                return $candidate;
            }
        }

        return $base.'_'.Str::lower(Str::random(5));
    }
}
