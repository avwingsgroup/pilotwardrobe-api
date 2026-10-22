<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Jobs\Admin\GenerateExport;
use App\Models\Export;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Control Tower overview (AD2), analytics (AD17) and CSV exports. Figures are USD orders. */
final class AdminInsightsController extends Controller
{
    /** `GET /admin/overview` — KPIs last 30 days vs the previous 30, queues filtered by the viewer's permissions. */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $kpi = Cache::remember('admin:overview:kpis', 120, fn () => $this->kpis());

        $queues = array_values(array_filter([
            ['key' => 'kyc', 'label' => 'KYC reviews', 'count' => DB::table('kyc_verifications')->whereIn('status', ['pending', 'escalated'])->count(),
                'detail' => DB::table('kyc_verifications')->whereIn('status', ['pending', 'escalated'])->where('submitted_at', '<', now()->subDay())->count().' over 24h SLA', 'to' => '/admin/kyc', 'permission' => 'kyc.view'],
            ['key' => 'disputes', 'label' => 'Open disputes', 'count' => DB::table('disputes')->whereIn('status', ['open', 'awaiting_seller', 'under_review', 'appealed'])->count(),
                'detail' => DB::table('disputes')->whereIn('status', ['under_review', 'appealed'])->where('decision_due_at', '<=', now()->endOfDay())->count().' due today', 'to' => '/admin/disputes', 'permission' => 'disputes.view'],
            ['key' => 'moderation', 'label' => 'Listings to review', 'count' => DB::table('listings')->where('status', 'pending_review')->count(), 'detail' => null, 'to' => '/admin/listings', 'permission' => 'listings.view'],
            ['key' => 'reports', 'label' => 'Reports & flags', 'count' => DB::table('reports')->where('status', 'open')->count(),
                'detail' => DB::table('reports')->where('status', 'open')->where('severity', 'critical')->count().' critical', 'to' => '/admin/reports', 'permission' => 'reports.view'],
            ['key' => 'payouts', 'label' => 'Payouts to approve', 'count' => DB::table('payouts')->where('status', 'awaiting_approval')->count(), 'detail' => null, 'to' => '/admin/payouts', 'permission' => 'payouts.view'],
            ['key' => 'approvals', 'label' => 'Changes awaiting approval', 'count' => DB::table('approval_requests')->where('status', 'pending')->count(), 'detail' => null, 'to' => '/admin/approvals', 'permission' => 'admin.access'],
        ], fn ($q) => $user->hasPermission($q['permission'])));

        $latest = $user->hasPermission('orders.view')
            ? Order::query()->with(['items.image', 'buyer', 'seller'])->latest()->limit(6)->get()->map(fn (Order $o) => AdminPresenter::orderRow($o))->values()
            : [];

        $risk = $user->hasPermission('orders.view') ? Order::query()->where('risk', 'high')->where('created_at', '>=', now()->subDays(7))->latest()->limit(5)->get()
            ->map(fn (Order $o) => ['id' => 'rs_'.$o->id, 'title' => implode(' · ', array_slice($o->risk_signals ?? ['High risk'], 0, 2)), 'detail' => '#'.$o->number, 'order_id' => $o->id])->values() : [];

        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        $failedWebhooks = DB::table('webhook_events')->where('status', 'failed')->where('created_at', '>=', now()->subHour())->count();

        return ApiResponse::ok([
            'greeting_name' => $user->first_name,
            'period_label' => 'figures in USD · last 30 days vs previous',
            'kpis' => $kpi['kpis'],
            'series' => $kpi['series'],
            'queues' => $queues,
            'latest_orders' => $latest,
            'risk_signals' => $risk,
            'system_status' => $failedJobs + $failedWebhooks > 10 ? 'degraded' : 'normal',
        ]);
    }

    /** `GET /admin/analytics?period=30d|90d|12m` */
    public function analytics(Request $request): JsonResponse
    {
        $period = in_array($request->query('period'), ['7d', '30d', '90d', '12m'], true) ? $request->query('period') : '30d';
        $since = match ($period) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            '12m' => now()->subMonths(12),
            default => now()->subDays(30),
        };
        $bucket = $period === '12m' ? 'month' : 'day';

        return ApiResponse::ok(Cache::remember("admin:analytics:{$period}", 300, function () use ($since, $bucket, $period) {
            $orders = DB::table('orders')->whereNotNull('paid_at')->where('paid_at', '>=', $since)->where('currency', 'USD');
            $series = (clone $orders)->selectRaw("to_char(date_trunc('{$bucket}', paid_at), 'YYYY-MM-DD') as date, sum(items_amount) as gmv, count(*) as orders, sum(platform_fee_amount + buyer_protection_amount + swap_fee_amount) as revenue")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn ($r) => ['date' => $r->date, 'gmv' => (int) $r->gmv, 'orders' => (int) $r->orders, 'revenue' => (int) $r->revenue]);

            $byCategory = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')->join('listings as l', 'l.id', '=', 'oi.listing_id')
                ->leftJoin('categories as c', 'c.id', '=', 'l.category_id')
                ->whereNotNull('o.paid_at')->where('o.paid_at', '>=', $since)->where('o.currency', 'USD')
                ->selectRaw("coalesce(c.name, 'Uncategorised') as name, sum(oi.total) as gmv, count(*) as items")->groupBy('name')->orderByDesc('gmv')->limit(10)->get();

            $byCountry = DB::table('orders as o')->join('users as b', 'b.id', '=', 'o.buyer_id')->whereNotNull('o.paid_at')->where('o.paid_at', '>=', $since)->where('o.currency', 'USD')
                ->selectRaw("coalesce(b.country_code, '—') as country, sum(o.total_amount) as gmv, count(*) as orders")->groupBy('country')->orderByDesc('gmv')->limit(10)->get();

            $signups = DB::table('users')->where('created_at', '>=', $since)->whereIn('role', ['user', 'aviator'])->count();
            $buyers = (clone $orders)->distinct('buyer_id')->count('buyer_id');
            $listings = DB::table('listings')->where('published_at', '>=', $since)->count();

            return [
                'period' => $period,
                'currency' => 'USD',
                'series' => $series,
                'totals' => [
                    'gmv' => Money::of((int) (clone $orders)->sum('items_amount'), 'USD')->toArray(),
                    'orders' => (clone $orders)->count(),
                    'revenue' => Money::of((int) (clone $orders)->sum(DB::raw('platform_fee_amount + buyer_protection_amount + swap_fee_amount')), 'USD')->toArray(),
                    'average_order' => Money::of((int) (clone $orders)->avg('total_amount'), 'USD')->toArray(),
                    'signups' => $signups,
                    'active_buyers' => $buyers,
                    'new_listings' => $listings,
                    'conversion_rate' => $signups > 0 ? round($buyers / $signups * 100, 1) : null,
                ],
                'by_category' => $byCategory->map(fn ($r) => ['name' => $r->name, 'gmv' => (int) $r->gmv, 'items' => (int) $r->items])->values(),
                'by_country' => $byCountry->map(fn ($r) => ['country_code' => $r->country, 'gmv' => (int) $r->gmv, 'orders' => (int) $r->orders])->values(),
            ];
        }));
    }

    /** `POST /admin/exports` — queues a CSV of a table; the link is emailed when ready. */
    public function export(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'table' => ['required', Rule::in(array_keys(GenerateExport::TABLES))],
            'filters' => ['nullable', 'array'],
        ]);
        $permission = GenerateExport::TABLES[$data['table']]['permission'];
        if (! $request->user()->hasPermission($permission)) {
            abort(403);
        }

        $export = Export::query()->create(['table' => $data['table'], 'filters' => $data['filters'] ?? [], 'status' => 'queued', 'requested_by' => $request->user()->id]);
        GenerateExport::dispatch($export->id);
        $audit->log('EXPORT.CREATED', $export, $data['table'], 'warning');

        return ApiResponse::created(['id' => $export->id, 'status' => 'queued'], 'Export queued. We will email you a link when it is ready.');
    }

    private function kpis(): array
    {
        $now = now();
        $cur = fn () => DB::table('orders')->whereNotNull('paid_at')->where('currency', 'USD')->where('paid_at', '>=', $now->copy()->subDays(30));
        $prev = fn () => DB::table('orders')->whereNotNull('paid_at')->where('currency', 'USD')->whereBetween('paid_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)]);
        $delta = fn (float $a, float $b) => $b > 0 ? sprintf('%+.1f%%', ($a - $b) / $b * 100) : '—';
        $usd = fn (int $minor) => Money::of($minor, 'USD')->format('en');

        $gmv = (int) $cur()->sum('items_amount');
        $gmvPrev = (int) $prev()->sum('items_amount');
        $orders = $cur()->count();
        $ordersPrev = $prev()->count();
        $revenue = (int) $cur()->sum(DB::raw('platform_fee_amount + buyer_protection_amount + swap_fee_amount'));
        $revenuePrev = (int) $prev()->sum(DB::raw('platform_fee_amount + buyer_protection_amount + swap_fee_amount'));
        $escrow = (int) DB::table('orders')->where('currency', 'USD')->whereIn('escrow_status', ['held', 'frozen', 'on_hold'])->sum(DB::raw('total_amount - refunded_amount'));
        $escrowCount = DB::table('orders')->whereIn('escrow_status', ['held', 'frozen', 'on_hold'])->count();
        $signups = DB::table('users')->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $signupsPrev = DB::table('users')->whereBetween('created_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)])->count();

        $daily = DB::table('orders')->whereNotNull('paid_at')->where('currency', 'USD')->where('paid_at', '>=', $now->copy()->subDays(30))
            ->selectRaw("to_char(date_trunc('day', paid_at), 'YYYY-MM-DD') as date, sum(items_amount) as gmv, count(*) as orders")
            ->groupBy('date')->orderBy('date')->get()->keyBy('date');
        $series = [];
        for ($d = $now->copy()->subDays(29)->startOfDay(); $d->lte($now); $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $series[] = ['date' => $d->toDateString(), 'gmv' => (int) ($row->gmv ?? 0), 'orders' => (int) ($row->orders ?? 0)];
        }
        $spark = fn (string $key) => array_map(fn ($chunk) => array_sum(array_column($chunk, $key)), array_chunk($series, 3));

        return [
            'kpis' => [
                ['key' => 'gmv', 'label' => 'Gross merchandise value', 'value' => $usd($gmv), 'delta' => $delta($gmv, $gmvPrev), 'trend' => $gmv >= $gmvPrev ? 'up' : 'down', 'positive' => $gmv >= $gmvPrev, 'sparkline' => $spark('gmv')],
                ['key' => 'escrow', 'label' => 'Held in escrow', 'value' => $usd($escrow), 'delta' => $escrowCount.' orders', 'trend' => 'flat', 'positive' => true, 'caption' => $escrowCount.' orders', 'sparkline' => []],
                ['key' => 'orders', 'label' => 'Paid orders', 'value' => (string) $orders, 'delta' => $delta($orders, $ordersPrev), 'trend' => $orders >= $ordersPrev ? 'up' : 'down', 'positive' => $orders >= $ordersPrev, 'sparkline' => $spark('orders')],
                ['key' => 'revenue', 'label' => 'Platform revenue', 'value' => $usd($revenue), 'delta' => $delta($revenue, $revenuePrev), 'trend' => $revenue >= $revenuePrev ? 'up' : 'down', 'positive' => $revenue >= $revenuePrev, 'sparkline' => []],
                ['key' => 'signups', 'label' => 'New members', 'value' => (string) $signups, 'delta' => $delta($signups, $signupsPrev), 'trend' => $signups >= $signupsPrev ? 'up' : 'down', 'positive' => $signups >= $signupsPrev, 'sparkline' => []],
            ],
            'series' => $series,
        ];
    }
}
