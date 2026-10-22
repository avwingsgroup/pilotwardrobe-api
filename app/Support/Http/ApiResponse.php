<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Builds the contract's success envelopes (§1.4):
 *
 *   { success: true, message?, data }
 *   { success: true, data: [], meta: {current_page, per_page, total, last_page, from, to, …}, links: {…} }
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, ?string $message = null, int $status = 200, array $headers = []): JsonResponse
    {
        $body = ['success' => true];
        if ($message !== null) {
            $body['message'] = $message;
        }
        $body['data'] = self::resolve($data);

        return response()->json($body, $status, $headers);
    }

    public static function created(mixed $data = null, ?string $message = 'Created successfully.'): JsonResponse
    {
        return self::ok($data, $message, 201);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  class-string<JsonResource>|callable|null  $transform  resource class or per-item mapper
     * @param  array<string, mixed>  $extraMeta  e.g. `counts` (tab badges) or `facets`
     */
    public static function paginated(LengthAwarePaginator $paginator, string|callable|null $transform = null, array $extraMeta = [], ?string $message = null): JsonResponse
    {
        $items = collect($paginator->items());
        if (is_string($transform)) {
            $data = $transform::collection($items)->resolve(request());
        } elseif (is_callable($transform)) {
            $data = $items->map($transform)->values()->all();
        } else {
            $data = $items->values()->all();
        }

        $body = ['success' => true];
        if ($message !== null) {
            $body['message'] = $message;
        }
        $body['data'] = $data;
        $body['meta'] = array_merge([
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ], $extraMeta);
        $body['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'next' => $paginator->nextPageUrl(),
            'prev' => $paginator->previousPageUrl(),
        ];

        return response()->json($body);
    }

    private static function resolve(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        return $data;
    }
}
