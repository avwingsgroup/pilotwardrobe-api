<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPresenter;
use App\Integrations\Payments\GatewayRegistry;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\MaintenanceWindow;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Admin\ApprovalService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\PlatformSettings;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Team & roles (AD18), audit log (AD19), platform settings and maintenance (AD20). */
final class AdminPlatformController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- roles

    public function roles(): JsonResponse
    {
        $members = User::query()->where('role', 'admin')->whereNotNull('staff_role_id')->groupBy('staff_role_id')->selectRaw('staff_role_id, count(*) as n')->pluck('n', 'staff_role_id');
        $supers = User::query()->where('role', 'super_admin')->count();

        return ApiResponse::ok(StaffRole::query()->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (StaffRole $r) => AdminPresenter::role($r, $r->is_system ? $supers : (int) ($members[$r->id] ?? 0)))->values());
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'matrix' => ['required', 'array'], ...$this->matrixRules()]);
        $role = StaffRole::query()->create(['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)), 'name' => $data['name'], 'is_system' => false, 'matrix' => $this->normaliseMatrix($data['matrix'])]);
        $this->audit->log('ROLE.CREATED', $role, $role->name, 'warning', after: $role->matrix);

        return ApiResponse::created(AdminPresenter::role($role, 0));
    }

    /** `PATCH /admin/roles/{id}` — the super admin role is immutable (409). */
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $role = StaffRole::query()->find($id) ?? throw ApiException::notFound('Role not found.');
        if ($role->is_system) {
            throw ApiException::conflict('The Super admin role cannot be edited.');
        }
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'matrix' => ['sometimes', 'array'], ...$this->matrixRules()]);

        DB::transaction(function () use ($role, $data) {
            $before = $role->matrix;
            $role->fill(array_filter(['name' => $data['name'] ?? null, 'matrix' => isset($data['matrix']) ? $this->normaliseMatrix($data['matrix']) : null]))->save();
            // Every member's effective permissions change: invalidate their caches.
            User::query()->where('staff_role_id', $role->id)->each(fn (User $u) => $u->bumpPermissionsVersion());
            $this->audit->log('ROLE.UPDATED', $role, 'Permission matrix changed', 'warning', $before, $role->matrix);
        });

        return ApiResponse::ok(AdminPresenter::role($role->fresh(), User::query()->where('staff_role_id', $role->id)->count()));
    }

    // ---------------------------------------------------------------- team

    public function team(Request $request): JsonResponse
    {
        $query = User::query()->with('staffRole')->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])->orderBy('first_name');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request, 50))->withQueryString(), fn (User $u) => [
            'id' => $u->id,
            'display_name' => $u->displayName(),
            'initials' => $u->initials(),
            'email' => $u->email,
            'role' => $u->role->value,
            'staff_role' => $u->isSuperAdmin() ? ['id' => null, 'name' => 'Super admin'] : ($u->staffRole ? ['id' => $u->staffRole->id, 'name' => $u->staffRole->name] : null),
            'status' => $u->status->value,
            'security_key' => DB::table('webauthn_credentials')->where('user_id', $u->id)->exists(),
            'last_login_at' => $u->last_login_at?->toIso8601ZuluString(),
        ], ['counts' => ['pending_invitations' => Invitation::query()->where('kind', 'staff')->whereNull('accepted_at')->where('expires_at', '>', now())->count()]]);
    }

    /** `POST /admin/team/invitations` — staff accounts must use the company domain. */
    public function inviteStaff(Request $request, NotificationService $notifications): JsonResponse
    {
        $domain = (string) config('platform.staff_email_domain');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254', 'ends_with:@'.$domain],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'staff_role_id' => ['required', 'string', 'max:40'],
        ], ['email.ends_with' => "Staff must use a @{$domain} address."]);
        $role = StaffRole::query()->whereKey($data['staff_role_id'])->where('is_system', false)->first()
            ?? throw ApiException::validation(['staff_role_id' => 'Choose a staff role.']);
        $email = mb_strtolower($data['email']);

        $staff = DB::transaction(function () use ($data, $email, $role, $request) {
            $staff = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($staff && ! $staff->isStaff() && $staff->sales_count > 0) {
                throw ApiException::conflict('This email belongs to a member account. Use a dedicated staff address.');
            }
            $staff ??= new User([
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $email,
                'username' => 'staff_'.Str::lower(Str::random(8)), 'signup_method' => 'staff_invite',
            ]);
            $staff->forceFill(['role' => UserRole::Admin, 'staff_role_id' => $role->id, 'status' => UserStatus::Active, 'email_verified_at' => now()])->save();
            $staff->bumpPermissionsVersion();

            $token = Str::random(48);
            Invitation::query()->create(['kind' => 'staff', 'email' => $email, 'role' => 'admin', 'staff_role_id' => $role->id,
                'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(7)]);
            $this->audit->log('STAFF.INVITED', $staff, $role->name, 'warning');

            return $staff;
        });

        $notifications->emailAddress($email, 'staff.invitation', ['inviter' => $request->user()->shortName(), 'role' => $role->name], $staff->displayName(), 'staff.invite:'.$staff->id.':'.now()->timestamp);

        return ApiResponse::created(['id' => $staff->id, 'email' => $email, 'staff_role' => $role->name], 'Invitation sent.');
    }

    /** `PATCH /admin/team/{id}` — change role or deactivate; the last super admin is protected (409). */
    public function updateMember(Request $request, string $id, SessionManager $sessions): JsonResponse
    {
        $data = $request->validate([
            'staff_role_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'role' => ['sometimes', Rule::in(['admin', 'super_admin', 'user'])],
            'status' => ['sometimes', Rule::in(['active', 'deactivated'])],
        ]);
        $member = User::query()->whereKey($id)->whereIn('role', ['admin', 'super_admin'])->first() ?? throw ApiException::notFound('Team member not found.');
        $actor = $request->user();

        if (($data['role'] ?? null) === 'super_admin' && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
        $demotingSuper = $member->isSuperAdmin() && ((isset($data['role']) && $data['role'] !== 'super_admin') || ($data['status'] ?? null) === 'deactivated');
        if ($demotingSuper && User::query()->where('role', 'super_admin')->where('status', 'active')->count() <= 1) {
            throw ApiException::conflict('You cannot demote or deactivate the last super admin.');
        }
        if ($member->id === $actor->id && ($demotingSuper || ($data['status'] ?? null) === 'deactivated')) {
            throw ApiException::conflict('You cannot demote or deactivate yourself.');
        }

        DB::transaction(function () use ($member, $data, $sessions) {
            $before = ['role' => $member->role->value, 'staff_role_id' => $member->staff_role_id, 'status' => $member->status->value];
            if (isset($data['role'])) {
                $member->role = UserRole::from($data['role']);
                if ($data['role'] !== 'admin') {
                    $member->staff_role_id = null;
                }
            }
            if (array_key_exists('staff_role_id', $data) && $member->role === UserRole::Admin) {
                if ($data['staff_role_id'] && ! StaffRole::query()->whereKey($data['staff_role_id'])->where('is_system', false)->exists()) {
                    throw ApiException::validation(['staff_role_id' => 'Choose a staff role.']);
                }
                $member->staff_role_id = $data['staff_role_id'];
            }
            if (isset($data['status'])) {
                $member->status = UserStatus::from($data['status']);
            }
            $member->save();
            $member->bumpPermissionsVersion();
            if (($data['status'] ?? null) === 'deactivated' || isset($data['role'])) {
                $sessions->revokeAll($member);
            }
            $this->audit->log('STAFF.UPDATED', $member, json_encode(array_intersect_key($data, $before)), 'danger', $before, ['role' => $member->role->value, 'staff_role_id' => $member->staff_role_id, 'status' => $member->status->value]);
        });

        return ApiResponse::ok(['id' => $member->id, 'role' => $member->role->value, 'staff_role_id' => $member->staff_role_id, 'status' => $member->status->value]);
    }

    // ---------------------------------------------------------------- audit (read-only)

    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', strtoupper((string) $a).'%'))
            ->when($request->query('actor_id'), fn ($q, $a) => $q->where('actor_id', $a))
            ->when($request->query('target_id'), fn ($q, $t) => $q->where('target_id', $t))
            ->when($request->query('severity'), fn ($q) => $q->whereIn('severity', QueryParams::list($request, 'severity')))
            ->when($request->query('date_from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when(QueryParams::search($request), fn ($q, $s) => $q->where(fn ($w) => $w->where('detail', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('target_label', 'ilike', '%'.addcslashes($s, '%_\\').'%')))
            ->orderByDesc('created_at')->orderByDesc('id');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (AuditLog $l) => AdminPresenter::auditRow($l));
    }

    // ---------------------------------------------------------------- settings

    public function settings(PlatformSettings $settings, GatewayRegistry $gateways): JsonResponse
    {
        $all = $settings->all();

        return ApiResponse::ok([
            'markets' => array_map(fn ($m) => $m + ['detail' => ($m['currency'] ?? '').' · '.implode(' + ', array_column(array_filter($all['gateways'], fn ($g) => in_array($m['country_code'], $g['countries'] ?? [], true) || in_array('*', $g['countries'] ?? [], true)), 'name'))], $all['markets']),
            'display_currencies' => $all['display_currencies'],
            'settlement_currency' => $all['settlement_currency'],
            'fx_margin_percent' => $all['fx_margin_percent'],
            'feature_flags' => $all['feature_flags'],
            'gateways' => array_map(function ($g) use ($gateways) {
                $gateway = $gateways->get($g['code']);
                $key = (string) $gateway->publicKey();

                return $g + [
                    'configured' => $gateway->isConfigured(),
                    'mode' => str_contains($key, 'live') ? 'live' : 'test',
                    'public_key_masked' => $key !== '' ? substr($key, 0, 8).'••••'.substr($key, -4) : null,
                    'webhook_status' => $this->webhookHealth($g['code']),
                ];
            }, $all['gateways']),
            'moderation' => $all['moderation'],
            'dual_control' => $all['dual_control'],
            'withdrawals' => $all['withdrawals'],
            'maintenance' => $this->maintenance(),
        ]);
    }

    /** `PATCH /admin/settings` — gateway/escrow/dual-control changes require a second approver. */
    public function updateSettings(Request $request, PlatformSettings $settings, ApprovalService $approvals): JsonResponse
    {
        $data = $request->validate([
            'markets' => ['sometimes', 'array'],
            'markets.*.country_code' => ['required_with:markets', 'string', 'size:2'],
            'markets.*.enabled' => ['required_with:markets', 'boolean'],
            'display_currencies' => ['sometimes', 'array'],
            'fx_margin_percent' => ['sometimes', 'numeric', 'between:0,10'],
            'feature_flags' => ['sometimes', 'array'],
            'feature_flags.*.key' => ['required_with:feature_flags', 'string'],
            'feature_flags.*.enabled' => ['required_with:feature_flags', 'boolean'],
            'moderation' => ['sometimes', 'array'],
            'gateways' => ['sometimes', 'array'],
            'gateways.*.code' => ['required_with:gateways', Rule::in(['paystack', 'flutterwave', 'monnify', 'stripe', 'paypal'])],
            'gateways.*.enabled' => ['sometimes', 'boolean'],
            'gateways.*.currencies' => ['sometimes', 'array'],
            'gateways.*.countries' => ['sometimes', 'array'],
            'dual_control' => ['sometimes', 'array'],
            'withdrawals' => ['sometimes', 'array'],
        ]);

        $current = $settings->all();
        $immediate = [];
        $sensitive = [];
        foreach ($data as $key => $value) {
            $merged = match ($key) {
                'markets', 'feature_flags', 'gateways' => $this->mergeList($current[$key], $value, $key === 'feature_flags' ? 'key' : ($key === 'markets' ? 'country_code' : 'code')),
                'moderation', 'dual_control', 'withdrawals' => array_merge($current[$key], $value),
                default => $value,
            };
            if (in_array($key, ['gateways', 'dual_control', 'withdrawals'], true)) {
                if (! $request->user()->hasPermission('payment_gateways.manage') && $key === 'gateways') {
                    throw ApiException::forbidden();
                }
                $sensitive[$key] = $merged;
            } else {
                $immediate[$key] = $merged;
            }
        }

        foreach ($immediate as $key => $value) {
            $settings->set($key, $value);
        }
        if ($immediate !== []) {
            $this->audit->log('SETTINGS.UPDATED', 'platform_settings', implode(', ', array_keys($immediate)), 'warning', array_intersect_key($current, $immediate), $immediate);
            Cache::forget('home:feed');
        }

        if ($sensitive !== []) {
            return ApiResponse::ok($approvals->request('settings', 'settings.manage', $request->user(), $sensitive, 'Settings: '.implode(', ', array_keys($sensitive))),
                'Payment settings change submitted — a second approver must confirm.');
        }

        return ApiResponse::ok(['status' => 'applied'], 'Settings saved.');
    }

    /** `POST /admin/maintenance-windows` */
    public function scheduleMaintenance(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'message' => ['nullable', 'string', 'max:255'],
            'notify_users' => ['nullable', 'boolean'],
        ]);
        $window = MaintenanceWindow::query()->create([...$data, 'notify_users' => $data['notify_users'] ?? true, 'created_by' => $request->user()->id]);
        Cache::forget('maintenance:active');
        $this->audit->log('MAINTENANCE.SCHEDULED', $window, $window->starts_at->toDayDateTimeString().' → '.$window->ends_at->toDayDateTimeString(), 'warning');

        if ($window->notify_users) {
            User::query()->whereIn('role', ['user', 'aviator'])->where('status', 'active')->where('last_login_at', '>=', now()->subDays(30))
                ->chunkById(500, function ($users) use ($window, $notifications) {
                    foreach ($users as $user) {
                        $notifications->notify($user, 'system.maintenance_scheduled', [
                            'date' => $window->starts_at->format('D j M'), 'starts' => $window->starts_at->format('H:i'),
                            'ends' => $window->ends_at->format('H:i'), 'message' => (string) $window->message,
                        ], "maintenance:{$window->id}:{$user->id}");
                    }
                });
        }

        return ApiResponse::ok(['id' => $window->id, 'starts_at' => $window->starts_at->toIso8601ZuluString(), 'ends_at' => $window->ends_at->toIso8601ZuluString()], 'Maintenance scheduled.');
    }

    private function maintenance(): array
    {
        $window = MaintenanceWindow::query()->whereNull('cancelled_at')->where('ends_at', '>', now())->orderBy('starts_at')->first();

        return ['scheduled' => $window !== null, 'window' => $window ? ['id' => $window->id, 'starts_at' => $window->starts_at->toIso8601ZuluString(), 'ends_at' => $window->ends_at->toIso8601ZuluString(), 'message' => $window->message] : null];
    }

    private function webhookHealth(string $provider): string
    {
        $recent = DB::table('webhook_events')->where('provider', $provider)->where('created_at', '>=', now()->subDay());
        $total = (clone $recent)->count();
        if ($total === 0) {
            return 'no_recent_events';
        }

        return (clone $recent)->where('status', 'failed')->count() / $total > 0.05 ? 'degraded' : 'healthy';
    }

    private function mergeList(array $current, array $changes, string $key): array
    {
        $byKey = collect($current)->keyBy($key);
        foreach ($changes as $change) {
            if (isset($change[$key])) {
                $byKey[$change[$key]] = array_merge($byKey[$change[$key]] ?? [], $change);
            }
        }

        return $byKey->values()->all();
    }

    private function matrixRules(): array
    {
        $rules = [];
        foreach (array_keys(config('permissions.matrix')) as $area) {
            $rules["matrix.{$area}"] = ['sometimes', Rule::in(['none', 'view', 'manage'])];
        }

        return $rules;
    }

    private function normaliseMatrix(array $matrix): array
    {
        $out = [];
        foreach (array_keys(config('permissions.matrix')) as $area) {
            $out[$area] = in_array($matrix[$area] ?? 'none', ['none', 'view', 'manage'], true) ? ($matrix[$area] ?? 'none') : 'none';
        }

        return $out;
    }
}
