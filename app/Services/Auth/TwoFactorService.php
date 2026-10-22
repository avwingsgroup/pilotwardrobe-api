<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * TOTP (authenticator app), SMS fallback codes, single-use backup codes, pending
 * sign-in challenges (VA7) and "trust this device for 30 days".
 */
final class TwoFactorService
{
    public const TRUSTED_COOKIE = 'pw_trusted_device';

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly OneTimeCodes $codes,
        private readonly NotificationService $notifications,
    ) {}

    // ---------------------------------------------------------------- challenges

    /** @param array<string, mixed> $context */
    public function createChallenge(User $user, array $context = []): string
    {
        $id = '2fa_'.Str::lower(Str::random(24));
        Cache::put($this->challengeKey($id), ['user_id' => $user->id, 'attempts' => 0] + $context,
            now()->addSeconds((int) config('platform.auth.two_factor_challenge_ttl_seconds')));

        return $id;
    }

    /** @return array<string, mixed> challenge context; throws 419 when missing/expired */
    public function challenge(string $id): array
    {
        $challenge = Cache::get($this->challengeKey($id));
        if (! is_array($challenge)) {
            throw ApiException::sessionExpired('Your sign-in session expired. Please sign in again.');
        }

        return $challenge;
    }

    public function forgetChallenge(string $id): void
    {
        Cache::forget($this->challengeKey($id));
    }

    public function recordChallengeFailure(string $id, array $challenge): void
    {
        $challenge['attempts'] = ($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= 5) {
            $this->forgetChallenge($id);

            throw ApiException::sessionExpired('Too many incorrect codes. Please sign in again.');
        }
        Cache::put($this->challengeKey($id), $challenge, now()->addSeconds((int) config('platform.auth.two_factor_challenge_ttl_seconds')));
    }

    /** @return list<string> */
    public function methodsFor(User $user): array
    {
        $methods = ['totp'];
        if ($user->phone) {
            $methods[] = 'sms';
        }
        if (! empty($user->two_factor_recovery_codes)) {
            $methods[] = 'backup_code';
        }

        return $methods;
    }

    public function sendSmsCode(User $user, string $challengeId): void
    {
        if (! $user->phone) {
            throw ApiException::validation(['method' => 'No phone number is on file for SMS codes.']);
        }

        $code = $this->codes->issue($user, 'two_factor_sms', 6, 600);
        $this->notifications->smsCode($user, 'security.two_factor_code', ['code' => $code], 'two_factor_sms:'.$challengeId.':'.Str::ulid());
    }

    // ---------------------------------------------------------------- verification

    public function verify(User $user, string $method, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return match ($method) {
            'totp' => $this->verifyTotp($user, $code),
            'sms' => $this->codes->verify($user, 'two_factor_sms', $code) === 'ok',
            'backup_code' => $this->consumeBackupCode($user, $code),
            default => false,
        };
    }

    public function verifyTotp(User $user, string $code): bool
    {
        if (! $user->two_factor_secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, (int) Cache::get("2fa:last:{$user->id}", 0), 1);
        if ($timestep === false) {
            return false;
        }

        // Block replay of the same code within its validity window.
        Cache::put("2fa:last:{$user->id}", $timestep === true ? $this->google2fa->getTimestamp() : $timestep, now()->addMinutes(5));

        return true;
    }

    private function consumeBackupCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = hash('sha256', strtoupper(str_replace('-', '', $code)));
        $index = array_search($hash, $hashes, true);
        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return true;
    }

    // ---------------------------------------------------------------- enrolment

    /** @return array{secret: string, otpauth_url: string, qr_svg: string} */
    public function beginEnrolment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        $url = $this->google2fa->getQRCodeUrl('Pilotwardrobe', $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $svg];
    }

    /** @return list<string> plaintext backup codes, shown once */
    public function confirmEnrolment(User $user, string $code): array
    {
        if (! $this->verifyTotp($user, $code)) {
            throw ApiException::validation(['code' => 'That code is not valid. Check the time on your device and try again.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->regenerateBackupCodes($user);
    }

    /** @return list<string> */
    public function regenerateBackupCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(Str::random(10));
            $plain[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }
        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', str_replace('-', '', $c)), $plain),
        ])->save();

        return $plain;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        TrustedDevice::query()->where('user_id', $user->id)->delete();
    }

    // ---------------------------------------------------------------- trusted devices

    public function isTrustedDevice(User $user, Request $request): bool
    {
        $token = (string) $request->cookie(self::TRUSTED_COOKIE, '');
        if ($token === '') {
            return false;
        }

        return TrustedDevice::query()->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function trustDevice(User $user, Request $request): Cookie
    {
        $token = Str::random(48);
        $days = (int) config('platform.auth.trusted_device_days');
        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'device' => DeviceDescriber::label($request->userAgent()),
            'expires_at' => now()->addDays($days),
        ]);

        return cookie(self::TRUSTED_COOKIE, $token, $days * 24 * 60, '/', config('session.domain'), (bool) config('session.secure', true), true, false, 'lax');
    }

    private function challengeKey(string $id): string
    {
        return 'auth_challenge:'.$id;
    }
}
