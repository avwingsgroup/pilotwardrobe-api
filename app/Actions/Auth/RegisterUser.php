<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\EmailVerification;
use App\Services\Auth\UsernameGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates a member account (VA4). `account_type=aviator` does not grant the aviator
 * role: the role is assigned only once licence credentials are approved
 * (see docs/backend-implementation-notes.md, decision D3).
 */
final class RegisterUser
{
    public function __construct(
        private readonly UsernameGenerator $usernames,
        private readonly EmailVerification $verification,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function handle(array $data, string $signupMethod = 'email', bool $emailVerified = false): User
    {
        return DB::transaction(function () use ($data, $signupMethod, $emailVerified) {
            $user = new User([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'username' => $data['username'] ?? $this->usernames->generate($data['first_name'], $data['last_name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'currency' => $this->defaultCurrency($data['country_code'] ?? null),
                'signup_method' => $signupMethod,
                'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
                'terms_accepted_at' => now(),
            ]);
            if ($emailVerified) {
                $user->email_verified_at = now();
            }
            if (isset($data['password'])) {
                $user->password_changed_at = now();
            }
            $user->save();

            if (! empty($data['invite_token'])) {
                Invitation::query()->where('token_hash', hash('sha256', (string) $data['invite_token']))
                    ->whereNull('accepted_at')->where('expires_at', '>', now())
                    ->update(['accepted_at' => now()]);
            }

            $this->audit->log('USER.REGISTERED', $user, "Signed up via {$signupMethod}", 'success', actor: $user);

            if (! $emailVerified) {
                $this->verification->send($user);
            }

            return $user;
        });
    }

    private function defaultCurrency(?string $country): string
    {
        return match (strtoupper((string) $country)) {
            'GB' => 'GBP',
            'DE', 'FR', 'NL', 'IE', 'ES', 'IT' => 'EUR',
            default => 'USD', // NG/GH sellers price in USD by default (settlement currency)
        };
    }
}
