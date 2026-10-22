<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCallbackRequest;
use App\Http\Requests\Auth\SocialCompleteRequest;
use App\Http\Requests\Auth\SocialLinkRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Auth\TwoFactorService;
use App\Services\Auth\UsernameGenerator;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Social sign-in (VA2, VA3, VA8). The backend owns the OAuth client secrets and the
 * `state` value; the provider redirects to the SPA, which posts `code` + `state` here.
 */
final class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /** `GET /auth/social/{provider}/redirect` */
    public function redirect(string $provider): JsonResponse
    {
        $this->assertProvider($provider);

        $state = Str::random(40);
        Cache::put("oauth_state:{$state}", $provider, now()->addMinutes(10));

        $url = $this->driver($provider)->stateless()->with(['state' => $state])->redirect()->getTargetUrl();

        return ApiResponse::ok(['url' => $url]);
    }

    /** `POST /auth/social/{provider}/callback` */
    public function callback(SocialCallbackRequest $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);

        if (Cache::pull('oauth_state:'.$request->validated('state')) !== $provider) {
            throw ApiException::validation(['state' => 'This sign-in attempt expired. Please try again.']);
        }

        try {
            $social = $this->driver($provider)->stateless()->user();
        } catch (Throwable $e) {
            report($e);

            throw ApiException::validation(['code' => "We couldn't sign you in with ".ucfirst($provider).'. Please try again.']);
        }

        $email = $social->getEmail() ? mb_strtolower($social->getEmail()) : null;
        $account = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', (string) $social->getId())->first();

        if ($account !== null) {
            return $this->signIn($account->user, $request);
        }

        $pending = [
            'provider' => $provider,
            'provider_user_id' => (string) $social->getId(),
            'email' => $email,
            'name' => (string) $social->getName(),
            'avatar_url' => $social->getAvatar(),
        ];
        $token = Str::random(48);
        Cache::put("oauth_pending:{$token}", $pending, now()->addMinutes(15));

        $existing = $email ? User::query()->whereRaw('lower(email) = ?', [$email])->first() : null;
        if ($existing !== null) {
            return ApiResponse::ok([
                'status' => 'link_required',
                'pending_token' => $token,
                'link_token' => $token,
                'existing_account' => [
                    'email' => $existing->email,
                    'display_name' => $existing->displayName(),
                    'methods' => $existing->password ? ['password'] : [],
                ],
            ]);
        }

        [$first, $last] = array_pad(explode(' ', trim((string) $social->getName()), 2), 2, '');

        return ApiResponse::ok([
            'status' => 'new_account',
            'pending_token' => $token,
            'profile' => [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'avatar_url' => $social->getAvatar(),
                'suggested_username' => app(UsernameGenerator::class)->generate($first ?: 'pilot', $last),
            ],
        ]);
    }

    /** `POST /auth/social/complete` — finish a new social account (VA3). */
    public function complete(SocialCompleteRequest $request, RegisterUser $register, UsernameGenerator $usernames, NotificationService $notifications): JsonResponse
    {
        $data = $request->validated();
        $pending = Cache::get("oauth_pending:{$data['pending_token']}");
        if (! is_array($pending) || empty($pending['email'])) {
            throw ApiException::sessionExpired('Your sign-up session expired. Please start again.');
        }
        if (! $usernames->isAvailable($data['username'])) {
            throw ApiException::validation(['username' => 'That username is taken.']);
        }
        if (User::query()->whereRaw('lower(email) = ?', [$pending['email']])->exists()) {
            throw ApiException::validation(['email' => 'An account with this email already exists.']);
        }

        [$first, $last] = array_pad(explode(' ', trim($pending['name']), 2), 2, '');

        $user = DB::transaction(function () use ($register, $data, $pending, $first, $last) {
            $user = $register->handle([
                'first_name' => $data['first_name'] ?? ($first ?: 'Pilot'),
                'last_name' => $data['last_name'] ?? $last,
                'username' => mb_strtolower($data['username']),
                'email' => $pending['email'],
                'country_code' => $data['country_code'],
                'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            ], $pending['provider'], emailVerified: true);

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $pending['provider'],
                'provider_user_id' => $pending['provider_user_id'],
                'email' => $pending['email'],
            ]);

            return $user;
        });

        Cache::forget("oauth_pending:{$data['pending_token']}");
        $notifications->notify($user, 'auth.welcome', [], 'auth.welcome:'.$user->id);
        $tokenFields = $this->sessions->start($user, $request);

        return ApiResponse::created(array_merge([
            'status' => 'authenticated',
            'user' => new AuthUserResource($user->fresh()),
        ], $tokenFields), 'Account created.');
    }

    /** `POST /auth/social/link` — link the social identity to an existing password account (VA8). */
    public function link(SocialLinkRequest $request, NotificationService $notifications): JsonResponse
    {
        $token = $request->validated('pending_token') ?? $request->validated('link_token');
        $pending = Cache::get("oauth_pending:{$token}");
        if (! is_array($pending) || empty($pending['email'])) {
            throw ApiException::sessionExpired('Your sign-in session expired. Please start again.');
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$pending['email']])->firstOrFail();
        if (! $user->password || ! Hash::check($request->validated('password'), $user->password)) {
            throw ApiException::validation(['password' => 'Incorrect password.']);
        }

        SocialAccount::query()->firstOrCreate(
            ['provider' => $pending['provider'], 'provider_user_id' => $pending['provider_user_id']],
            ['user_id' => $user->id, 'email' => $pending['email']],
        );
        Cache::forget("oauth_pending:{$token}");

        $this->audit->log('SECURITY.PROVIDER_LINKED', $user, ucfirst($pending['provider']), 'warning', actor: $user);
        $notifications->notify($user, 'security.provider_linked', ['provider' => ucfirst($pending['provider'])], 'security.provider_linked:'.$user->id.':'.$pending['provider']);

        return $this->signIn($user, $request);
    }

    private function signIn(User $user, Request $request): JsonResponse
    {
        if ($user->status === UserStatus::Suspended || $user->isStaff()) {
            throw ApiException::forbidden('This account cannot sign in here.');
        }
        if ($user->status === UserStatus::Deactivated) {
            $user->forceFill(['status' => UserStatus::Active, 'deactivated_at' => null])->save();
        }

        if ($user->hasTwoFactorEnabled() && ! $this->twoFactor->isTrustedDevice($user, $request)) {
            return ApiResponse::ok([
                'status' => 'two_factor_required',
                'challenge_id' => $this->twoFactor->createChallenge($user),
                'methods' => $this->twoFactor->methodsFor($user),
                'sms_hint' => $user->phone ? '•••• '.substr($user->phone, -4) : null,
            ]);
        }

        $tokenFields = $this->sessions->start($user, $request);
        $this->audit->log('AUTH.SIGNED_IN', $user, 'Social sign-in', actor: $user);

        return ApiResponse::ok(array_merge(['status' => 'authenticated', 'user' => new AuthUserResource($user->fresh())], $tokenFields));
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ApiException::notFound('Unsupported sign-in provider.');
        }
        if (empty(config("services.{$provider}.client_id")) || empty(config("services.{$provider}.client_secret"))) {
            throw ApiException::gatewayUnavailable(ucfirst($provider).' sign-in is not available right now.');
        }
    }

    private function driver(string $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider);
        if (! $driver instanceof AbstractProvider) {
            throw ApiException::notFound('Sign-in provider not supported.');
        }

        return $driver;
    }
}
