<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional `Idempotency-Key` support for critical POSTs (§1.10).
 *
 *  - Same key + same body → the original response is replayed (`Idempotent-Replayed: true`).
 *  - Same key + different body → 409 CONFLICT.
 *  - Same key while the first request is still running → 409 CONFLICT.
 *
 * Keys are scoped to the user and route and kept for 24 hours. 5xx and 429 responses
 * are not stored, so a client may retry those with the same key.
 */
final class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($key === '' || $request->isMethodSafe()) {
            return $next($request);
        }
        if (strlen($key) > 120 || ! preg_match('/^[A-Za-z0-9\-_:.]+$/', $key)) {
            throw ApiException::validation(['idempotency_key' => 'The Idempotency-Key header is invalid.']);
        }

        $userId = $request->user()?->id;
        $route = $request->route()?->getName() ?? $request->path();
        $hash = hash('sha256', $request->getContent());

        $lock = Cache::lock("idem:{$userId}:{$route}:{$key}", 30);
        if (! $lock->get()) {
            throw ApiException::conflict('A request with this Idempotency-Key is already in progress.');
        }

        try {
            $existing = IdempotencyKey::query()
                ->where(['user_id' => $userId, 'key' => $key, 'route' => $route])
                ->where('expires_at', '>', now())
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ApiException::conflict('This Idempotency-Key was already used with a different request.');
                }
                if ($existing->completed_at !== null) {
                    return (new JsonResponse($existing->response_body, $existing->response_status))
                        ->header('Idempotent-Replayed', 'true');
                }
            }

            $record = $existing ?? $this->reserve($userId, $key, $route, $hash);

            $response = $next($request);

            $status = $response->getStatusCode();
            if ($status < 500 && $status !== 429 && $response instanceof JsonResponse) {
                $record->forceFill([
                    'response_status' => $status,
                    'response_body' => $response->getData(true),
                    'completed_at' => now(),
                ])->save();
            } else {
                $record->delete();
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function reserve(?string $userId, string $key, string $route, string $hash): IdempotencyKey
    {
        IdempotencyKey::query()->where(['user_id' => $userId, 'key' => $key, 'route' => $route])
            ->where('expires_at', '<=', now())->delete();

        try {
            return IdempotencyKey::query()->create([
                'user_id' => $userId,
                'key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'expires_at' => now()->addDay(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('A request with this Idempotency-Key is already in progress.');
        }
    }
}
