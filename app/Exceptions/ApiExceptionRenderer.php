<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps every exception to the contract's error envelope (§1.5) for API requests.
 * Messages are user-facing: no stack traces, SQL, paths or gateway responses unless APP_DEBUG.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => self::isApi($request));

        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! self::isApi($request)) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function isApi(Request $request): bool
    {
        return $request->is('api/*', 'health', 'sanctum/*') || $request->expectsJson();
    }

    public static function toResponse(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $e->render(),

            $e instanceof ValidationException => self::envelope(
                ErrorCode::VALIDATION_ERROR,
                'The submitted data is invalid.',
                errors: $e->errors(),
                status: 422,
            ),

            $e instanceof AuthenticationException => self::envelope(ErrorCode::UNAUTHENTICATED, 'Unauthenticated.'),

            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::envelope(
                ErrorCode::FORBIDDEN,
                'This action is unauthorized.',
            ),

            $e instanceof ModelNotFoundException => self::envelope(
                ErrorCode::NOT_FOUND,
                Str::headline(class_basename($e->getModel())).' not found.',
            ),

            $e instanceof NotFoundHttpException => self::envelope(
                ErrorCode::NOT_FOUND,
                $e->getPrevious() instanceof ModelNotFoundException
                    ? Str::headline(class_basename($e->getPrevious()->getModel())).' not found.'
                    : 'Not found.',
            ),

            $e instanceof TokenMismatchException => self::envelope(ErrorCode::SESSION_EXPIRED, 'Your session has expired. Please sign in again.'),

            $e instanceof ThrottleRequestsException => self::envelope(
                ErrorCode::RATE_LIMITED,
                'Too many requests. Please slow down.',
                meta: ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)],
                headers: $e->getHeaders(),
            ),

            $e instanceof MethodNotAllowedHttpException => self::envelope(ErrorCode::UNKNOWN, 'Method not allowed.', status: 405, headers: $e->getHeaders()),

            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => self::envelope(
                ErrorCode::MAINTENANCE,
                'Pilotwardrobe is undergoing maintenance. Please check back shortly.',
                headers: $e->getHeaders(),
            ),

            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => self::envelope(ErrorCode::SESSION_EXPIRED, 'Your session has expired. Please sign in again.'),

            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => self::envelope(
                ErrorCode::UNKNOWN,
                $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be processed.',
                status: $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),

            default => self::serverError($e),
        };
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $meta = ['request_id' => Context::get('request_id')];
        if (config('app.debug')) {
            $meta['exception'] = get_class($e);
            $meta['debug_message'] = $e->getMessage();
            $meta['file'] = $e->getFile().':'.$e->getLine();
        }

        return self::envelope(ErrorCode::SERVER_ERROR, 'Something went wrong on our side. Please try again.', meta: $meta);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    private static function envelope(ErrorCode $code, string $message, ?array $errors = null, ?array $meta = null, ?int $status = null, array $headers = []): JsonResponse
    {
        return (new ApiException($code, $message, $errors, $meta, $status, $headers))->render();
    }
}
