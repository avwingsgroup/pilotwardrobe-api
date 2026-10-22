<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every authenticated request:
 *  - suspended accounts lose access immediately (sessions are also revoked at suspension time);
 *  - revoked or expired device sessions are rejected (401);
 *  - staff sessions expire after 30 minutes idle;
 *  - impersonation sessions are read-only.
 */
final class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if ($user->status === UserStatus::Suspended || $user->trashed()) {
            $this->endSession($request);

            throw ApiException::forbidden('Your account is suspended. Contact support for help.');
        }

        $session = $this->currentAuthSession($request);
        if ($session !== null) {
            $idleLimit = $session->is_staff ? (int) config('platform.auth.staff_idle_minutes') : null;
            $expired = $session->revoked_at !== null
                || ($session->expires_at !== null && $session->expires_at->isPast())
                || ($idleLimit !== null && $session->last_active_at !== null && $session->last_active_at->lt(now()->subMinutes($idleLimit)));

            if ($expired) {
                $this->endSession($request);

                throw new ApiException(ErrorCode::UNAUTHENTICATED, 'Your session has ended. Please sign in again.');
            }

            if ($session->is_impersonation && ! $request->isMethodSafe()) {
                throw ApiException::forbidden('Impersonation sessions are read-only.');
            }

            // Throttle the activity write to once a minute.
            if ($session->last_active_at === null || $session->last_active_at->lt(now()->subMinute())) {
                $session->forceFill(['last_active_at' => now(), 'ip_address' => $request->ip()])->saveQuietly();
            }
        }

        $request->attributes->set('auth_session', $session);

        return $next($request);
    }

    private function currentAuthSession(Request $request): ?AuthSession
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return is_int($token->getKey()) ? AuthSession::query()->where('token_id', $token->getKey())->first() : null;
        }

        if ($request->hasSession()) {
            $id = $request->session()->get('auth_session_id');

            return $id ? AuthSession::query()->whereKey($id)->where('user_id', $user->id)->first() : null;
        }

        return null;
    }

    private function endSession(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && is_int($token->getKey())) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }
    }
}
