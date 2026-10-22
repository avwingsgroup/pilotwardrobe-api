<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PayoutStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Jobs\Payouts\ProcessPayout;
use App\Models\ApprovalRequest;
use App\Models\BoostPackage;
use App\Models\CommissionRule;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Services\Admin\ApprovalService;
use App\Services\Audit\AuditLogger;
use App\Services\Payouts\PayoutService;
use App\Services\Platform\PlatformSettings;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Ids\ReferenceGenerator;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Payouts (AD11), fees & revenue (AD12) and the dual-control approvals queue. */
final class AdminMoneyController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    // ---------------------------------------------------------------- payouts

    public function payouts(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'awaiting_approval');
        $query = Payout::query()->with(['user.kyc', 'payoutMethod'])
            ->when($tab !== 'all', fn ($q) => $q->whereIn('status', match ($tab) {
                'processing' => ['pending', 'approved', 'processing'],
                'failed' => ['failed'],
                'on_hold' => ['on_hold'],
                'paid' => ['paid'],
                'rejected' => ['rejected'],
                default => ['awaiting_approval'],
            }))
            ->when(QueryParams::bool($request, 'flagged') === true, fn ($q) => $q->where('flagged', true))
            ->orderBy('created_at');

        $count = fn (array $s) => Payout::query()->whereIn('status', $s)->count();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Payout $p) => AdminPresenter::payoutRow($p), ['counts' => [
            'awaiting_approval' => $count(['awaiting_approval']),
            'processing' => $count(['pending', 'approved', 'processing']),
            'on_hold' => $count(['on_hold']),
            'failed' => $count(['failed']),
        ]]);
    }

    public function payoutStats(): JsonResponse
    {
        $rows = Payout::query()->selectRaw('status, currency, count(*) as n, sum(amount) as total')->groupBy('status', 'currency')->get();
        $sum = fn (array $statuses) => $rows->whereIn('status', $statuses)->groupBy('currency')
            ->map(fn ($g, $c) => Money::of((int) $g->sum('total'), $c)->toArray())->values()->all();

        return ApiResponse::ok([
            'awaiting_approval' => ['count' => (int) $rows->where('status', 'awaiting_approval')->sum('n'), 'totals' => $sum(['awaiting_approval'])],
            'processing' => ['count' => (int) $rows->whereIn('status', ['pending', 'approved', 'processing'])->sum('n'), 'totals' => $sum(['pending', 'approved', 'processing'])],
            'paid_today' => ['count' => Payout::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())->count()],
            'failed' => ['count' => (int) $rows->where('status', 'failed')->sum('n')],
        ]);
    }

    /** `POST /admin/payouts/approve` — `{ ids }`; the requester can never approve their own payout. */
    public function approvePayouts(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['string', 'max:40']]);
        $approved = [];
        $skipped = [];
        foreach (Payout::query()->whereIn('id', $data['ids'])->get() as $payout) {
            if ($payout->user_id === $request->user()->id) {
                throw ApiException::conflict('You cannot approve your own payout.');
            }
            try {
                $this->payouts->approve($payout, $request->user());
                $approved[] = $payout->id;
            } catch (ApiException $e) {
                $skipped[] = ['id' => $payout->id, 'reason' => $e->getMessage()];
            }
        }

        return ApiResponse::ok(['approved' => count($approved), 'ids' => $approved, 'skipped' => $skipped], count($approved).' payout(s) approved.');
    }

    public function holdPayout(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok(AdminPresenter::payoutRow($this->payouts->hold($this->payout($id), $request->user(), $data['reason'])->fresh(['user', 'payoutMethod'])));
    }

    public function rejectPayout(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok(AdminPresenter::payoutRow($this->payouts->reject($this->payout($id), $request->user(), $data['reason'])->fresh(['user', 'payoutMethod'])));
    }

    /** `POST /admin/payouts/{id}/mark-paid` (addition) — confirms a manual-rail transfer. */
    public function markPaid(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['provider_reference' => ['required', 'string', 'max:191']]);
        $payout = $this->payout($id);
        if ($payout->status !== PayoutStatus::Processing || $payout->provider !== 'manual') {
            throw ApiException::invalidTransition('Only manual payouts in processing can be marked paid.');
        }
        $payout->forceFill(['provider_reference' => $data['provider_reference']])->save();
        $this->payouts->markPaid($payout);
        $this->audit->log('PAYOUT.MARKED_PAID', $payout, $data['provider_reference'], 'success');

        return ApiResponse::ok(AdminPresenter::payoutRow($payout->fresh(['user', 'payoutMethod'])));
    }

    /** `POST /admin/payouts/batches` — groups approved payouts per provider/currency and sends them. */
    public function createBatch(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['string', 'max:40']]);

        $batches = DB::transaction(function () use ($data, $request) {
            $payouts = Payout::query()->where('status', PayoutStatus::Approved->value)->whereNull('batch_id')
                ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))->lockForUpdate()->get();
            $created = [];
            foreach ($payouts->groupBy(fn ($p) => $p->provider.'|'.$p->currency) as $key => $group) {
                [$provider, $currency] = explode('|', $key);
                $batch = PayoutBatch::query()->create([
                    'reference' => 'POB-'.ReferenceGenerator::code(8),
                    'provider' => $provider, 'currency' => $currency,
                    'payouts_count' => $group->count(), 'total_amount' => (int) $group->sum('amount'),
                    'status' => 'queued', 'created_by' => $request->user()->id,
                ]);
                Payout::query()->whereIn('id', $group->pluck('id'))->update(['batch_id' => $batch->id]);
                foreach ($group as $payout) {
                    ProcessPayout::dispatch($payout->id)->afterCommit();
                }
                $created[] = ['id' => $batch->id, 'reference' => $batch->reference, 'provider' => $provider, 'payouts_count' => $batch->payouts_count,
                    'total' => Money::of($batch->total_amount, $currency)->toArray()];
                $this->audit->log('PAYOUT_BATCH.CREATED', $batch, $batch->payouts_count.' payouts', 'warning');
            }

            return $created;
        });

        return ApiResponse::created(['batches' => $batches], count($batches).' batch(es) queued.');
    }

    // ---------------------------------------------------------------- fees (AD12)

    public function fees(PlatformSettings $settings): JsonResponse
    {
        $revenue = DB::table('orders')->whereNotNull('paid_at')->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())->where('currency', 'USD')
            ->selectRaw("to_char(date_trunc('month', paid_at), 'YYYY-MM') as month, sum(platform_fee_amount) as commission, sum(buyer_protection_amount) as protection, sum(swap_fee_amount) as swap_fees")
            ->groupBy('month')->orderBy('month')->get();
        $boosts = DB::table('listing_promotions')->where('status', 'active')->where('currency', 'USD')->where('created_at', '>=', now()->startOfMonth())->sum('amount');

        return ApiResponse::ok([
            'revenue' => [
                'currency' => 'USD',
                'months' => $revenue->map(fn ($r) => ['month' => $r->month, 'commission' => (int) $r->commission, 'buyer_protection' => (int) $r->protection, 'swap_fees' => (int) $r->swap_fees])->values(),
                'boosts_this_month' => (int) $boosts,
            ],
            'commission_rules' => CommissionRule::query()->orderBy('kind')->orderBy('priority')->get()->map(fn (CommissionRule $r) => $this->presentRule($r))->values(),
            'escrow_timings' => $settings->get('escrow'),
            'boost_packages' => BoostPackage::query()->orderBy('position')->get()->map(fn (BoostPackage $b) => ['id' => $b->id, 'name' => $b->name, 'duration_days' => $b->duration_days, 'price' => $b->price()->toArray(), 'active' => $b->is_active])->values(),
            'pending_approvals' => ApprovalRequest::query()->whereIn('type', ['commission_rule', 'escrow_timings'])->where('status', 'pending')->count(),
        ]);
    }

    /** `POST /admin/commission-rules` and `PATCH /admin/commission-rules/{id}` — always dual control. */
    public function saveRule(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(['sale_commission', 'swap_fee', 'buyer_protection'])],
            'scope' => ['sometimes', Rule::in(['all', 'categories', 'seller_type', 'country'])],
            'scope_values' => ['nullable', 'array'],
            'rate' => [$id ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:50'],
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'cap_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['nullable', Rule::in(['live', 'scheduled', 'disabled'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $rule = $id ? (CommissionRule::query()->find($id) ?? throw ApiException::notFound('Rule not found.')) : null;

        $payload = array_filter([
            'id' => $rule?->id,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'kind' => $data['kind'] ?? ($rule ? null : 'sale_commission'),
            'scope' => $data['scope'] ?? ($rule ? null : 'all'),
            'scope_values' => $data['scope_values'] ?? null,
            'rate_bps' => isset($data['rate']) ? (int) round($data['rate'] * 100) : null,
            'min_amount' => $data['min_amount'] ?? null,
            'cap_amount' => $data['cap_amount'] ?? null,
            'currency' => $data['currency'] ?? ($rule ? null : 'USD'),
            'priority' => $data['priority'] ?? null,
            'status' => $data['status'] ?? ($rule ? null : 'live'),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ], fn ($v) => $v !== null);

        $summary = ($rule ? 'Edit' : 'New').' rule: '.($payload['name'] ?? $rule?->name).(isset($payload['rate_bps']) ? ' '.($payload['rate_bps'] / 100).'%' : '');

        return ApiResponse::ok($this->approvals->request('commission_rule', 'fees.manage', $request->user(), $payload, $summary, $rule),
            'Fee change submitted — a second approver must confirm.');
    }

    /** `PUT /admin/escrow-timings` — dual control. */
    public function escrowTimings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_release_hours' => ['sometimes', 'integer', 'between:24,336'],
            'dispatch_sla_days' => ['sometimes', 'integer', 'between:1,14'],
            'dispute_window_hours' => ['sometimes', 'integer', 'between:24,336'],
            'new_seller_payout_hold_days' => ['sometimes', 'integer', 'between:0,30'],
        ]);
        if ($data === []) {
            throw ApiException::validation(['auto_release_hours' => 'Change at least one timing.']);
        }

        return ApiResponse::ok($this->approvals->request('escrow_timings', 'fees.manage', $request->user(), $data, 'Escrow timings: '.json_encode($data)),
            'Change submitted — a second approver must confirm.');
    }

    // ---------------------------------------------------------------- approvals (addition)

    public function approvalsIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ApprovalRequest::query()->with(['requester', 'decider'])
            ->where('status', $request->query('status', 'pending'))->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (ApprovalRequest $a) => [
            'id' => $a->id,
            'type' => $a->type,
            'summary' => $a->summary,
            'payload' => $a->payload,
            'target_type' => $a->target_type,
            'target_id' => $a->target_id,
            'status' => $a->status,
            'requested_by' => $a->requester?->shortName(),
            'requested_at' => $a->created_at?->toIso8601ZuluString(),
            'decided_by' => $a->decider?->shortName(),
            'decided_at' => $a->decided_at?->toIso8601ZuluString(),
            'can_approve' => $a->status === 'pending' && $a->requested_by !== $user->id && $user->hasPermission($a->permission),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $approval = ApprovalRequest::query()->find($id) ?? throw ApiException::notFound('Request not found.');

        return ApiResponse::ok(['id' => $this->approvals->approve($approval, $request->user(), $data['note'] ?? null)->id, 'status' => 'approved'], 'Approved and applied.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $approval = ApprovalRequest::query()->find($id) ?? throw ApiException::notFound('Request not found.');

        return ApiResponse::ok(['id' => $this->approvals->reject($approval, $request->user(), $data['note'])->id, 'status' => 'rejected']);
    }

    private function presentRule(CommissionRule $r): array
    {
        $money = fn (?int $a) => $a === null ? null : Money::of($a, $r->currency)->toArray();

        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'kind' => $r->kind,
            'scope' => $r->scope,
            'scope_values' => $r->scope_values ?? [],
            'applies_to' => $r->scope === 'all' ? 'All' : implode(', ', $r->scope_values ?? []),
            'rate' => $r->rate_bps / 100,
            'min' => $money($r->min_amount),
            'cap' => $money($r->cap_amount),
            'status' => $r->status,
            'starts_at' => $r->starts_at?->toIso8601ZuluString(),
            'ends_at' => $r->ends_at?->toIso8601ZuluString(),
        ];
    }

    private function payout(string $id): Payout
    {
        return Payout::query()->with(['user', 'payoutMethod'])->whereKey($id)->first() ?? throw ApiException::notFound('Payout not found.');
    }
}
