<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckMaintenanceWindow;
use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\OptionalAuthentication;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Gateway/provider callbacks: signature-verified, no session or CSRF.
            Route::prefix('api/v1/webhooks')->middleware('throttle:webhooks')->group(base_path('routes/webhooks.php'));
            // Operational endpoints (`/health`).
            Route::middleware('throttle:health')->group(base_path('routes/system.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // Sanctum SPA cookie auth for first-party origins; bearer tokens work everywhere.
        $middleware->statefulApi();

        $middleware->api(append: [
            SetRequestLocale::class,
            CheckMaintenanceWindow::class,
        ]);

        $middleware->alias([
            'staff' => EnsureStaff::class,
            'perm' => RequirePermission::class,
            'idempotent' => IdempotentRequest::class,
            'account' => EnsureAccountUsable::class,
            'auth.optional' => OptionalAuthentication::class,
        ]);

        // Account state is checked only after Sanctum has resolved the user.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
            EnsureAccountUsable::class,
        ]);

        // API clients never get redirected to a login page.
        $middleware->redirectGuestsTo(fn () => null);

        // Trusted proxies come from config('platform.trusted_proxies'), applied in AppServiceProvider:
        // env() here would be ignored once config is cached in production.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
