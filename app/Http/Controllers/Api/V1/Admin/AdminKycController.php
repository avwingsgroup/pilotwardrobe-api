<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AviatorStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Models\AviatorCredential;
use App\Models\KycVerification;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Verification\KycService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** KYC review queue (AD5) and aviator credential review. */
final class AdminKycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /** `GET /admin/kyc?tab=pending|escalated|more_info|done` */
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'pending');
        $statuses = match ($tab) {
            'escalated' => ['escalated'],
            'more_info' => ['more_info'],
            'done' => ['approved', 'rejected', 'expired'],
            'all' => ['pending', 'escalated', 'more_info', 'approved', 'rejected', 'expired'],
            default => ['pending'],
        };

        $query = KycVerification::query()->with('user')->whereIn('status', $statuses)
            ->when($request->query('country'), fn ($q, $c) => $q->where('document_country', strtoupper((string) $c)))
            ->when(QueryParams::search($request), fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', '%'.addcslashes($s, '%_\\').'%')
                ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", ['%'.addcslashes($s, '%_\\').'%'])));
        QueryParams::applySort($query, $request, ['submitted_at' => 'submitted_at'], 'submitted_at');

        $count = fn (array $s) => KycVerification::query()->whereIn('status', $s)->count();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (KycVerification $k) => AdminPresenter::kycItem($k), ['counts' => [
            'pending' => $count(['pending']),
            'escalated' => $count(['escalated']),
            'more_info' => $count(['more_info']),
            'over_sla' => KycVerification::query()->whereIn('status', ['pending', 'escalated'])->where('submitted_at', '<', now()->subHours((int) config('kyc.review_sla_hours')))->count(),
        ]]);
    }

    /** `GET /admin/kyc/{id}` — documents as 10-minute signed URLs. Viewing is audit-logged. */
    public function show(string $id, AuditLogger $audit): JsonResponse
    {
        $kyc = $this->find($id);
        $audit->log('KYC.VIEWED', $kyc, null, 'info');

        return ApiResponse::ok(AdminPresenter::kycItem($kyc, withDocuments: true));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'approve', false);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'reject', true);
    }

    public function escalate(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'escalate', true);
    }

    public function requestInfo(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'request_info', true);
    }

    /** `GET /admin/aviators?tab=pending` (addition) */
    public function aviators(Request $request): JsonResponse
    {
        $query = AviatorCredential::query()->with(['user', 'licence'])
            ->when($request->query('tab', 'pending') !== 'all', fn ($q) => $q->where('status', $request->query('tab', 'pending')))
            ->orderBy('submitted_at');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (AviatorCredential $c) => AdminPresenter::aviator($c));
    }

    /** `POST /admin/aviators/{id}/{approve|reject}` (addition) — approval grants the aviator role. */
    public function decideAviator(Request $request, string $id, string $decision, NotificationService $notifications, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']]);
        $credential = AviatorCredential::query()->with('user')->whereKey($id)->first() ?? throw ApiException::notFound('Application not found.');
        if ($credential->user_id === $request->user()->id) {
            throw ApiException::conflict('You cannot review your own application.');
        }

        DB::transaction(function () use ($credential, $decision, $data, $request, $audit) {
            $target = $decision === 'approve' ? AviatorStatus::Approved : AviatorStatus::Rejected;
            $credential->status->assertCanTransitionTo($target, 'This application is not awaiting review.');
            $credential->forceFill([
                'status' => $target,
                'verified_at' => $decision === 'approve' ? now() : null,
                'rejection_reason' => $decision === 'reject' ? $data['reason'] : null,
                'reviewed_by' => $request->user()->id,
            ])->save();
            if ($decision === 'approve' && $credential->user->role === UserRole::User) {
                $credential->user->forceFill(['role' => UserRole::Aviator, 'job_title' => $credential->user->job_title ?? $credential->job_title])->save();
                $credential->user->bumpPermissionsVersion();
            }
            $audit->log('AVIATOR.'.strtoupper($target->value), $credential, $data['reason'] ?? null, $decision === 'approve' ? 'success' : 'warning');
        });

        $notifications->notify($credential->user, 'aviator.'.($decision === 'approve' ? 'approved' : 'rejected'), ['reason' => (string) ($data['reason'] ?? '')], "aviator.decision:{$credential->id}:".now()->timestamp);

        return ApiResponse::ok(AdminPresenter::aviator($credential->fresh(['user', 'licence'])));
    }

    private function decide(Request $request, string $id, string $decision, bool $reasonRequired): JsonResponse
    {
        $data = $request->validate(['reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:1000']], ['reason.required' => 'Give a reason.']);
        $kyc = $this->kyc->decide($this->find($id), $request->user(), $decision, $data['reason'] ?? null);

        // Same shape as GET /admin/kyc/{id} so the review screen re-renders from the response.
        return ApiResponse::ok(AdminPresenter::kycItem($kyc->fresh(['user', 'documentFront', 'documentBack', 'selfie']), withDocuments: true));
    }

    private function find(string $id): KycVerification
    {
        return KycVerification::query()->with(['user', 'documentFront', 'documentBack', 'selfie'])->whereKey($id)->first()
            ?? throw ApiException::notFound('Verification not found.');
    }
}
