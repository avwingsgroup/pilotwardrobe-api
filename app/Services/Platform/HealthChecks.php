<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/** Dependency checks shared by `/health` and the status-history recorder. */
final class HealthChecks
{
    /** @return array<string, array{status: 'up'|'degraded'|'down', latency_ms?: int, detail?: string}> */
    public function run(): array
    {
        return [
            'database' => $this->time(fn () => DB::select('select 1')),
            'cache' => $this->time(function () {
                // A string token: Redis returns stored numbers as strings ("1"), so an int would never match.
                $key = 'health:'.Str::random(8);
                $token = Str::random(16);
                Cache::put($key, $token, 10);
                if (Cache::pull($key) !== $token) {
                    throw new \RuntimeException('cache round-trip failed');
                }
            }),
            'queue' => $this->queue(),
        ];
    }

    private function queue(): array
    {
        try {
            $size = Queue::size('default') + Queue::size('notifications') + Queue::size('payments');
            $status = $size > 5000 ? 'degraded' : 'up';

            return ['status' => $status, 'detail' => $status === 'up' ? 'ok' : 'backlog high'];
        } catch (Throwable) {
            return ['status' => 'down', 'detail' => 'queue unreachable'];
        }
    }

    private function time(callable $check): array
    {
        $start = hrtime(true);
        try {
            $check();

            return ['status' => 'up', 'latency_ms' => (int) ((hrtime(true) - $start) / 1_000_000)];
        } catch (Throwable) {
            return ['status' => 'down', 'detail' => 'unreachable'];
        }
    }
}
