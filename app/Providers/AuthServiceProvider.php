<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Permission strings from config/permissions.php (`users.suspend`, …) work directly as
 * gate abilities (`can:users.suspend`, `$user->can('kyc.review')`). Ownership is enforced
 * where records are loaded: queries are scoped to the caller (`findForParty`, `where('seller_id', …)`)
 * so other users' records are a 404, and services re-check the actor before any state change.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $catalogue = array_flip(config('permissions.all'));

        Gate::before(function (User $user, string $ability) use ($catalogue) {
            if (isset($catalogue[$ability])) {
                return $user->hasPermission($ability);
            }

            return null; // fall through to policies
        });

        // OpenAPI docs (/api/documentation, /openapi): always open locally (Scramble's own check);
        // elsewhere public only when API_DOCS_PUBLIC=true, otherwise signed-in staff.
        Gate::define('viewApiDocs', fn (?User $user) => (bool) config('platform.api_docs_public') || ($user?->isStaff() ?? false));
    }
}
