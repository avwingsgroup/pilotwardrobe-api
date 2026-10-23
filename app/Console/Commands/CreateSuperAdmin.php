<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UsernameGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstraps the first Control Tower super admin on a fresh install. Staff sign in with
 * Google Workspace + a security key (registered on first sign-in), so the account gets an
 * unusable random password. Later staff are invited from the Control Tower (`/admin/team`).
 *
 *   php artisan staff:create-super-admin ops@pilotwardrobe.com --first-name=Tolu --last-name=Ade
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'staff:create-super-admin {email} {--first-name=Platform} {--last-name=Owner}';

    protected $description = 'Create the first super admin (signs in with Google Workspace + security key).';

    public function handle(UsernameGenerator $usernames, AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $domain = (string) config('platform.staff_email_domain');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! Str::endsWith($email, '@'.$domain)) {
            $this->error("Use an address on the staff domain ({$domain}); set STAFF_EMAIL_DOMAIN if that is wrong.");

            return self::FAILURE;
        }
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->error('An account with this e-mail already exists; change its role from the Control Tower instead.');

            return self::FAILURE;
        }

        $first = (string) $this->option('first-name');
        $last = (string) $this->option('last-name');
        $user = User::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'username' => $usernames->generate($first, $last),
            'email' => $email,
            'password' => Str::password(48),
            'signup_method' => 'staff_bootstrap',
            'terms_accepted_at' => now(),
        ]);
        $user->forceFill([
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ])->save();

        $audit->log('STAFF.SUPER_ADMIN_BOOTSTRAPPED', $user, 'Created from the console', 'warning');

        $this->info("Super admin {$email} created.");
        $this->line('Sign in at '.config('platform.frontend_url').'/admin/sign-in with that Google Workspace account; the first sign-in registers your security key.');

        return self::SUCCESS;
    }
}
