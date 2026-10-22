<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StatusCheck;
use App\Services\Platform\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Records one health sample per component (runs every 5 minutes). The Help Center
 * status panel (VC10) is built only from these samples — never hard-coded.
 */
final class RecordStatusChecks extends Command
{
    protected $signature = 'status:record';

    protected $description = 'Record a health sample for the public status panel.';

    public function handle(HealthChecks $checks): int
    {
        $core = $checks->run();
        $coreUp = ! in_array('down', array_column($core, 'status'), true);

        $since = now()->subMinutes(15);
        $webhookFailures = DB::table('webhook_events')->where('created_at', '>=', $since)->where('status', 'failed')->count();
        $webhookTotal = DB::table('webhook_events')->where('created_at', '>=', $since)->count();
        $deliveryFailures = DB::table('notification_deliveries')->where('created_at', '>=', $since)->where('status', 'failed')->count();
        $deliveryTotal = DB::table('notification_deliveries')->where('created_at', '>=', $since)->count();

        $state = fn (bool $up, int $fail = 0, int $total = 0) => ! $up ? 'down' : ($total > 10 && $fail / $total > 0.1 ? 'degraded' : 'up');

        $samples = [
            'Marketplace' => $state($coreUp),
            'Payments' => $state($coreUp, $webhookFailures, $webhookTotal),
            'Messaging' => $state($coreUp && ($core['queue']['status'] ?? 'up') !== 'down'),
            'Notifications' => $state($coreUp, $deliveryFailures, $deliveryTotal),
        ];

        $rank = ['up' => 0, 'degraded' => 1, 'down' => 2];
        foreach ($samples as $component => $sample) {
            $row = StatusCheck::query()->firstOrNew(['component' => $component, 'day' => now()->toDateString()]);
            $row->checks = (int) $row->checks + 1;
            $row->failures = (int) $row->failures + ($sample === 'up' ? 0 : 1);
            // The day shows the worst state seen.
            $row->state = ($rank[$sample] > ($rank[$row->state ?? 'up'] ?? 0)) ? $sample : ($row->state ?? 'up');
            $row->save();
        }

        return self::SUCCESS;
    }
}
