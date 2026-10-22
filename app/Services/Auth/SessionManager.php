<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AuthSession;
use App\Models\KnownDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Starts and ends authenticated sessions in both modes the SPA supports (handoff §2):
 *
 *  - cookie mode (Sanctum SPA): the request carries a session → log in on the `web` guard;
 *  - token mode: no session → issue a short-lived personal access token.
 *
 * Either way an `auth_sessions` row represents the device, so users can list and revoke
 * sessions (`/me/security`) and staff/impersonation sessions get their own limits.
 */
final class SessionManager
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{staff?: bool, impersonator?: User|null, ttl_minutes?: int|null, abilities?: list<string>}  $options
     * @return array<string, mixed> token fields for the response (empty in cookie mode)
     */
    public function start(User $user, Request $request, bool $remember = false, array $options = []): array
    {
        $isStaff = (bool) ($options['staff'] ?? false);
        $impersonator = $options['impersonator'] ?? null;

        $session = AuthSession::query()->create([
            'user_id' => $user->id,
            'device' => DeviceDescriber::label($request->userAgent()),
            'location' => DeviceDescriber::location($request),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'device_fingerprint' => DeviceDescriber::fingerprint($request),
            'is_staff' => $isStaff,
            'is_impersonation' => $impersonator !== null,
            'impersonator_id' => $impersonator?->id,
            'last_active_at' => now(),
        ]);

        $tokenFields = [];

        if ($request->hasSession() && $impersonator === null) {
            Auth::guard('web')->login($user, $remember && ! $isStaff);
            $request->session()->regenerate();
            $request->session()->put('auth_session_id', $session->id);
            $session->forceFill(['session_id' => $request->session()->getId()])->save();
        } else {
            $ttl = $options['ttl_minutes'] ?? ($remember
                ? (int) config('platform.auth.remember_token_ttl_days') * 24 * 60
                : (int) config('platform.auth.token_ttl_minutes'));
            $expiresAt = now()->addMinutes($ttl);
            $token = $user->createToken($session->device ?? 'api', $options['abilities'] ?? ['*'], $expiresAt);
            $session->forceFill(['token_id' => $token->accessToken->id, 'expires_at' => $expiresAt])->save();

            $tokenFields = [
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $ttl * 60,
            ];
        }

        if ($impersonator === null) {
            $this->recordDevice($user, $request, $session);
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        return $tokenFields;
    }

    /** Ends the current session or token (logout). */
    public function endCurrent(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && is_int($token->getKey())) {
            AuthSession::query()->where('token_id', $token->id)->update(['revoked_at' => now()]);
            $token->delete();
        }

        if ($request->hasSession()) {
            $id = $request->session()->get('auth_session_id');
            if ($id) {
                AuthSession::query()->whereKey($id)->update(['revoked_at' => now()]);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** Revokes one device session (and its token / server-side session). */
    public function revoke(AuthSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session->forceFill(['revoked_at' => now()])->save();
            if ($session->token_id) {
                PersonalAccessToken::query()->whereKey($session->token_id)->delete();
            }
            if ($session->session_id) {
                app('session')->driver()->getHandler()->destroy($session->session_id);
            }
        });
    }

    /** Revokes every session of the user except (optionally) the current one. */
    public function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        $sessions = AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->get();

        foreach ($sessions as $session) {
            $this->revoke($session);
        }

        // Tokens issued before session tracking (or orphaned) go too.
        $keepToken = $exceptSessionId ? AuthSession::query()->whereKey($exceptSessionId)->value('token_id') : null;
        $user->tokens()->when($keepToken, fn ($q) => $q->where('id', '!=', $keepToken))->delete();

        $user->forceFill(['remember_token' => null])->saveQuietly();

        return $sessions->count();
    }

    public function currentSessionId(Request $request): ?string
    {
        $session = $request->attributes->get('auth_session');

        return $session?->id;
    }

    private function recordDevice(User $user, Request $request, AuthSession $session): void
    {
        $fingerprint = (string) $session->device_fingerprint;
        $known = KnownDevice::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)->first();

        if ($known) {
            $known->forceFill(['last_seen_at' => now()])->save();

            return;
        }

        $isFirstDevice = ! KnownDevice::query()->where('user_id', $user->id)->exists();
        KnownDevice::query()->create(['user_id' => $user->id, 'fingerprint' => $fingerprint, 'last_seen_at' => now()]);

        if (! $isFirstDevice) {
            $this->notifications->notify($user, 'security.new_device', [
                'device' => (string) $session->device,
                'location' => $session->location ?? 'Unknown location',
                'time' => now()->toDayDateTimeString().' UTC',
            ], 'security.new_device:'.$session->id);
            $this->audit->log('SECURITY.NEW_DEVICE', $user, "Sign-in from {$session->device}", 'warning', actor: $user);
        }
    }
}
