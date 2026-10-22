<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every `/admin/*` route: a staff account holding `admin.access`. Specific permissions are checked per route. */
final class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isStaff() || ! $user->hasPermission('admin.access')) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
