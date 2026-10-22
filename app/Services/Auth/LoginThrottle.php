<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-email brute-force protection (contract §1.9): 5 consecutive failures lock the
 * email for 15 minutes (423 ACCOUNT_LOCKED with `meta.retry_after`). Counts are kept
 * in the cache (Redis in production) so they are shared across app servers.
 */
final class LoginThrottle
{
    public function assertNotLocked(string $email): void
    {
        $key = $this->key($email);
        if (RateLimiter::tooManyAttempts($key, $this->max())) {
            $retry = RateLimiter::availableIn($key);

            throw new ApiException(
                ErrorCode::ACCOUNT_LOCKED,
                'Too many failed attempts. Try again in '.max(1, (int) ceil($retry / 60)).' minutes or reset your password.',
                meta: ['retry_after' => $retry],
                headers: ['Retry-After' => (string) $retry],
            );
        }
    }

    /** Records a failure and returns attempts remaining before the lock. */
    public function failed(string $email): int
    {
        $key = $this->key($email);
        RateLimiter::hit($key, (int) config('platform.auth.login_lock_minutes') * 60);

        return max(0, $this->max() - RateLimiter::attempts($key));
    }

    public function clear(string $email): void
    {
        RateLimiter::clear($this->key($email));
    }

    private function max(): int
    {
        return (int) config('platform.auth.login_max_attempts');
    }

    private function key(string $email): string
    {
        return 'login:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
