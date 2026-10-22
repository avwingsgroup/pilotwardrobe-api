<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Reports & flags (AD10). */
final class AdminReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'open');
        $query = Report::query()->with('assignee')
            ->when($tab === 'open', fn ($q) => $q->where('status', 'open'))
            ->when($tab === 'closed', fn ($q) => $q->whereIn('status', ['actioned', 'dismissed']))
            ->when($request->query('severity'), fn ($q) => $q->whereIn('severity', QueryParams::list($request, 'severity')))
            ->when($request->query('type'), fn ($q) => $q->whereIn('target_type', QueryParams::list($request, 'type')))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (Report $r) => AdminPresenter::reportRow($r), ['counts' => [
            'open' => Report::query()->where('status', 'open')->count(),
            'critical' => Report::query()->where('status', 'open')->where('severity', 'critical')->count(),
            'automated' => Report::query()->where('status', 'open')->where('source', 'automated')->count(),
        ]]);
    }

    /** `POST /admin/reports/{id}/actions` — `dismiss | warn | remove | suspend | assign`. */
    public function act(Request $request, string $id, AuditLogger $audit, SessionManager $sessions, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'warn', 'remove', 'suspend', 'assign'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'assignee_id' => ['required_if:action,assign', 'nullable', 'string', 'max:40'],
        ]);
        $report = Report::query()->whereKey($id)->first() ?? throw ApiException::notFound('Report not found.');
        $staff = $request->user();

        if ($data['action'] === 'suspend' && ! $staff->hasPermission('users.suspend')) {
            throw ApiException::forbidden();
        }

        DB::transaction(function () use ($report, $data, $staff, $audit, $sessions, $notifications) {
            if ($data['action'] === 'assign') {
                $assignee = User::query()->whereKey($data['assignee_id'])->first();
                if ($assignee === null || ! $assignee->isStaff()) {
                    throw ApiException::validation(['assignee_id' => 'Choose a staff member.']);
                }
                $report->forceFill(['assignee_id' => $assignee->id])->save();
                $audit->log('REPORT.ASSIGNED', $report, $assignee->shortName());

                return;
            }

            if ($report->status !== 'open') {
                throw ApiException::invalidTransition('This report is already closed.');
            }

            $owner = $this->targetOwner($report);
            if ($data['action'] === 'remove' && $report->target_type === 'listing') {
                $listing = Listing::query()->find($report->target_id);
                if ($listing && $listing->status->canTransitionTo(ListingStatus::Removed)) {
                    $listing->forceFill(['status' => ListingStatus::Removed, 'moderation_note' => $data['note'] ?? 'Removed after a report', 'moderated_by' => $staff->id, 'moderated_at' => now()])->save();
                    $notifications->notify($listing->seller, 'listing.removed', ['title' => $listing->title, 'note' => (string) ($data['note'] ?? 'Policy violation')], "listing.removed:{$listing->id}");
                }
            }
            if ($data['action'] === 'suspend' && $owner && ! $owner->isStaff()) {
                $owner->forceFill(['status' => UserStatus::Suspended, 'suspended_at' => now(), 'status_reason' => $data['note'] ?? 'Policy violation'])->save();
                $sessions->revokeAll($owner);
                $notifications->notify($owner, 'account.suspended', ['reason' => (string) ($data['note'] ?? 'Policy violation')], 'account.suspended:'.$owner->id.':'.now()->timestamp);
            }
            if ($data['action'] === 'warn' && $owner) {
                $notifications->notify($owner, 'account.restricted', ['reason' => 'Warning: '.($data['note'] ?? 'please follow our community rules.')], 'account.warned:'.$report->id);
            }

            $report->forceFill([
                'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
                'resolution' => $data['action'],
                'resolution_note' => $data['note'] ?? null,
                'resolved_by' => $staff->id,
                'resolved_at' => now(),
                'assignee_id' => $report->assignee_id ?? $staff->id,
            ])->save();
            $audit->log('REPORT.'.strtoupper($data['action']), $report, $data['note'] ?? null, $data['action'] === 'dismiss' ? 'info' : 'warning');
        });

        return ApiResponse::ok(AdminPresenter::reportRow($report->fresh('assignee')), 'Action applied and report closed.');
    }

    private function targetOwner(Report $report): ?User
    {
        return match ($report->target_type) {
            'user' => User::query()->find($report->target_id),
            'listing' => Listing::query()->find($report->target_id)?->seller,
            'message' => Message::query()->find($report->target_id)?->sender,
            'conversation' => ($c = Conversation::query()->find($report->target_id)) ? User::query()->find(
                Message::query()->where('conversation_id', $c->id)->whereNotNull('flags')->latest()->value('sender_id') ?? $c->seller_id) : null,
            default => null,
        };
    }
}
