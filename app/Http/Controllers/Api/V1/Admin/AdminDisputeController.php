<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Disputes\DisputeController;
use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\Admin\ApprovalService;
use App\Services\Disputes\DisputeService;
use App\Services\Platform\PlatformSettings;
use App\Services\Pricing\ExchangeRates;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Disputes queue and mediation workspace (AD9). */
final class AdminDisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'open');
        $query = Dispute::query()->with(DisputeController::WITH)
            ->when($tab === 'open', fn ($q) => $q->whereIn('status', ['open', 'awaiting_seller', 'under_review', 'appealed']))
            ->when($tab === 'awaiting_seller', fn ($q) => $q->where('status', 'awaiting_seller'))
            ->when($tab === 'under_review', fn ($q) => $q->where('status', 'under_review'))
            ->when($tab === 'due_today', fn ($q) => $q->whereIn('status', ['under_review', 'appealed'])->where('decision_due_at', '<=', now()->endOfDay()))
            ->when($tab === 'resolved', fn ($q) => $q->whereIn('status', ['resolved_buyer', 'resolved_seller', 'resolved_split', 'withdrawn']))
            ->when($tab === 'mine', fn ($q) => $q->where('mediator_id', $request->user()->id))
            ->when(QueryParams::search($request), fn ($q, $s) => $q->where('number', 'ilike', '%'.addcslashes($s, '%_\\').'%'))
            ->orderBy('decision_due_at');

        $count = fn (array $s) => Dispute::query()->whereIn('status', $s)->count();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Dispute $d) => (new DisputeResource($d))->forStaff()->resolve($request), ['counts' => [
            'open' => $count(['open', 'awaiting_seller', 'under_review', 'appealed']),
            'awaiting_seller' => $count(['awaiting_seller']),
            'under_review' => $count(['under_review']),
            'due_today' => Dispute::query()->whereIn('status', ['under_review', 'appealed'])->where('decision_due_at', '<=', now()->endOfDay())->count(),
        ]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok((new DisputeResource($this->find($id)))->forStaff()->resolve($request));
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
            'internal' => ['nullable', 'boolean'],
            'evidence_ids' => ['nullable', 'array', 'max:10'],
        ]);
        $dispute = $this->disputes->addMessage($this->find($id), $request->user(), $data['body'], $data['evidence_ids'] ?? [], (bool) ($data['internal'] ?? false), asMediator: true);

        return ApiResponse::ok((new DisputeResource($dispute->fresh(DisputeController::WITH)))->forStaff()->resolve($request));
    }

    /** `POST /admin/disputes/{id}/decision` — dual control above the threshold. */
    public function decide(Request $request, string $id, ApprovalService $approvals, PlatformSettings $settings, ExchangeRates $fx): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(Dispute::DECISIONS)],
            'amount_to_buyer' => ['nullable', 'integer', 'min:0'],
            'policy_reference' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['policy_reference.required' => 'Cite the policy this decision is based on.']);
        $dispute = $this->find($id);
        if (! $dispute->status->isActive()) {
            throw ApiException::invalidTransition('This dispute is already closed.');
        }

        $amount = match ($data['decision']) {
            'release_to_seller' => 0,
            'partial_refund' => (int) ($data['amount_to_buyer'] ?? 0),
            default => $dispute->frozen_amount,
        };
        if ($data['decision'] === 'partial_refund' && ($amount <= 0 || $amount >= $dispute->frozen_amount)) {
            throw ApiException::validation(['amount_to_buyer' => 'A partial refund must be more than zero and less than '.$dispute->money($dispute->frozen_amount)->format().'.']);
        }

        $summary = match ($data['decision']) {
            'release_to_seller' => 'Funds released to the seller.',
            'partial_refund' => 'Partial refund of '.$dispute->money($amount)->format().' to the buyer; remainder released to the seller.',
            'full_refund_with_return' => 'Full refund to the buyer once the item is returned.',
            'full_refund_no_return' => 'Full refund to the buyer; no return required.',
            default => throw ApiException::validation(['decision' => 'Choose a valid decision.']),
        }.(! empty($data['note']) ? ' '.$data['note'] : '');

        $threshold = $settings->get('dual_control.dispute_decision_threshold');
        if ($amount > 0 && $threshold && $fx->convert($dispute->money($amount), $threshold['currency'])->amount > (int) $threshold['amount']) {
            return ApiResponse::ok($approvals->request('dispute_decision', 'disputes.resolve', $request->user(), [
                'decision' => $data['decision'], 'amount_to_buyer' => $amount, 'policy_reference' => $data['policy_reference'], 'summary' => $summary,
            ], "Dispute {$dispute->number}: {$data['decision']}", $dispute), 'Decision submitted — a second approver must confirm.');
        }

        $this->disputes->resolve($dispute, $amount, $summary, $request->user(), $data['decision'], $data['policy_reference']);

        return ApiResponse::ok(['status' => 'executed'], 'Decision issued. Both parties notified.');
    }

    private function find(string $id): Dispute
    {
        return Dispute::query()->with(DisputeController::WITH)->whereKey($id)->first() ?? throw ApiException::notFound('Dispute not found.');
    }
}
