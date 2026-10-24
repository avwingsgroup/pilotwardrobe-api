<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Staff sign-in without Google Workspace: password + authenticator code, set up from the console. */
final class StaffPasswordSignInTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        // Production-like: staff password login on, authenticator app required.
        config(['platform.auth.allow_staff_password_login' => true, 'platform.auth.staff_password_requires_two_factor' => true]);
        $this->app->instance(Google2FA::class, new class extends Google2FA
        {
            public function generateSecretKey($length = 32, $prefix = '')
            {
                return 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
            }
        });
    }

    private function code(): string
    {
        return (new Google2FA)->getCurrentOtp(self::SECRET);
    }

    public function test_console_sets_password_and_pairs_the_authenticator_app(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@pilotwardrobe.com']);

        $this->artisan('staff:credentials', ['email' => 'admin@pilotwardrobe.com'])
            ->expectsQuestion('New password', 'weak')
            ->expectsQuestion('Repeat it', 'weak')
            ->expectsQuestion('New password', 'Cockpit-Check-2026!')
            ->expectsQuestion('Repeat it', 'Cockpit-Check-2026!')
            ->expectsQuestion('Enter the 6-digit code the app now shows', '000000')
            ->expectsQuestion('Enter the 6-digit code the app now shows', $this->code())
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('Cockpit-Check-2026!', $admin->password));
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'STAFF.PASSWORD_SET']);
    }

    public function test_staff_without_an_authenticator_app_cannot_sign_in_with_a_password(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@pilotwardrobe.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@pilotwardrobe.com', 'password' => 'Password1'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'authenticator app'));
    }

    public function test_staff_sign_in_needs_password_and_code_every_time_then_reaches_control_tower(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@pilotwardrobe.com']);
        $admin->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'admin@pilotwardrobe.com', 'password' => 'Password1'])
            ->assertOk()->assertJsonPath('data.status', 'two_factor_required')->json('data.challenge_id');

        $response = $this->postJson('/api/v1/auth/two-factor', ['challenge_id' => $challenge, 'code' => $this->code(), 'method' => 'totp', 'trust_device' => true])
            ->assertOk()->assertJsonPath('data.status', 'authenticated');
        $this->assertNull($response->getCookie('pw_trusted_device', false), 'staff never get a trusted-device cookie');

        $this->withToken($response->json('data.access_token'))->getJson('/api/v1/admin/overview')->assertOk();
    }
}
