<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\Report;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Listing moderation (AD6). Queue item ids are listing ids. */
final class AdminModerationController extends Controller
{
    /** `GET /admin/moderation?tab=pending|flagged|needs_changes|removed` */
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'pending');
        $reported = Report::query()->where('target_type', 'listing')->where('status', 'open')->pluck('target_id');

        $query = Listing::query()->with(['seller.kyc', 'seller.avatar', 'category.parent', 'images'])
            ->when($tab === 'pending', fn ($q) => $q->where('status', ListingStatus::PendingReview->value))
            ->when($tab === 'flagged', fn ($q) => $q->whereIn('id', $reported))
            ->when($tab === 'needs_changes', fn ($q) => $q->where('status', ListingStatus::NeedsChanges->value))
            ->when($tab === 'removed', fn ($q) => $q->whereIn('status', ['removed', 'rejected']))
            ->when(QueryParams::search($request), fn ($q, $s) => $q->where('title', 'ilike', '%'.addcslashes($s, '%_\\').'%'))
            ->orderBy('submitted_at');

        $page = $query->paginate(QueryParams::perPage($request))->withQueryString();

        return ApiResponse::paginated($page, fn (Listing $l) => (new ListingResource($l))->resolve($request) + [
            'moderation_id' => $l->id,
            'submitted_at' => $l->submitted_at?->toIso8601ZuluString(),
            'reports_count' => Report::query()->where('target_type', 'listing')->where('target_id', $l->id)->where('status', 'open')->count(),
            'seller_kyc_status' => $l->seller->kycStatus()->value,
            'moderation_note' => $l->moderation_note,
        ], ['counts' => [
            'pending' => Listing::query()->where('status', 'pending_review')->count(),
            'flagged' => $reported->unique()->count(),
            'needs_changes' => Listing::query()->where('status', 'needs_changes')->count(),
        ]]);
    }

    /** `POST /admin/moderation/decisions` — `{ ids, action: approve|request_changes|remove|reject, note }` */
    public function decide(Request $request, NotificationService $notifications, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'max:40'],
            'action' => ['required', Rule::in(['approve', 'request_changes', 'remove', 'reject'])],
            'note' => [Rule::requiredIf(fn () => $request->input('action') !== 'approve'), 'nullable', 'string', 'max:1000'],
        ], ['note.required' => 'Tell the seller what to change.']);

        $user = $request->user();
        $required = $data['action'] === 'approve' ? 'listings.approve' : 'listings.reject';
        if (! $user->hasPermission($required)) {
            throw ApiException::forbidden();
        }

        $target = match ($data['action']) {
            'approve' => ListingStatus::Active,
            'request_changes' => ListingStatus::NeedsChanges,
            'reject' => ListingStatus::Rejected,
            'remove' => ListingStatus::Removed,
            default => throw ApiException::validation(['action' => 'Choose a valid action.']),
        };
        $template = match ($data['action']) {
            'approve' => 'listing.approved',
            'request_changes' => 'listing.changes_requested',
            default => 'listing.removed',
        };

        $updated = 0;
        $skipped = [];
        foreach (Listing::query()->whereIn('id', $data['ids'])->get() as $listing) {
            if (! $listing->status->canTransitionTo($target)) {
                $skipped[] = ['id' => $listing->id, 'reason' => 'Not in a reviewable state ('.$listing->status->value.')'];

                continue;
            }

            DB::transaction(function () use ($listing, $target, $data, $user, $audit) {
                $before = $listing->status->value;
                $listing->forceFill([
                    'status' => $target,
                    'moderation_note' => $data['note'] ?? null,
                    'moderated_by' => $user->id,
                    'moderated_at' => now(),
                    'published_at' => $target === ListingStatus::Active ? ($listing->published_at ?? now()) : $listing->published_at,
                ])->save();
                if ($target === ListingStatus::Removed) {
                    Report::query()->where('target_type', 'listing')->where('target_id', $listing->id)->where('status', 'open')
                        ->update(['status' => 'actioned', 'resolution' => 'remove', 'resolved_by' => $user->id, 'resolved_at' => now()]);
                }
                $audit->log('LISTING.'.strtoupper($data['action']), $listing, $data['note'] ?? "{$before} → {$target->value}",
                    $data['action'] === 'approve' ? 'success' : 'warning');
            });

            $notifications->notify($listing->seller, $template, [
                'title' => $listing->title, 'slug' => $listing->slug, 'listing_id' => $listing->id, 'note' => (string) ($data['note'] ?? ''),
            ], "{$template}:{$listing->id}:".now()->timestamp);
            $updated++;
        }

        return ApiResponse::ok(['updated' => $updated, 'skipped' => $skipped]);
    }
}
