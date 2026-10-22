<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\MaintenanceWindow;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves 503 MAINTENANCE during a scheduled window (AD20). Staff, auth, webhooks and the
 * health check keep working so the platform can be operated and payments reconciled.
 */
final class CheckMaintenanceWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/webhooks/*', 'api/v1/admin/*', 'api/v1/auth/*', 'health', 'up')) {
            return $next($request);
        }

        // Scalars only in the cache (object unserialisation is disabled).
        $window = Cache::remember('maintenance:active', 30, function () {
            $active = MaintenanceWindow::query()->whereNull('cancelled_at')
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();

            return $active ? ['ends_at' => $active->ends_at->toIso8601String(), 'message' => $active->message] : null;
        });

        if ($window !== null && ! $request->user()?->isStaff()) {
            $endsAt = Carbon::parse($window['ends_at']);

            throw new ApiException(
                ErrorCode::MAINTENANCE,
                $window['message'] ?: 'Pilotwardrobe is undergoing scheduled maintenance. Please check back shortly.',
                meta: ['ends_at' => $endsAt->toIso8601ZuluString()],
                headers: ['Retry-After' => (string) max(60, (int) now()->diffInSeconds($endsAt, false))],
            );
        }

        return $next($request);
    }
}
