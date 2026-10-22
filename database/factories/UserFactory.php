<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\KycVerification;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'first_name' => $first,
            'last_name' => $last,
            'username' => Str::lower(Str::ascii($first.'_'.$last)).'_'.fake()->unique()->numberBetween(100, 999999),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+23480'.fake()->numerify('########'),
            'password' => 'Password1',
            'country_code' => 'NG',
            'currency' => 'USD',
            'locale' => 'en',
            'signup_method' => 'email',
            'terms_accepted_at' => now(),
            'city' => 'Lagos',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->email_verified_at ??= now();
            $user->password_changed_at ??= now();
        });
    }

    public function unverified(): static
    {
        return $this->afterMaking(fn (User $user) => $user->email_verified_at = null)
            ->afterCreating(fn (User $user) => $user->forceFill(['email_verified_at' => null])->save());
    }

    public function role(UserRole $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->forceFill(['role' => $role])->save());
    }

    public function aviator(): static
    {
        return $this->role(UserRole::Aviator);
    }

    public function status(UserStatus $status): static
    {
        return $this->afterCreating(fn (User $user) => $user->forceFill(['status' => $status])->save());
    }

    public function suspended(): static
    {
        return $this->status(UserStatus::Suspended);
    }

    public function kycApproved(): static
    {
        return $this->afterCreating(function (User $user) {
            KycVerification::query()->updateOrCreate(['user_id' => $user->id], [
                'code' => 'KY-'.fake()->unique()->numerify('######'),
                'status' => KycStatus::Approved,
                'step' => 'done',
                'document_type' => 'passport',
                'submitted_at' => now()->subDays(10),
                'reviewed_at' => now()->subDays(9),
            ]);
        });
    }

    public function officialStore(): static
    {
        return $this->state(['seller_type' => 'official_store']);
    }

    /** Staff member holding a staff-role bundle (`trust_safety`, `finance`, …). */
    public function staff(string $staffRoleSlug = 'trust_safety'): static
    {
        return $this->afterCreating(function (User $user) use ($staffRoleSlug) {
            $definition = config("permissions.staff_roles.{$staffRoleSlug}");
            $role = StaffRole::query()->firstOrCreate(['slug' => $staffRoleSlug], [
                'name' => $definition['name'] ?? Str::headline($staffRoleSlug),
                'is_system' => (bool) ($definition['system'] ?? false),
                'matrix' => is_array($definition['matrix'] ?? null) ? $definition['matrix'] : array_fill_keys(array_keys(config('permissions.matrix')), 'manage'),
            ]);
            $user->forceFill(['role' => UserRole::Admin, 'staff_role_id' => $role->id])->save();
        });
    }

    public function superAdmin(): static
    {
        return $this->role(UserRole::SuperAdmin);
    }
}
