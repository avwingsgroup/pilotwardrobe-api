<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\ApprovalRequest;
use App\Models\CommissionRule;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Disputes\DisputeService;
use App\Services\Orders\RefundService;
use App\Services\Platform\PlatformSettings;
use App\Services\Pricing\CommissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dual control (contract §4.4). High-risk staff actions are stored as approval requests
 * and applied only when a *different* staff member holding the same permission approves.
 * Both the request and the decision are audit-logged.
 */
final class ApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformSettings $settings,
    ) {}

    /** @return array{status: 'pending_approval', approver: string, approval_id: string} */
    public function request(string $type, string $permission, User $requester, array $payload, string $summary, ?Model $target = null): array
    {
        $approval = DB::transaction(function () use ($type, $permission, $requester, $payload, $summary, $target) {
            $approval = ApprovalRequest::query()->create([
                'type' => $type,
                'permission' => $permission,
                'target_type' => $target ? AuditLogger::typeOf($target) : null,
                'target_id' => $target?->getKey(),
                'summary' => mb_substr($summary, 0, 255),
                'payload' => $payload,
                'status' => 'pending',
                'requested_by' => $requester->id,
            ]);
            $this->audit->log(strtoupper($type).'.REQUESTED', $target ?? $approval, $summary.' · awaiting approver', 'warning', after: $payload, actor: $requester,
                metadata: ['approval_id' => $approval->id]);

            return $approval;
        });

        return ['status' => 'pending_approval', 'approver' => $this->approverLabel($permission, $requester), 'approval_id' => $approval->id];
    }

    public function approve(ApprovalRequest $approval, User $approver, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($approval, $approver, $note) {
            $approval = ApprovalRequest::query()->lockForUpdate()->findOrFail($approval->id);
            if ($approval->status !== 'pending') {
                throw ApiException::invalidTransition('This request has already been decided.');
            }
            if ($approval->requested_by === $approver->id) {
                throw ApiException::conflict('You cannot approve a change you requested.');
            }
            if (! $approver->hasPermission($approval->permission)) {
                throw ApiException::forbidden();
            }

            $requester = User::query()->find($approval->requested_by);
            try {
                $this->apply($approval, $requester, $approver);
            } catch (ApiException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);
                throw ApiException::conflict('The change could not be applied: '.class_basename($e));
            }

            $approval->forceFill(['status' => 'approved', 'decided_by' => $approver->id, 'decision_note' => $note, 'decided_at' => now()])->save();
            $this->audit->log(strtoupper($approval->type).'.APPROVED', $approval, $approval->summary, 'success', actor: $approver, metadata: ['approval_id' => $approval->id]);

            return $approval;
        });
    }

    public function reject(ApprovalRequest $approval, User $approver, ?string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($approval, $approver, $note) {
            $approval = ApprovalRequest::query()->lockForUpdate()->findOrFail($approval->id);
            if ($approval->status !== 'pending') {
                throw ApiException::invalidTransition('This request has already been decided.');
            }
            if (! $approver->hasPermission($approval->permission)) {
                throw ApiException::forbidden();
            }
            $approval->forceFill(['status' => 'rejected', 'decided_by' => $approver->id, 'decision_note' => $note, 'decided_at' => now()])->save();
            $this->audit->log(strtoupper($approval->type).'.REJECTED', $approval, $note, 'warning', actor: $approver, metadata: ['approval_id' => $approval->id]);

            return $approval;
        });
    }

    private function apply(ApprovalRequest $approval, ?User $requester, User $approver): void
    {
        $p = $approval->payload;

        match ($approval->type) {
            'refund' => app(RefundService::class)->refundOrder(
                Order::query()->findOrFail($approval->target_id), (int) $p['amount'], (string) $p['reason'], $p['note'] ?? null, $requester, approver: $approver),
            'dispute_decision' => app(DisputeService::class)->resolve(
                Dispute::query()->findOrFail($approval->target_id), (int) $p['amount_to_buyer'], (string) $p['summary'], $approver, $p['decision'], $p['policy_reference'] ?? null),
            'commission_rule' => $this->applyCommissionRule($p),
            'escrow_timings' => $this->settings->set('escrow', array_merge($this->settings->get('escrow'), $p)),
            'settings' => collect($p)->each(fn ($value, $key) => $this->settings->set($key, $value)),
            default => throw ApiException::conflict('Unknown approval type.'),
        };
    }

    private function applyCommissionRule(array $p): void
    {
        $rule = isset($p['id']) ? CommissionRule::query()->findOrFail($p['id']) : new CommissionRule;
        $rule->fill(array_diff_key($p, ['id' => 1]))->save();
        CommissionService::flush();
    }

    private function approverLabel(string $permission, User $requester): string
    {
        $candidate = User::query()->where('id', '!=', $requester->id)->where('status', 'active')
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])->get()
            ->first(fn (User $u) => $u->hasPermission($permission) && ! $u->isSuperAdmin());

        return $candidate?->shortName() ?? 'Super admin';
    }
}
