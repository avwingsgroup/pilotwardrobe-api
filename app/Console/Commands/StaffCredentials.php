<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a staff member's password and pairs their authenticator app, for teams without Google
 * Workspace. With STAFF_PASSWORD_LOGIN=true, staff then sign in on the normal login page with
 * password + 6-digit code (required for staff in production, every time).
 *
 *   php artisan staff:credentials admin@pilotwardrobe.com
 */
final class StaffCredentials extends Command
{
    protected $signature = 'staff:credentials {email} {--no-password : Only set up the authenticator app} {--no-two-factor : Only set the password}';

    protected $description = "Set a staff member's password and authenticator app (password + code sign-in).";

    public function handle(TwoFactorService $twoFactor, AuditLogger $audit): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])->first();
        if ($user === null || ! $user->isStaff()) {
            $this->error('No staff account with that e-mail. Create one with staff:create-super-admin or invite them from Control Tower → Team.');

            return self::FAILURE;
        }

        if (! $this->option('no-password') && ! $this->setPassword($user, $audit)) {
            return self::FAILURE;
        }
        if (! $this->option('no-two-factor') && ! $this->setUpAuthenticator($user, $twoFactor, $audit)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Done. {$user->email} can sign in with password + authenticator code once STAFF_PASSWORD_LOGIN=true.");

        return self::SUCCESS;
    }

    private function setPassword(User $user, AuditLogger $audit): bool
    {
        $this->line('Choose a password: at least 12 characters, with upper and lower case letters, a number and a symbol.');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $password = (string) $this->secret('New password');
            $confirmation = (string) $this->secret('Repeat it');
            $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
                'password' => ['required', 'confirmed', 'max:128', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ]);
            if ($validator->passes()) {
                $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
                $audit->log('STAFF.PASSWORD_SET', $user, 'Set from the console', 'warning');
                $this->info('Password set.');

                return true;
            }
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }
        }

        return false;
    }

    private function setUpAuthenticator(User $user, TwoFactorService $twoFactor, AuditLogger $audit): bool
    {
        if ($user->hasTwoFactorEnabled() && ! $this->confirm('An authenticator app is already paired. Replace it (the old one stops working)?', false)) {
            $this->line('Kept the existing authenticator app.');

            return true;
        }

        $enrolment = $twoFactor->beginEnrolment($user);
        $this->newLine();
        $this->line('In your authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password…),');
        $this->line('add an account → "Enter a setup key", and type:');
        $this->line('  Account: '.$user->email);
        $this->line('  Key:     <options=bold>'.trim(chunk_split($enrolment['secret'], 4, ' ')).'</>');
        $this->line('  Type:    Time-based');
        $this->line('Or paste this link into an app that accepts otpauth links:');
        $this->line('  '.$enrolment['otpauth_url']);
        $this->newLine();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $code = preg_replace('/\D/', '', (string) $this->ask('Enter the 6-digit code the app now shows'));
            try {
                $backupCodes = $twoFactor->confirmEnrolment($user, (string) $code);
            } catch (ApiException) {
                $this->error('That code did not match. Check the key and that the server and phone clocks are correct.');

                continue;
            }

            $audit->log('SECURITY.TWO_FACTOR_ENABLED', $user, 'Set up from the console', 'success');
            $this->info('Authenticator app paired. Save these one-time backup codes somewhere safe (each works once if you lose your phone):');
            foreach (array_chunk($backupCodes, 5) as $row) {
                $this->line('  '.implode('   ', $row));
            }

            return true;
        }

        $twoFactor->disable($user);
        $this->error('Not paired after 3 attempts, so this account has no authenticator app now. Run the command again.');

        return false;
    }
}
