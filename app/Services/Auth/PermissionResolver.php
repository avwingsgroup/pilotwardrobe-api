<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Computes a user's effective permissions (contract §2):
 *
 *   role defaults ∪ staff-role bundle ∪ grants − revocations
 *
 * Results are cached per user and `permissions_version`, so bumping the version
 * (role change, grant, staff-role edit) invalidates every node's cache at once.
 */
final class PermissionResolver
{
    /** @var array<string, list<string>> per-request memo */
    private array $memo = [];

    public function __construct(private readonly Cache $cache) {}

    /** @return list<string> */
    public function for(User $user): array
    {
        $key = $this->cacheKey($user);

        return $this->memo[$key] ??= $this->cache->remember($key, now()->addHour(), fn () => $this->compute($user));
    }

    public function forget(User $user): void
    {
        $this->memo = [];
        $this->cache->forget($this->cacheKey($user));
    }

    /** @return list<string> */
    public function compute(User $user): array
    {
        $all = config('permissions.all');

        if ($user->role === UserRole::SuperAdmin) {
            return $all;
        }

        $permissions = config('permissions.roles.'.$user->role->value, []);

        if ($user->role === UserRole::Admin && $user->staff_role_id) {
            $role = $user->staffRole()->first();
            if ($role) {
                $permissions = [...$permissions, ...self::fromMatrix($role->matrix ?? [])];
            }
        }

        $overrides = $user->permissionOverrides()->get(['permission', 'effect']);
        foreach ($overrides as $override) {
            if ($override->effect === 'grant') {
                $permissions[] = $override->permission;
            }
        }
        $revoked = $overrides->where('effect', 'revoke')->pluck('permission')->all();

        $effective = array_values(array_diff(array_unique($permissions), $revoked));

        // Only ever return known permissions, in catalogue order.
        return array_values(array_intersect($all, $effective));
    }

    /**
     * Expands an AD18 matrix (`{ area: none|view|manage }`) or the shorthand `'manage'` into permissions.
     *
     * @param  array<string, string>|string  $matrix
     * @return list<string>
     */
    public static function fromMatrix(array|string $matrix): array
    {
        $definitions = config('permissions.matrix');
        if (is_string($matrix)) {
            $matrix = array_fill_keys(array_keys($definitions), $matrix);
        }

        $permissions = [];
        foreach ($matrix as $area => $level) {
            if (isset($definitions[$area][$level])) {
                $permissions = [...$permissions, ...$definitions[$area][$level]];
            }
        }

        return array_values(array_unique($permissions));
    }

    private function cacheKey(User $user): string
    {
        return "perm:{$user->id}:v{$user->permissions_version}:{$user->role->value}:".($user->staff_role_id ?? '-');
    }
}
