<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * `GET /health` — readiness probe for load balancers and uptime monitors. Checks only
 * the dependencies the API cannot serve without (database, cache/Redis, queue); third-
 * party providers are deliberately excluded so a PSP outage does not take nodes out.
 */
final class HealthController
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        $results = $checks->run();
        $healthy = ! in_array('down', array_column($results, 'status'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $results,
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
