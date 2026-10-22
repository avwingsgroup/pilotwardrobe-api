<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChallengeRequest;
use App\Http\Requests\Auth\EmailLookupRequest;
use App\Http\Requests\Auth\EmailOnlyRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\TwoFactorRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\EmailVerification;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordResets;
use App\Services\Auth\SessionManager;
use App\Services\Auth\TwoFactorService;
use App\Services\Auth\UsernameGenerator;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Email-first sign-in, registration, email OTP, 2FA and password reset (VA1–VA13). */
final class AuthController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly TwoFactorService $twoFactor,
        private readonly EmailVerification $emailVerification,
        private readonly AuditLogger $audit,
    ) {}

    /** `POST /auth/email-lookup` — does an account exist, and which sign-in methods does it have? */
    public function emailLookup(EmailLookupRequest $request): JsonResponse
    {
        $user = $this->findByEmail($request->validated('email'));

        if ($user === null || $user->status === UserStatus::Suspended || $user->isStaff()) {
            return ApiResponse::ok(['exists' => false, 'methods' => []]);
        }

        $methods = $user->password ? ['password'] : [];
        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
            $methods[] = $provider;
        }

        return ApiResponse::ok(['exists' => true, 'methods' => array_values(array_unique($methods))]);
    }

    /** `POST /auth/login` */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $this->throttle->assertNotLocked($email);

        $user = $this->findByEmail($email);
        // Always run a hash check so response time does not reveal whether the email exists.
        $valid = Hash::check($request->validated('password'), $user->password ?? '$2y$12$'.str_repeat('a', 53));

        if (! $user || ! $user->password || ! $valid) {
            $remaining = $this->throttle->failed($email);
            if ($user) {
                $this->audit->log('AUTH.LOGIN_FAILED', $user, "{$remaining} attempts remaining", 'warning', actor: $user);
            }
            $this->throttle->assertNotLocked($email);

            throw ApiException::validation(['email' => 'Incorrect email or password.'], 'Incorrect email or password.', ['attempts_remaining' => $remaining]);
        }

        $this->throttle->clear($email);
        $this->assertCanSignIn($user);

        if ($user->status === UserStatus::Deactivated) {
            $user->forceFill(['status' => UserStatus::Active, 'deactivated_at' => null])->save();
            $this->audit->log('USER.REACTIVATED', $user, 'Signed in after deactivation', actor: $user);
        }

        if ($user->hasTwoFactorEnabled() && ! $this->twoFactor->isTrustedDevice($user, $request)) {
            $challengeId = $this->twoFactor->createChallenge($user, ['remember' => (bool) $request->boolean('remember')]);

            return ApiResponse::ok([
                'status' => 'two_factor_required',
                'challenge_id' => $challengeId,
                'methods' => $this->twoFactor->methodsFor($user),
                'sms_hint' => $user->phone ? '•••• '.substr($user->phone, -4) : null,
            ]);
        }

        return $this->authenticated($user, $request, (bool) $request->boolean('remember'));
    }

    /** `POST /auth/two-factor` */
    public function twoFactor(TwoFactorRequest $request): JsonResponse
    {
        $challengeId = $request->validated('challenge_id');
        $challenge = $this->twoFactor->challenge($challengeId);
        $user = User::query()->findOrFail($challenge['user_id']);
        $this->assertCanSignIn($user);

        $code = (string) $request->validated('code');
        $method = $request->validated('method') ?? (preg_match('/^\d{6}$/', $code) ? 'totp' : 'backup_code');

        $ok = DB::transaction(fn () => $this->twoFactor->verify($user, $method, $code));
        if (! $ok && $method === 'totp') {
            // The 6-digit code may be an SMS code.
            $ok = DB::transaction(fn () => $this->twoFactor->verify($user, 'sms', $code));
        }
        if (! $ok) {
            $this->twoFactor->recordChallengeFailure($challengeId, $challenge);

            throw ApiException::validation(['code' => "That code isn't right. Try again."]);
        }

        $this->twoFactor->forgetChallenge($challengeId);
        $this->audit->log('AUTH.TWO_FACTOR_PASSED', $user, "Method: {$method}", actor: $user);

        $response = $this->authenticated($user, $request, (bool) ($challenge['remember'] ?? false));
        if ($request->boolean('trust_device')) {
            $response->withCookie($this->twoFactor->trustDevice($user, $request));
        }

        return $response;
    }

    /** `POST /auth/two-factor/sms` */
    public function twoFactorSms(ChallengeRequest $request): JsonResponse
    {
        $challenge = $this->twoFactor->challenge($request->validated('challenge_id'));
        $user = User::query()->findOrFail($challenge['user_id']);
        $this->twoFactor->sendSmsCode($user, $request->validated('challenge_id'));

        return ApiResponse::ok([
            'sent' => true,
            'sms_hint' => '•••• '.substr((string) $user->phone, -4),
            'resend_available_in' => 30,
        ], 'We sent a code to your phone.');
    }

    /** `POST /auth/register` */
    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $user = $register->handle($request->validated());
        $tokenFields = $this->sessions->start($user, $request);

        return ApiResponse::created(array_merge([
            'user' => new AuthUserResource($user->fresh()),
            'verification' => $this->emailVerification->meta($user),
        ], $tokenFields), 'Account created. Check your email for a 4-digit code.');
    }

    /** `POST /auth/email/verify` */
    public function verifyEmail(VerifyEmailRequest $request, NotificationService $notifications): JsonResponse
    {
        $user = $this->findByEmail($request->validated('email'));
        if ($user === null) {
            throw ApiException::notFound('We could not find an account with that email.');
        }

        if (! $user->hasVerifiedEmail()) {
            $result = DB::transaction(fn () => $this->emailVerification->verify($user, (string) $request->validated('code')));
            if ($result !== 'ok') {
                throw ApiException::validation(['code' => $result === 'expired' ? 'That code has expired. Request a new one.' : "That code isn't right. Try again."]);
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            $notifications->notify($user, 'auth.welcome', [], 'auth.welcome:'.$user->id);
            $this->audit->log('USER.EMAIL_VERIFIED', $user, null, 'success', actor: $user);
        }

        $response = ApiResponse::ok(new AuthUserResource($user->fresh()), 'Email verified.');

        // Signing the user in here completes the register → verify flow when the session was lost.
        if ($request->user()?->id !== $user->id && $user->status->canSignIn()) {
            $tokenFields = $this->sessions->start($user, $request);
            if ($tokenFields !== []) {
                $response = ApiResponse::ok(array_merge((new AuthUserResource($user->fresh()))->resolve($request), $tokenFields), 'Email verified.');
            }
        }

        return $response;
    }

    /** `POST /auth/email/resend` — always 200 (no account enumeration). */
    public function resendEmail(EmailOnlyRequest $request): JsonResponse
    {
        $user = $this->findByEmail($request->validated('email'));
        $wait = (int) config('platform.auth.email_otp_resend_seconds');

        if ($user && ! $user->hasVerifiedEmail()) {
            $remaining = $this->emailVerification->resendAvailableIn($user);
            if ($remaining > 0) {
                return ApiResponse::ok(['resend_available_in' => $remaining], 'Please wait before requesting another code.');
            }
            $this->emailVerification->send($user);
        }

        return ApiResponse::ok(['resend_available_in' => $wait], 'A new code is on its way.');
    }

    /** `POST /auth/password/forgot` — same response whether or not the email exists. */
    public function forgotPassword(EmailOnlyRequest $request, PasswordResets $resets): JsonResponse
    {
        $user = $this->findByEmail($request->validated('email'));
        if ($user && $user->status !== UserStatus::Suspended && ! $user->isStaff()) {
            $resets->sendLink($user);
            $this->audit->log('AUTH.PASSWORD_RESET_REQUESTED', $user, null, actor: $user);
        }

        return ApiResponse::ok(['resend_available_in' => 60], 'If an account exists for that email, a reset link is on its way.');
    }

    /** `GET /auth/password/reset/{token}` */
    public function checkResetToken(string $token, PasswordResets $resets): JsonResponse
    {
        $email = $resets->emailFor($token);
        if ($email === null) {
            throw ApiException::tokenExpired();
        }

        return ApiResponse::ok(['valid' => true, 'email' => $email]);
    }

    /** `POST /auth/password/reset` */
    public function resetPassword(ResetPasswordRequest $request, PasswordResets $resets, NotificationService $notifications): JsonResponse
    {
        $data = $request->validated();
        $email = $resets->emailFor($data['token']);
        if ($email === null || mb_strtolower($email) !== $data['email']) {
            throw ApiException::tokenExpired();
        }

        $user = $this->findByEmail($email);
        if ($user === null) {
            throw ApiException::tokenExpired();
        }

        DB::transaction(function () use ($user, $data, $resets) {
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
            $resets->consume($data['token']);
            // The person resetting is not signed in, so "log out other devices" means all of them.
            if ($data['logout_other_devices'] ?? true) {
                $this->sessions->revokeAll($user);
            }
            $this->throttle->clear($user->email);
            $this->audit->log('AUTH.PASSWORD_RESET', $user, null, 'warning', actor: $user);
        });

        $notifications->notify($user, 'security.password_changed', [], 'security.password_changed:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(['reset' => true], 'Your password has been updated. You can now sign in.');
    }

    /** `GET /auth/me` */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        return ApiResponse::ok(new AuthUserResource($user));
    }

    /** `POST /auth/logout` */
    public function logout(Request $request): Response
    {
        $this->sessions->endCurrent($request);

        return ApiResponse::noContent();
    }

    /** `GET /auth/username-availability?username=` */
    public function usernameAvailability(Request $request, UsernameGenerator $usernames): JsonResponse
    {
        $username = mb_strtolower(trim((string) $request->query('username', '')));
        $available = $usernames->isAvailable($username);

        return ApiResponse::ok([
            'available' => $available,
            'suggestion' => $available ? null : $usernames->suggest($username),
        ]);
    }

    // ----------------------------------------------------------------

    private function authenticated(User $user, Request $request, bool $remember): JsonResponse
    {
        $tokenFields = $this->sessions->start($user, $request, $remember);
        $this->audit->log('AUTH.SIGNED_IN', $user, null, actor: $user);

        return ApiResponse::ok(array_merge([
            'status' => 'authenticated',
            'user' => new AuthUserResource($user->fresh()),
        ], $tokenFields));
    }

    private function assertCanSignIn(User $user): void
    {
        if ($user->status === UserStatus::Suspended) {
            throw ApiException::forbidden('Your account is suspended. Contact support for help.');
        }
        if ($user->isStaff() && ! config('platform.auth.allow_staff_password_login')) {
            throw ApiException::validation(['email' => 'Staff accounts sign in through the Control Tower.'], 'Staff accounts sign in through the Control Tower.');
        }
    }

    private function findByEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }
}
