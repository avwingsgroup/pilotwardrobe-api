<?php

declare(strict_types=1);

namespace App\Jobs\Admin;

use App\Models\Export;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Streams a whitelisted table to CSV in private storage and emails the requester a
 * 24-hour signed link. Only listed columns are exported (no secrets, no identity data).
 */
final class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public const TABLES = [
        'users' => ['permission' => 'users.view', 'columns' => ['id', 'first_name', 'last_name', 'email', 'username', 'role', 'status', 'country_code', 'sales_count', 'rating_avg', 'created_at']],
        'orders' => ['permission' => 'orders.view', 'columns' => ['id', 'number', 'buyer_id', 'seller_id', 'status', 'escrow_status', 'currency', 'items_amount', 'freight_amount', 'buyer_protection_amount', 'platform_fee_amount', 'total_amount', 'refunded_amount', 'paid_at', 'created_at']],
        'payouts' => ['permission' => 'payouts.view', 'columns' => ['id', 'reference', 'user_id', 'amount', 'currency', 'fee_amount', 'net_amount', 'status', 'provider', 'created_at', 'paid_at']],
        'listings' => ['permission' => 'listings.view', 'columns' => ['id', 'slug', 'title', 'seller_id', 'status', 'currency', 'price_amount', 'views_count', 'sold_count', 'published_at']],
        'disputes' => ['permission' => 'disputes.view', 'columns' => ['id', 'number', 'order_id', 'reason', 'requested', 'status', 'currency', 'frozen_amount', 'amount_to_buyer', 'created_at', 'resolved_at']],
        'audit_logs' => ['permission' => 'audit.view', 'columns' => ['id', 'created_at', 'actor_name', 'action', 'severity', 'target_type', 'target_id', 'target_label', 'detail', 'ip_address']],
    ];

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection(config('queue.long_connection'))->onQueue('long');
    }

    public function handle(NotificationService $notifications): void
    {
        $export = Export::query()->with('requester')->find($this->exportId);
        if ($export === null || $export->status === 'completed') {
            return;
        }

        $definition = self::TABLES[$export->table];
        $path = 'exports/'.$export->id.'.csv';
        $tmp = tmpfile();
        fputcsv($tmp, $definition['columns'], ',', '"', '\\');
        $rows = 0;

        DB::table($export->table)->select($definition['columns'])
            ->when(! empty($export->filters['date_from']), fn ($q) => $q->where('created_at', '>=', $export->filters['date_from']))
            ->when(! empty($export->filters['date_to']), fn ($q) => $q->where('created_at', '<=', $export->filters['date_to']))
            ->when(! empty($export->filters['status']) && in_array('status', $definition['columns'], true), fn ($q) => $q->where('status', $export->filters['status']))
            ->orderBy('id')->chunk(2000, function ($chunk) use ($tmp, &$rows) {
                foreach ($chunk as $row) {
                    // Neutralise spreadsheet formula injection.
                    fputcsv($tmp, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, (array) $row), ',', '"', '\\');
                    $rows++;
                }
            });

        rewind($tmp);
        Storage::disk('local')->put($path, $tmp);
        fclose($tmp);

        $export->forceFill(['status' => 'completed', 'path' => $path, 'rows' => $rows, 'completed_at' => now()])->save();
        $url = URL::temporarySignedRoute('statements.download', now()->addDay(), ['path' => base64_encode($path)]);

        if ($export->requester) {
            $notifications->notify($export->requester, 'staff.export_ready', ['table' => $export->table, 'rows' => (string) $rows, 'url' => $url], "export:{$export->id}");
        }
    }

    public function failed(Throwable $e): void
    {
        Export::query()->whereKey($this->exportId)->update(['status' => 'failed']);
    }
}
