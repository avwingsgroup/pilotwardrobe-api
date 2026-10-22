<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `perm:a,b` — the caller must hold **all** listed permissions (contract guard "perm: x").
 * `perm:a|b` — the caller must hold at least one of the alternatives.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        foreach ($permissions as $permission) {
            $alternatives = explode('|', $permission);
            $held = array_filter($alternatives, fn (string $p) => $user->hasPermission($p));
            if ($held === []) {
                throw ApiException::forbidden();
            }
        }

        return $next($request);
    }
}
