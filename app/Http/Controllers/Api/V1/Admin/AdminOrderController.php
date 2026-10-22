<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Jobs\Payments\ReconcilePayments;
use App\Models\Order;
use App\Services\Admin\ApprovalService;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\OrderService;
use App\Services\Orders\RefundService;
use App\Services\Platform\PlatformSettings;
use App\Services\Pricing\ExchangeRates;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Orders & escrow (AD7) and order detail / refund (AD8). */
final class AdminOrderController extends Controller
{
    private const WITH = ['items.image', 'buyer.kyc', 'seller.kyc', 'events', 'successfulPayment', 'latestPayment'];

    private const TABS = [
        'held' => ['escrow_status' => ['held']],
        'frozen' => ['escrow_status' => ['frozen']],
        'on_hold' => ['status' => ['on_hold']],
        'awaiting_dispatch' => ['status' => ['paid', 'awaiting_dispatch']],
        'in_transit' => ['status' => ['in_transit', 'out_for_delivery']],
        'delivered' => ['status' => ['delivered']],
        'disputed' => ['status' => ['disputed']],
        'pending_payment' => ['status' => ['pending_payment']],
        'completed' => ['status' => ['completed']],
        'refunded' => ['status' => ['refunded', 'partially_refunded', 'cancelled']],
    ];

    public function __construct(private readonly OrderService $orders, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'all');
        $query = Order::query()->with(self::WITH);
        if (isset(self::TABS[$tab])) {
            foreach (self::TABS[$tab] as $column => $values) {
                $query->whereIn($column, $values);
            }
        }
        $query->when(QueryParams::search($request), fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('id', $s)))
            ->when($request->query('status'), fn ($q) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->when($request->query('risk'), fn ($q) => $q->whereIn('risk', QueryParams::list($request, 'risk')))
            ->when($request->query('date_from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'));
        QueryParams::applySort($query, $request, ['created_at' => 'created_at', 'total' => 'total_amount'], '-created_at');

        $counts = [];
        foreach (self::TABS as $key => $filter) {
            $c = Order::query();
            foreach ($filter as $column => $values) {
                $c->whereIn($column, $values);
            }
            $counts[$key] = $c->count();
        }

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Order $o) => AdminPresenter::orderRow($o), ['counts' => ['all' => Order::query()->count()] + $counts]);
    }

    /** `GET /admin/orders/stats` — escrow buckets (USD-equivalent). */
    public function stats(ExchangeRates $fx): JsonResponse
    {
        $rows = DB::table('orders')->selectRaw('escrow_status, currency, count(*) as n, sum(total_amount - refunded_amount) as amount')
            ->whereIn('escrow_status', ['held', 'frozen', 'on_hold', 'releasing'])->groupBy('escrow_status', 'currency')->get();

        $buckets = [];
        foreach (['held', 'frozen', 'on_hold', 'releasing'] as $bucket) {
            $subset = $rows->where('escrow_status', $bucket);
            $usd = $subset->sum(fn ($r) => rescue(fn () => $fx->convert(Money::of((int) $r->amount, $r->currency), 'USD')->amount, 0, false));
            $buckets[] = ['key' => $bucket, 'label' => ucfirst(str_replace('_', ' ', $bucket)), 'count' => (int) $subset->sum('n'),
                'amount' => Money::of((int) $usd, 'USD')->toArray()];
        }

        return ApiResponse::ok([
            'buckets' => $buckets,
            'auto_release_next_24h' => Order::query()->where('status', 'delivered')->whereBetween('auto_release_at', [now(), now()->addDay()])->count(),
            'overdue_dispatch' => Order::query()->whereIn('status', ['paid', 'awaiting_dispatch'])->where('dispatch_due_at', '<', now())->count(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(AdminPresenter::orderDetail($this->find($id)));
    }

    /** `POST /admin/orders/{id}/refund` — above the threshold this becomes a dual-control request. */
    public function refund(Request $request, string $id, ApprovalService $approvals, RefundService $refunds, PlatformSettings $settings, ExchangeRates $fx): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['full', 'partial'])],
            'amount' => ['required_if:type,partial', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
            'suspend_buyer' => ['nullable', 'boolean'],
            'suspend_seller' => ['nullable', 'boolean'],
        ]);
        $order = $this->find($id);
        $refundable = $order->total_amount - $order->refunded_amount;
        $amount = $data['type'] === 'full' ? $refundable : (int) $data['amount'];
        if ($amount <= 0 || $amount > $refundable) {
            throw ApiException::validation(['amount' => 'The refund must be between 1 and '.$order->money($refundable)->format().'.']);
        }

        $this->maybeSuspend($order, $data);

        $threshold = $settings->get('dual_control.refund_threshold');
        if ($threshold && $fx->convert($order->money($amount), $threshold['currency'])->amount > (int) $threshold['amount']) {
            $result = $approvals->request('refund', 'transactions.refund', $request->user(),
                ['amount' => $amount, 'reason' => $data['reason'], 'note' => $data['note'] ?? null],
                'Refund '.$order->money($amount)->format().' on #'.$order->number, $order);

            return ApiResponse::ok($result, 'Refund requested — a second approver must confirm.');
        }

        $refund = $refunds->refundOrder($order, $amount, $data['reason'], $data['note'] ?? null, $request->user());

        return ApiResponse::ok(['status' => 'executed', 'refund_id' => $refund->id, 'order' => AdminPresenter::orderDetail($this->find($id))], 'Refund issued.');
    }

    /** `POST /admin/orders/{id}/release` — lift a hold, or force-release escrow. */
    public function release(Request $request, string $id): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->orders->releaseHold($this->find($id), $request->user());

        return ApiResponse::ok(AdminPresenter::orderDetail($this->find($id)), 'Released.');
    }

    public function hold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->orders->hold($this->find($id), $request->user(), $data['reason']);

        return ApiResponse::ok(AdminPresenter::orderDetail($this->find($id)), 'Order on hold.');
    }

    /** `POST /admin/orders/reconcile` — queue a PSP reconciliation run. */
    public function reconcile(Request $request): JsonResponse
    {
        ReconcilePayments::dispatch(hours: (int) $request->input('hours', 72));
        $this->audit->log('PAYMENTS.RECONCILE_STARTED', 'payments', 'Last '.(int) $request->input('hours', 72).'h');

        return ApiResponse::ok(['status' => 'queued'], 'Reconciliation started. Results appear in the audit log.');
    }

    private function maybeSuspend(Order $order, array $data): void
    {
        foreach (['buyer' => $order->buyer, 'seller' => $order->seller] as $role => $user) {
            if (! empty($data["suspend_{$role}"]) && $user->status !== UserStatus::Suspended) {
                $user->forceFill(['status' => UserStatus::Suspended, 'suspended_at' => now(), 'status_reason' => 'Refund on #'.$order->number.': '.$data['reason']])->save();
                $this->audit->log('USER.SUSPENDED', $user, 'During refund of #'.$order->number, 'danger');
            }
        }
    }

    private function find(string $id): Order
    {
        return Order::query()->with(self::WITH)->whereKey($id)->first() ?? throw ApiException::notFound('Order not found.');
    }
}
