<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Models\AuthSession;
use App\Models\Invitation;
use App\Models\Listing;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\UserNote;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Users (AD3) and User 360 (AD4). */
final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly SessionManager $sessions,
    ) {}

    /** `GET /admin/users?tab=&search=&status=&country=&risk=&sort=` */
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'all');
        $query = $this->tabQuery($tab)->with(['kyc', 'staffRole']);

        if ($search = QueryParams::search($request)) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('email', 'ilike', $like)->orWhere('username', 'ilike', $like)
                ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", [$like])->orWhere('id', $search));
        }
        $query->when($request->query('status'), fn ($q) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->when($request->query('country'), fn ($q) => $q->whereIn('country_code', array_map('strtoupper', QueryParams::list($request, 'country'))))
            ->when($request->query('role'), fn ($q) => $q->whereIn('role', QueryParams::list($request, 'role')))
            ->when($request->query('risk'), fn ($q, $r) => match ($r) {
                'high' => $q->where('risk_score', '>=', 70),
                'medium' => $q->whereBetween('risk_score', [30, 69]),
                default => $q->where('risk_score', '<', 30),
            });
        QueryParams::applySort($query, $request, ['joined_at' => 'created_at', 'name' => 'first_name', 'trades' => 'sales_count', 'risk' => 'risk_score'], '-joined_at');

        $page = $query->paginate(QueryParams::perPage($request))->withQueryString();
        $ids = collect($page->items())->pluck('id');
        $gmv = DB::table('orders')->whereIn('seller_id', $ids)->whereNotNull('paid_at')->where('currency', 'USD')
            ->groupBy('seller_id')->selectRaw('seller_id, sum(items_amount) as g')->pluck('g', 'seller_id')->map(fn ($v) => (int) $v)->all();

        return ApiResponse::paginated($page, fn (User $u) => AdminPresenter::userRow($u, $gmv), ['counts' => [
            'all' => $this->tabQuery('all')->count(),
            'verified' => $this->tabQuery('verified')->count(),
            'buyers' => $this->tabQuery('buyers')->count(),
            'photographers' => $this->tabQuery('photographers')->count(),
            'aviators' => $this->tabQuery('aviators')->count(),
            'pending_kyc' => $this->tabQuery('pending_kyc')->count(),
            'suspended' => $this->tabQuery('suspended')->count(),
        ]]);
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(AdminPresenter::userDetail($this->find($id)));
    }

    /** `POST /admin/users/invitations` */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'role' => ['nullable', Rule::in(['user', 'aviator'])]]);
        $email = mb_strtolower($data['email']);
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ApiException::validation(['email' => 'An account with this email already exists.']);
        }

        $token = Str::random(48);
        $invitation = Invitation::query()->create([
            'kind' => 'user', 'email' => $email, 'role' => $data['role'] ?? 'user',
            'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(14),
        ]);
        $this->notifications->emailAddress($email, 'auth.invitation', ['inviter' => $request->user()->shortName(), 'token' => $token], null, "invite:{$invitation->id}");
        $this->audit->log('USER.INVITED', $invitation, $email);

        return ApiResponse::created(['id' => $invitation->id, 'email' => $email, 'expires_at' => $invitation->expires_at->toIso8601ZuluString()], 'Invitation sent.');
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:2000']], ['reason.required' => 'Give a reason for the suspension.']);
        $user = $this->find($id);
        $this->assertNotPrivileged($request->user(), $user);

        DB::transaction(function () use ($user, $data, $request) {
            $user->status->assertCanTransitionTo(UserStatus::Suspended, 'This account is already suspended.');
            $before = ['status' => $user->status->value];
            $user->forceFill(['status' => UserStatus::Suspended, 'suspended_at' => now(), 'status_reason' => $data['reason']])->save();
            if (! empty($data['note'])) {
                UserNote::query()->create(['user_id' => $user->id, 'author_id' => $request->user()->id, 'body' => $data['note']]);
            }
            $this->sessions->revokeAll($user);
            Listing::query()->where('seller_id', $user->id)->where('status', 'active')->update(['status' => 'paused']);
            $this->audit->log('USER.SUSPENDED', $user, $data['reason'], 'danger', $before, ['status' => 'suspended']);
        });
        $this->notifications->notify($user, 'account.suspended', ['reason' => $data['reason']], 'account.suspended:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Account suspended.');
    }

    public function reinstate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $user = $this->find($id);

        DB::transaction(function () use ($user, $data) {
            if (! in_array($user->status, [UserStatus::Suspended, UserStatus::Restricted, UserStatus::Deactivated], true)) {
                throw ApiException::invalidTransition('This account is already active.');
            }
            $before = ['status' => $user->status->value];
            $user->forceFill(['status' => UserStatus::Active, 'suspended_at' => null, 'status_reason' => null])->save();
            $this->audit->log('USER.REINSTATED', $user, $data['reason'], 'success', $before, ['status' => 'active']);
        });
        $this->notifications->notify($user, 'account.reinstated', [], 'account.reinstated:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Account reinstated.');
    }

    /** `POST /admin/users/{id}/restrict` — selling and/or withdrawals off. */
    public function restrict(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'can_list' => ['nullable', 'boolean'],
            'can_receive_payouts' => ['nullable', 'boolean'],
            'can_trade' => ['nullable', 'boolean'],
        ]);
        $user = $this->find($id);
        $this->assertNotPrivileged($request->user(), $user);

        DB::transaction(function () use ($user, $data) {
            $before = $user->limits();
            $limits = array_merge($user->limits ?? [], [
                'can_list' => $data['can_list'] ?? false,
                'can_receive_payouts' => $data['can_receive_payouts'] ?? false,
                'can_trade' => $data['can_trade'] ?? true,
            ]);
            $user->forceFill(['status' => UserStatus::Restricted, 'limits' => $limits, 'status_reason' => $data['reason']])->save();
            $this->audit->log('USER.RESTRICTED', $user, $data['reason'], 'warning', $before, $limits);
        });
        $this->notifications->notify($user, 'account.restricted', ['reason' => $data['reason']], 'account.restricted:'.$user->id.':'.now()->timestamp);

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Restrictions applied.');
    }

    public function limits(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'can_list' => ['sometimes', 'boolean'],
            'can_receive_payouts' => ['sometimes', 'boolean'],
            'can_trade' => ['sometimes', 'boolean'],
            'instant_payouts' => ['sometimes', 'boolean'],
            'daily_payout_limit' => ['sometimes', 'integer', 'min:0'],
        ]);
        $user = $this->find($id);
        $before = $user->limits();
        $user->forceFill(['limits' => array_merge($user->limits ?? [], $data)])->save();
        $this->audit->log('USER.LIMITS_CHANGED', $user, implode(', ', array_keys($data)), 'warning', $before, $user->limits());

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Limits updated.');
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Write a note.']);
        $user = $this->find($id);
        UserNote::query()->create(['user_id' => $user->id, 'author_id' => $request->user()->id, 'body' => $data['body']]);
        $this->audit->log('USER.NOTE_ADDED', $user, Str::limit($data['body'], 80));

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Note added.');
    }

    /** `POST /admin/users/{id}/impersonate` — read-only 15-minute token; visible to the user. */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user->isStaff()) {
            throw ApiException::forbidden('Staff accounts cannot be impersonated.');
        }

        $minutes = (int) config('platform.auth.impersonation_minutes');
        $token = $user->createToken('impersonation:'.$request->user()->id, ['read-only'], now()->addMinutes($minutes));
        AuthSession::query()->create([
            'user_id' => $user->id, 'token_id' => $token->accessToken->id, 'device' => 'Support session ('.$request->user()->shortName().')',
            'is_impersonation' => true, 'impersonator_id' => $request->user()->id, 'expires_at' => now()->addMinutes($minutes), 'last_active_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $this->audit->log('USER.IMPERSONATED', $user, "{$minutes} minutes, read-only", 'warning');
        $this->notifications->notify($user, 'security.impersonated', ['staff' => $request->user()->shortName()], 'security.impersonated:'.$token->accessToken->id);

        return ApiResponse::ok(['access_token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => $minutes * 60, 'read_only' => true]);
    }

    /** `POST /admin/users/{id}/role` — super_admin can never be granted here. */
    public function role(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['user', 'aviator', 'admin'])],
            'staff_role_id' => ['required_if:role,admin', 'nullable', 'string', 'max:40'],
        ]);
        $user = $this->find($id);
        if ($user->isSuperAdmin()) {
            throw ApiException::conflict('Super admin roles are managed from Team & roles.');
        }
        if ($user->id === $request->user()->id) {
            throw ApiException::conflict('You cannot change your own role.');
        }
        if (! empty($data['staff_role_id']) && ! StaffRole::query()->whereKey($data['staff_role_id'])->where('is_system', false)->exists()) {
            throw ApiException::validation(['staff_role_id' => 'Choose a staff role.']);
        }

        DB::transaction(function () use ($user, $data) {
            $before = ['role' => $user->role->value, 'staff_role_id' => $user->staff_role_id];
            $user->forceFill(['role' => UserRole::from($data['role']), 'staff_role_id' => $data['role'] === 'admin' ? $data['staff_role_id'] : null])->save();
            $user->bumpPermissionsVersion();
            $this->sessions->revokeAll($user);
            $this->audit->log('USER.ROLE_CHANGED', $user, "{$before['role']} → {$data['role']}", 'danger', $before, $data);
        });

        return ApiResponse::ok(AdminPresenter::userDetail($user->fresh()), 'Role updated.');
    }

    private function tabQuery(string $tab): Builder
    {
        $q = User::query();

        return match ($tab) {
            'verified' => $q->whereHas('kyc', fn ($k) => $k->where('status', 'approved')),
            'buyers' => $q->whereIn('role', ['user', 'aviator'])->where('sales_count', 0),
            'photographers' => $q->where('seller_type', 'photographer'),
            'aviators' => $q->where('role', 'aviator'),
            'pending_kyc' => $q->whereHas('kyc', fn ($k) => $k->whereIn('status', ['pending', 'escalated'])),
            'suspended' => $q->where('status', 'suspended'),
            'staff' => $q->whereIn('role', ['admin', 'super_admin']),
            default => $q,
        };
    }

    private function find(string $id): User
    {
        return User::query()->with(['kyc', 'staffRole', 'aviatorCredential'])->whereKey($id)->first() ?? throw ApiException::notFound('User not found.');
    }

    /** Admins cannot act on super admins or on themselves. */
    private function assertNotPrivileged(User $actor, User $target): void
    {
        if ($target->id === $actor->id) {
            throw ApiException::conflict('You cannot do this to your own account.');
        }
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
    }
}
