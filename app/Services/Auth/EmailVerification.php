<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Str;

/** 4-digit email OTP (VA5): 10-minute expiry, resend throttled to once per 60 s. */
final class EmailVerification
{
    public function __construct(
        private readonly OneTimeCodes $codes,
        private readonly NotificationService $notifications,
    ) {}

    public function send(User $user): void
    {
        $code = $this->codes->issue($user, 'email_verification', 4, (int) config('platform.auth.email_otp_ttl_seconds'));

        $this->notifications->notify($user, 'auth.verify_email', ['code' => $code], 'auth.verify_email:'.$user->id.':'.Str::ulid());
    }

    /** Seconds until another code may be sent (0 = now). */
    public function resendAvailableIn(User $user): int
    {
        $last = $this->codes->lastIssuedAt($user, 'email_verification');
        if ($last === null) {
            return 0;
        }

        return max(0, (int) config('platform.auth.email_otp_resend_seconds') - (int) $last->diffInSeconds(now()));
    }

    /** @return 'ok'|'invalid'|'expired' */
    public function verify(User $user, string $code): string
    {
        return $this->codes->verify($user, 'email_verification', $code);
    }

    /** `verification` block returned by register/resend. */
    public function meta(User $user): array
    {
        return [
            'required' => ! $user->hasVerifiedEmail(),
            'expires_in' => (int) config('platform.auth.email_otp_ttl_seconds'),
            'resend_available_in' => max($this->resendAvailableIn($user), (int) config('platform.auth.email_otp_resend_seconds')),
        ];
    }
}
