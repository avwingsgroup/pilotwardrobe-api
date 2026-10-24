<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Auth\OneTimeCodes;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    public function test_register_creates_unverified_user_and_queues_otp_email(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'Ada.Obi@example.com',
            'phone' => '+234 803 000 0000',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'country_code' => 'NG',
            'account_type' => 'user',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada.obi@example.com')
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonPath('data.user.verification.email', false)
            ->assertJsonPath('data.user.verification.kyc_status', 'not_started')
            ->assertJsonPath('data.verification.expires_in', 600)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertContains('listings.create', $response->json('data.user.permissions'));
        $this->assertDatabaseHas('notification_deliveries', ['template' => 'auth.verify_email', 'channel' => 'email', 'status' => 'sent']);
        // The code must not stay readable in the database once the e-mail is sent.
        $this->assertSame(['redacted' => true], NotificationDelivery::query()->where('template', 'auth.verify_email')->firstOrFail()->payload);
    }

    public function test_register_validation_uses_contract_messages(): void
    {
        User::factory()->create(['email' => 'pilot@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => '',
            'last_name' => 'Obi',
            'email' => 'PILOT@example.com',
            'password' => 'short',
            'password_confirmation' => 'x',
            'country_code' => 'NG',
            'terms_accepted' => false,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.email.0', 'An account with this email already exists.')
            ->assertJsonPath('errors.password_confirmation.0', "Passwords don't match.")
            ->assertJsonPath('errors.terms_accepted.0', 'You must accept the terms.')
            ->assertJsonPath('errors.password.0', 'The password must be at least 8 characters.')
            ->assertJsonStructure(['errors' => ['first_name']]);
    }

    public function test_email_otp_verification(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new@example.com']);
        $code = DB::transaction(fn () => app(OneTimeCodes::class)->issue($user, 'email_verification', 4, 600));

        $this->postJson('/api/v1/auth/email/verify', ['email' => 'new@example.com', 'code' => $code === '0000' ? '0001' : '0000'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);

        $this->postJson('/api/v1/auth/email/verify', ['email' => 'new@example.com', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.verification.email', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'auth.welcome']);
    }

    public function test_verify_email_for_unknown_account_is_404(): void
    {
        $this->assertErrorEnvelope($this->postJson('/api/v1/auth/email/verify', ['email' => 'ghost@example.com', 'code' => '1234']), 404, 'NOT_FOUND');
    }

    public function test_login_returns_token_and_auth_user(): void
    {
        $user = User::factory()->create(['email' => 'pilot@example.com']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'Password1', 'remember' => true]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user' => ['permissions', 'counters' => ['unread_messages', 'cart_items']]]]);

        $token = $response->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'pilot@example.com');
        $this->assertSame(1, AuthSession::query()->where('user_id', $user->id)->count());
    }

    public function test_wrong_password_reports_attempts_then_locks_with_423(): void
    {
        User::factory()->create(['email' => 'pilot@example.com']);

        $first = $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'nope']);
        $first->assertStatus(422)
            ->assertJsonPath('message', 'Incorrect email or password.')
            ->assertJsonPath('errors.email.0', 'Incorrect email or password.')
            ->assertJsonPath('meta.attempts_remaining', 4);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'nope'])->assertStatus(422);
        }

        $locked = $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'nope']);
        $this->assertErrorEnvelope($locked, 423, 'ACCOUNT_LOCKED');
        $this->assertGreaterThan(0, $locked->json('meta.retry_after'));

        // Even the right password is refused while locked.
        $this->assertErrorEnvelope($this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'Password1']), 423, 'ACCOUNT_LOCKED');
    }

    public function test_unknown_email_gets_same_error_as_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'Password1'])
            ->assertStatus(422)->assertJsonPath('message', 'Incorrect email or password.');
    }

    public function test_suspended_account_cannot_sign_in_and_loses_existing_access(): void
    {
        $user = User::factory()->suspended()->create(['email' => 'bad@example.com']);

        $this->assertErrorEnvelope($this->postJson('/api/v1/auth/login', ['email' => 'bad@example.com', 'password' => 'Password1']), 403, 'FORBIDDEN');

        $this->actingAsUser($user);
        $this->assertErrorEnvelope($this->getJson('/api/v1/me/security'), 403, 'FORBIDDEN');
    }

    public function test_two_factor_challenge_flow(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create(['email' => 'aviator@example.com']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'aviator@example.com', 'password' => 'Password1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'two_factor_required')
            ->assertJsonPath('data.methods.0', 'totp')
            ->json('data.challenge_id');

        $this->postJson('/api/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => '000000', 'method' => 'totp'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);

        $this->postJson('/api/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => $google2fa->getCurrentOtp($secret), 'method' => 'totp'])
            ->assertOk()->assertJsonPath('data.status', 'authenticated');

        // Challenge is single use.
        $this->assertErrorEnvelope($this->postJson('/api/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => '123456']), 419, 'SESSION_EXPIRED');
    }

    public function test_me_is_401_when_signed_out(): void
    {
        $this->assertErrorEnvelope($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_forgot_password_never_reveals_account_existence(): void
    {
        User::factory()->create(['email' => 'pilot@example.com']);

        $a = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'pilot@example.com'])->assertOk();
        $b = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();
        $this->assertSame($a->json('message'), $b->json('message'));
        $this->assertSame(1, NotificationDelivery::query()->where('template', 'auth.password_reset')->count());
    }

    public function test_password_reset_with_expired_token_is_410(): void
    {
        $this->assertErrorEnvelope($this->postJson('/api/v1/auth/password/reset', [
            'token' => 'expired-token', 'email' => 'pilot@example.com',
            'password' => 'Password2!', 'password_confirmation' => 'Password2!',
        ]), 410, 'TOKEN_EXPIRED');

        $this->assertErrorEnvelope($this->getJson('/api/v1/auth/password/reset/unknown-token'), 410, 'TOKEN_EXPIRED');
    }

    public function test_password_reset_round_trip_revokes_sessions(): void
    {
        $user = User::factory()->create(['email' => 'pilot@example.com']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'Password1'])->json('data.access_token');

        $token = 'tok_'.str_repeat('x', 60);
        DB::table('password_reset_tokens')->insert(['email' => 'pilot@example.com', 'token' => hash('sha256', $token), 'created_at' => now()]);

        $this->getJson("/api/v1/auth/password/reset/{$token}")->assertOk()->assertJsonPath('data.email', 'pilot@example.com');

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token, 'email' => 'pilot@example.com',
            'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9', 'logout_other_devices' => true,
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertErrorEnvelope($this->withToken($login)->getJson('/api/v1/me/security'), 401, 'UNAUTHENTICATED');
        $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'NewPassword9'])->assertOk();
        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_logout_revokes_token(): void
    {
        User::factory()->create(['email' => 'pilot@example.com']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'pilot@example.com', 'password' => 'Password1'])->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->assertErrorEnvelope($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_staff_cannot_use_member_login(): void
    {
        User::factory()->staff()->create(['email' => 'admin@pilotwardrobe.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@pilotwardrobe.com', 'password' => 'Password1'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Staff accounts sign in through the Control Tower.');
        $this->postJson('/api/v1/auth/email-lookup', ['email' => 'admin@pilotwardrobe.com'])
            ->assertOk()->assertJsonPath('data.exists', false);
    }

    public function test_staff_password_login_when_enabled_for_development(): void
    {
        config(['platform.auth.allow_staff_password_login' => true]);
        User::factory()->superAdmin()->create(['email' => 'super@example.com']);

        $this->postJson('/api/v1/auth/email-lookup', ['email' => 'super@example.com'])
            ->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.methods', ['password']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'super@example.com', 'password' => 'Password1'])
            ->assertOk()->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/admin/overview')->assertOk();
    }

    public function test_email_lookup(): void
    {
        User::factory()->create(['email' => 'pilot@example.com']);

        $this->postJson('/api/v1/auth/email-lookup', ['email' => 'pilot@example.com'])
            ->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.methods', ['password']);
        $this->postJson('/api/v1/auth/email-lookup', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.methods', []);
    }

    public function test_request_id_is_echoed(): void
    {
        $this->withHeaders(['X-Request-Id' => 'b7c1a2d4-1111-2222-3333-444455556666'])
            ->getJson('/api/v1/auth/me')
            ->assertHeader('X-Request-Id', 'b7c1a2d4-1111-2222-3333-444455556666');
    }
}
