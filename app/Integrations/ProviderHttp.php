<?php

declare(strict_types=1);

namespace App\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP client conventions for provider adapters: short timeouts, request-id
 * propagation, and one place that turns failures into classified ProviderExceptions.
 *
 * Retries are deliberately NOT automatic for writes: a timed-out charge or transfer
 * must be reconciled by reading state back, not by blindly re-sending it.
 */
final class ProviderHttp
{
    public static function client(string $provider, int $timeout = 15): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(5)
            ->acceptJson()
            ->withHeaders(array_filter(['X-Request-Id' => Context::get('request_id')]))
            ->withUserAgent('Pilotwardrobe-API/1.0');
    }

    /** Safe-to-repeat reads: retried twice on connection failures and 5xx. */
    public static function readClient(string $provider, int $timeout = 15): PendingRequest
    {
        return self::client($provider, $timeout)->retry(2, 300, function ($exception) {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError());
        }, throw: false);
    }

    /**
     * @param  callable(): Response  $call
     */
    public static function send(string $provider, string $operation, callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('provider.connection_failed', ['provider' => $provider, 'operation' => $operation]);

            throw ProviderException::transient($provider, "{$operation}: connection failed", $e);
        }

        if ($response->failed()) {
            Log::warning('provider.request_failed', [
                'provider' => $provider,
                'operation' => $operation,
                'status' => $response->status(),
            ]);

            throw ProviderException::fromStatus($provider, $response->status(), "{$operation}: ".mb_substr($response->body(), 0, 300));
        }

        return $response;
    }
}
