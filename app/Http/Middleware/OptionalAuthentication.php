<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard "optional" (contract legend): resolve the Sanctum user (cookie or bearer) when
 * present so responses can be personalised, but never require it. A suspended or
 * revoked session is simply treated as signed out here.
 */
final class OptionalAuthentication
{
    public function __construct(private readonly EnsureAccountUsable $accountCheck) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($user !== null) {
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);

            try {
                return $this->accountCheck->handle($request, $next);
            } catch (ApiException) {
                Auth::forgetUser();
                $request->setUserResolver(fn () => null);
            }
        }

        return $next($request);
    }
}
