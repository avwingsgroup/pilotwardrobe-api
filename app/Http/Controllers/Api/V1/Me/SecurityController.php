<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\ChangePasswordRequest;
use App\Models\AuthSession;
use App\Models\SocialAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Auth\TwoFactorService;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Settings · Security (VB17): sign-in methods, 2FA, sessions, password. */
final class SecurityController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** `GET /me/security` */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $this->sessions->currentSessionId($request);

        $methods = [[
            'provider' => 'password',
            'connected' => $user->password !== null,
            'detail' => $user->password_changed_at ? 'changed '.$user->password_changed_at->diffForHumans() : null,
        ]];
        $linked = $user->socialAccounts()->get()->keyBy('provider');
        foreach (['google', 'facebook'] as $provider) {
            $methods[] = [
                'provider' => $provider,
                'connected' => $linked->has($provider),
                'detail' => $linked->get($provider)?->email,
            ];
        }

        $sessions = AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('is_impersonation', false)
            ->orderByDesc('last_active_at')->limit(20)->get()
            ->map(fn (AuthSession $s) => [
                'id' => $s->id,
                'device' => $s->device,
                'location' => $s->location,
                'last_active_at' => $s->last_active_at?->toIso8601ZuluString(),
                'current' => $s->id === $currentId,
            ])->values();

        return ApiResponse::ok([
            'methods' => $methods,
            'two_factor' => [
                'authenticator_enabled' => $user->hasTwoFactorEnabled(),
                'sms_backup_masked' => $user->phone ? '•••• '.substr($user->phone, -4) : null,
                'backup_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
            ],
            'sessions' => $sessions,
            'password_changed_at' => $user->password_changed_at?->toIso8601ZuluString(),
        ]);
    }

    /** `PUT /me/password` */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->password && ! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
            throw ApiException::validation(['current_password' => 'Your current password is incorrect.']);
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
            if ($data['logout_other_devices'] ?? true) {
                $this->sessions->revokeAll($user, $this->sessions->currentSessionId($request));
            }
            $this->audit->log('SECURITY.PASSWORD_CHANGED', $user, null, 'warning', actor: $user);
        });

        $this->notifications->notify($user, 'security.password_changed', [], 'security.password_changed:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(['changed' => true], 'Password updated.');
    }

    /** `DELETE /me/sessions/{id}` */
    public function revokeSession(Request $request, string $id): Response
    {
        $user = $request->user();
        $session = AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->whereKey($id)->first();
        if ($session === null) {
            throw ApiException::notFound('Session not found.');
        }
        if ($session->id === $this->sessions->currentSessionId($request)) {
            throw ApiException::conflict('Use “Sign out” to end the session you are using now.');
        }

        $this->sessions->revoke($session);
        $this->audit->log('SECURITY.SESSION_REVOKED', $user, (string) $session->device, actor: $user);

        return ApiResponse::noContent();
    }

    /** `POST /me/sessions/logout-others` */
    public function logoutOthers(Request $request): Response
    {
        $user = $request->user();
        $count = $this->sessions->revokeAll($user, $this->sessions->currentSessionId($request));
        $this->audit->log('SECURITY.SESSIONS_REVOKED', $user, "{$count} sessions", 'warning', actor: $user);
        $this->notifications->notify($user, 'security.sessions_revoked', [], 'security.sessions_revoked:'.$user->id.':'.now()->timestamp);

        return ApiResponse::noContent();
    }

    /** `DELETE /me/social/{provider}` — 409 when it is the last sign-in method. */
    public function unlinkSocial(Request $request, string $provider): Response
    {
        $user = $request->user();
        $account = SocialAccount::query()->where('user_id', $user->id)->where('provider', $provider)->first();
        if ($account === null) {
            throw ApiException::notFound('That sign-in method is not linked.');
        }

        $otherMethods = ($user->password ? 1 : 0) + SocialAccount::query()->where('user_id', $user->id)->where('provider', '!=', $provider)->count();
        if ($otherMethods === 0) {
            throw ApiException::conflict('Set a password before unlinking your only sign-in method.');
        }

        $account->delete();
        $this->audit->log('SECURITY.PROVIDER_UNLINKED', $user, ucfirst($provider), 'warning', actor: $user);

        return ApiResponse::noContent();
    }

    // ---------------------------------------------------------------- two-factor management (additions to the contract)

    /** `POST /me/two-factor` — start authenticator enrolment; returns secret + QR (SVG). */
    public function beginTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            throw ApiException::conflict('Two-step verification is already on.');
        }

        return ApiResponse::ok($twoFactor->beginEnrolment($user));
    }

    /** `POST /me/two-factor/confirm` — returns one-time backup codes. */
    public function confirmTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (! $user->two_factor_secret || $user->hasTwoFactorEnabled()) {
            throw ApiException::invalidTransition('Start two-step setup first.');
        }

        $codes = $twoFactor->confirmEnrolment($user, $data['code']);
        $this->audit->log('SECURITY.TWO_FACTOR_ENABLED', $user, null, 'success', actor: $user);
        $this->notifications->notify($user, 'security.two_factor_changed', ['state' => 'turned on'], 'security.2fa:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(['enabled' => true, 'backup_codes' => $codes], 'Two-step verification is on. Save your backup codes.');
    }

    /** `DELETE /me/two-factor` — requires the current password and a valid code. */
    public function disableTwoFactor(Request $request, TwoFactorService $twoFactor): Response
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ApiException::validation(['password' => 'Your current password is incorrect.']);
        }
        $method = preg_match('/^\d{6}$/', $data['code']) ? 'totp' : 'backup_code';
        if (! $twoFactor->verify($user, $method, $data['code'])) {
            throw ApiException::validation(['code' => "That code isn't right. Try again."]);
        }

        $twoFactor->disable($user);
        $this->audit->log('SECURITY.TWO_FACTOR_DISABLED', $user, null, 'danger', actor: $user);
        $this->notifications->notify($user, 'security.two_factor_changed', ['state' => 'turned off'], 'security.2fa:'.$user->id.':'.now()->timestamp);

        return ApiResponse::noContent();
    }

    /** `POST /me/two-factor/backup-codes` — regenerate backup codes (requires an authenticator code). */
    public function regenerateBackupCodes(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled() || ! $twoFactor->verifyTotp($user, $data['code'])) {
            throw ApiException::validation(['code' => "That code isn't right. Try again."]);
        }

        $codes = $twoFactor->regenerateBackupCodes($user);
        $this->audit->log('SECURITY.BACKUP_CODES_REGENERATED', $user, null, 'warning', actor: $user);

        return ApiResponse::ok(['backup_codes' => $codes]);
    }
}
