<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\AviatorStatus;
use App\Enums\KycStatus;
use App\Http\Resources\MediaAssetPresenter;
use App\Models\AuditLog;
use App\Models\AviatorCredential;
use App\Models\KycVerification;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Report;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\UserNote;
use App\Models\Wallet;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Control Tower row/detail shapes (docs/api-payloads/admin.md). Staff views may include
 * contact details but never raw identity documents (those use signed URLs) or secrets.
 */
final class AdminPresenter
{
    public static function code(string $prefix, string $id): string
    {
        return $prefix.'-'.strtoupper(substr($id, -6));
    }

    public static function risk(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 30 ? 'medium' : 'low');
    }

    public static function roleLabel(User $user): string
    {
        if ($user->isStaff()) {
            return $user->isSuperAdmin() ? 'Super admin' : ($user->staffRole->name ?? 'Staff');
        }

        return match (true) {
            $user->isOfficialStore() => 'Official store',
            $user->seller_type === 'photographer' => 'Photographer',
            $user->isAviator() => 'Aviator',
            $user->isKycApproved() && $user->sales_count > 0 => 'Verified seller',
            $user->sales_count > 0 => 'Seller',
            default => 'Buyer',
        };
    }

    /** @param array<string, int> $gmv user id => seller GMV (USD minor) */
    public static function userRow(User $user, array $gmv = []): array
    {
        return [
            'id' => $user->id,
            'code' => self::code('U', $user->id),
            'display_name' => $user->displayName(),
            'initials' => $user->initials(),
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => self::roleLabel($user),
            'kyc_status' => $user->kycStatus()->value,
            'country_code' => $user->country_code,
            'country_name' => $user->country_code ? (locale_get_display_region('-'.$user->country_code, 'en') ?: $user->country_code) : null,
            'trades' => (int) $user->sales_count,
            'gmv' => Money::of((int) ($gmv[$user->id] ?? 0), 'USD')->toArray(),
            'risk' => self::risk((int) $user->risk_score),
            'status' => $user->status->value,
            'joined_at' => $user->created_at?->toIso8601ZuluString(),
        ];
    }

    public static function userDetail(User $user): array
    {
        $sold = Order::query()->where('seller_id', $user->id)->whereNotNull('paid_at');
        $bought = Order::query()->where('buyer_id', $user->id)->whereNotNull('paid_at');
        $gmvSold = (int) (clone $sold)->where('currency', 'USD')->sum('items_amount');
        $wallet = Wallet::query()->where('user_id', $user->id)->orderByDesc('available_amount')->first();
        $disputes = DB::table('disputes')->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->selectRaw("count(*) as total, count(*) filter (where status in ('open','awaiting_seller','under_review','appealed')) as open,
                count(*) filter (where (status = 'resolved_seller' and seller_id = ?) or (status = 'resolved_buyer' and buyer_id = ?)) as won", [$user->id, $user->id])->first();
        $kyc = $user->kyc;
        $limits = $user->limits();

        return self::userRow($user, [$user->id => $gmvSold]) + [
            'phone_masked' => $user->phone ? substr($user->phone, 0, 4).' •••• '.substr($user->phone, -4) : null,
            'city' => trim(($user->city ? $user->city.', ' : '').($user->country_code ?? ''), ', ') ?: null,
            'signup_method' => ucfirst($user->signup_method),
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'staff_role_id' => $user->staff_role_id,
            'stats' => [
                'gmv_sold' => Money::of($gmvSold, 'USD')->toArray(),
                'trades_sold' => (clone $sold)->count(),
                'gmv_bought' => Money::of((int) (clone $bought)->where('currency', 'USD')->sum('items_amount'), 'USD')->toArray(),
                'orders_bought' => (clone $bought)->count(),
                'rating' => round((float) $user->rating_avg, 1),
                'reviews' => (int) $user->reviews_count,
                'disputes' => (int) ($disputes->total ?? 0),
                'disputes_open' => (int) ($disputes->open ?? 0),
                'disputes_won' => (int) ($disputes->won ?? 0),
                'listings' => Listing::query()->where('seller_id', $user->id)->count(),
                'wallet' => Money::of((int) ($wallet->available_amount ?? 0), $wallet->currency ?? 'USD')->toArray(),
                'wallet_escrow' => Money::of((int) ($wallet->escrow_amount ?? 0), $wallet->currency ?? 'USD')->toArray(),
            ],
            'identity' => $kyc ? [
                'kyc_id' => $kyc->id,
                'document_label' => $kyc->document_type ? (config('kyc.document_types')[$kyc->document_type] ?? $kyc->document_type).($kyc->document_country ? ' · '.$kyc->document_country : '') : null,
                'checks' => array_map(fn ($c) => ['label' => $c['label'], 'detail' => $c['result'] ?? null, 'passed' => (bool) ($c['passed'] ?? false)], $kyc->automated_checks ?? []),
                'image' => null,
            ] : null,
            'aviator' => $user->aviatorCredential ? ['status' => $user->aviatorCredential->status->value, 'licence_type' => $user->aviatorCredential->licence_type] : null,
            'risk_score' => (int) $user->risk_score,
            'risk_detail' => ucfirst(self::risk((int) $user->risk_score)).' · '.DB::table('known_devices')->where('user_id', $user->id)->count().' device fingerprint(s)',
            'limits' => [
                'can_list' => (bool) $limits['can_list'],
                'can_receive_payouts' => (bool) $limits['can_receive_payouts'],
                'can_trade' => (bool) $limits['can_trade'],
                'instant_payouts' => (bool) $limits['instant_payouts'],
                'daily_payout_limit' => Money::of((int) $limits['daily_payout_limit'], 'USD')->toArray(),
            ],
            'status_reason' => $user->status_reason,
            'activity' => AuditLog::query()->where(fn ($q) => $q->where('actor_id', $user->id)->orWhere(fn ($t) => $t->where('target_type', 'user')->where('target_id', $user->id)))
                ->latest('created_at')->limit(10)->get()
                ->map(fn (AuditLog $a) => [
                    'id' => $a->id,
                    'label' => str_replace(['.', '_'], [' · ', ' '], ucfirst(strtolower($a->action))).($a->detail ? ' — '.$a->detail : ''),
                    'detail' => $a->created_at->format('M j H:i').($a->ip_address ? ' · IP '.preg_replace('/\.\d+\.\d+$/', '.•.•', $a->ip_address) : ''),
                    'state' => in_array($a->severity, ['danger', 'warning'], true) ? 'flag' : 'ok',
                ])->values()->all(),
            'notes' => UserNote::query()->where('user_id', $user->id)->with('author')->latest()->limit(20)->get()
                ->map(fn (UserNote $n) => [
                    'id' => $n->id,
                    'author' => $n->author?->shortName(),
                    'initials' => $n->author?->initials(),
                    'body' => $n->body,
                    'created_at' => $n->created_at?->toIso8601ZuluString(),
                ])->values()->all(),
        ];
    }

    public static function kycItem(KycVerification $kyc, bool $withDocuments = false): array
    {
        $user = $kyc->user;
        $age = $kyc->submitted_at ? (int) $kyc->submitted_at->diffInHours(now()) : 0;
        $checks = $kyc->automated_checks ?? [];

        $out = [
            'id' => $kyc->id,
            'code' => $kyc->code,
            'user_id' => $kyc->user_id,
            'display_name' => $user->displayName(),
            'initials' => $user->initials(),
            'document_type' => $kyc->document_type,
            'document_label' => $kyc->document_type ? (config('kyc.document_types')[$kyc->document_type] ?? $kyc->document_type).($kyc->document_country ? ' · '.$kyc->document_country : '') : null,
            'country_code' => $kyc->document_country ?? $user->country_code,
            'age_hours' => $age,
            'over_sla' => $kyc->status->isInReview() && $age > (int) config('kyc.review_sla_hours'),
            'status' => $kyc->status->isInReview() ? ($kyc->status === KycStatus::Escalated ? 'escalated' : 'pending') : ($kyc->status === KycStatus::MoreInfo ? 'more_info' : 'done'),
            'kyc_status' => $kyc->status->value,
            'submitted_at' => $kyc->submitted_at?->toIso8601ZuluString(),
            'checks_status' => $kyc->checks_status,
            'auto_checks_passed' => count(array_filter($checks, fn ($c) => $c['passed'] ?? false)),
            'auto_checks_total' => count($checks),
        ];

        if ($withDocuments) {
            $out += [
                'document' => MediaAssetPresenter::make($kyc->documentFront),
                'document_back' => MediaAssetPresenter::make($kyc->documentBack),
                'selfie' => MediaAssetPresenter::make($kyc->selfie),
                'personal' => [
                    'first_name' => $kyc->legal_first_name,
                    'last_name' => $kyc->legal_last_name,
                    'date_of_birth' => $kyc->date_of_birth,
                    'nationality' => $kyc->nationality,
                    'document_number_masked' => $kyc->document_number ? str_repeat('•', max(0, strlen($kyc->document_number) - 4)).substr($kyc->document_number, -4) : null,
                    'document_expires_on' => $kyc->document_expires_on?->toDateString(),
                ],
                'extracted' => $kyc->extracted ?? [],
                'automated_checks' => $checks,
                'reviewer_note' => $kyc->reviewer_note,
                'rejection_reason' => $kyc->rejection_reason,
            ];
        }

        return $out;
    }

    public static function orderRow(Order $order): array
    {
        $item = $order->items->first();
        $count = $order->items->sum('quantity');

        return [
            'id' => $order->id,
            'number' => $order->number,
            'item_title' => $item ? $item->title.($count > 1 ? ' ×'.$count : '') : null,
            'item_image' => MediaAssetPresenter::make($item?->image, $item?->title),
            'buyer_name' => $order->buyer->shortName(),
            'seller_name' => $order->seller->shortName(),
            'total' => $order->total()->toArray(),
            'status' => $order->status->value,
            'escrow_status' => $order->escrow_status->value,
            'escrow_label' => $order->escrow_status->label().($order->hold_reason ? ' · '.$order->hold_reason : ''),
            'freight_label' => $order->carrier ? $order->carrier.' · '.$order->tracking_number : ($order->status->value === 'awaiting_dispatch' ? 'Awaiting dispatch' : '—'),
            'age_label' => self::age($order->created_at),
            'risk' => $order->risk,
            'kind' => $order->kind,
        ];
    }

    public static function orderDetail(Order $order): array
    {
        $payment = $order->successfulPayment ?? $order->latestPayment;
        $ledger = DB::table('ledger_entries')->where('order_id', $order->id)->orderBy('occurred_at')->get()
            ->map(fn ($e) => ['at' => Carbon::parse($e->occurred_at)->toIso8601ZuluString(), 'entry' => $e->description.' ('.$e->status.')',
                'amount' => Money::of((int) $e->amount, $e->currency)->toArray()]);
        $payments = $order->payments()->get()->map(fn ($p) => ['at' => $p->created_at->toIso8601ZuluString(),
            'entry' => ucfirst($p->gateway).' payment '.$p->status->value.' · '.$p->reference, 'amount' => $p->money()->toArray()]);

        return self::orderRow($order) + [
            'created_at' => $order->created_at?->toIso8601ZuluString(),
            'payment_gateway' => $payment?->gateway,
            'payment_reference' => $payment?->reference,
            'gateway_reference' => $payment?->gateway_reference,
            'ledger' => $payments->concat($ledger)->sortBy('at')->values()->all(),
            'risk_signals' => $order->risk_signals ?? [],
            'breakdown' => [
                'subtotal' => $order->money($order->items_amount)->toArray(),
                'freight' => $order->money($order->freight_amount)->toArray(),
                'buyer_protection' => $order->money($order->buyer_protection_amount)->toArray(),
                'platform_fee' => $order->money($order->platform_fee_amount)->toArray(),
                'seller_would_receive' => $order->money($order->seller_net_amount)->toArray(),
                'refunded' => $order->money($order->refunded_amount)->toArray(),
                'total' => $order->total()->toArray(),
            ],
            'parties' => [
                ['role' => 'buyer', 'id' => $order->buyer->id, 'name' => $order->buyer->displayName(), 'risk' => self::risk((int) $order->buyer->risk_score),
                    'detail' => 'Buyer · KYC '.str_replace('_', ' ', $order->buyer->kycStatus()->value).' · risk '.strtoupper(self::risk((int) $order->buyer->risk_score))],
                ['role' => 'seller', 'id' => $order->seller->id, 'name' => $order->seller->displayName(), 'risk' => self::risk((int) $order->seller->risk_score),
                    'detail' => 'Seller · '.($order->seller->isKycApproved() ? 'verified' : 'unverified').' · risk '.strtoupper(self::risk((int) $order->seller->risk_score))],
            ],
            'refund_method_label' => $payment?->method_label,
            'refundable' => $order->money($order->total_amount - $order->refunded_amount)->toArray(),
            'timeline' => $order->events->map(fn ($e) => ['code' => $e->code, 'label' => $e->label, 'detail' => $e->detail, 'occurred_at' => $e->occurred_at->toIso8601ZuluString()])->values()->all(),
            'shipping_address' => $order->shipping_address,
        ];
    }

    public static function payoutRow(Payout $payout): array
    {
        $user = $payout->user;
        $method = $payout->payoutMethod;

        return [
            'id' => $payout->id,
            'reference' => $payout->reference,
            'seller_id' => $user->id,
            'seller_name' => $user->displayName(),
            'initials' => $user->initials(),
            'seller_detail' => self::roleLabel($user).' · '.$user->sales_count.' trades',
            'amount' => $payout->money()->toArray(),
            'destination' => trim(($method->label ?? '—').($method?->last4 ? ' •••• '.$method->last4 : '')),
            'destination_amount' => Money::of($payout->destination_amount, $payout->destination_currency)->toArray(),
            'reason' => $payout->approval_reason ?? $payout->hold_reason ?? $payout->failure_reason,
            'requested_at' => $payout->created_at?->toIso8601ZuluString(),
            'flagged' => (bool) $payout->flagged,
            'status' => $payout->status->value,
            'provider' => $payout->provider,
        ];
    }

    public static function reportRow(Report $report): array
    {
        return [
            'id' => $report->id,
            'severity' => $report->severity,
            'title' => ucfirst(str_replace('_', ' ', $report->reason)),
            'detail' => $report->detail,
            'target' => ucfirst($report->target_type).' '.$report->target_id,
            'target_type' => $report->target_type,
            'target_id' => $report->target_id,
            'source' => $report->source === 'automated' ? 'Automated detection' : 'User reports',
            'reported_ago' => self::age($report->created_at),
            'assignee' => $report->assignee ? ['initials' => $report->assignee->initials(), 'name' => $report->assignee->shortName()] : null,
            'primary_action' => match ($report->target_type) {
                'listing' => ['key' => 'remove', 'label' => 'Remove', 'tone' => 'danger'],
                'user' => ['key' => 'suspend', 'label' => 'Suspend', 'tone' => 'danger'],
                default => ['key' => 'warn', 'label' => 'Warn', 'tone' => 'warning'],
            },
            'type' => $report->target_type,
            'status' => $report->status,
            'resolution' => $report->resolution,
        ];
    }

    public static function auditRow(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'at' => $log->created_at->toIso8601ZuluString(),
            'actor' => [
                'name' => $log->actor_is_system ? 'System' : ($log->actor_name ?? 'Unknown'),
                'initials' => $log->actor_is_system ? 'SY' : collect(explode(' ', (string) $log->actor_name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode(''),
                'system' => (bool) $log->actor_is_system,
            ],
            'action' => $log->action,
            'tone' => $log->severity,
            'target' => $log->target_label ?? $log->target_id,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'details' => $log->detail,
            'ip' => $log->ip_address,
            'request_id' => $log->request_id,
        ];
    }

    public static function role(StaffRole $role, int $members): array
    {
        $matrix = $role->is_system ? array_fill_keys(array_keys(config('permissions.matrix')), 'manage') : ($role->matrix ?? []);

        return [
            'id' => $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            'system' => (bool) $role->is_system,
            'members' => $members,
            'matrix' => $matrix,
        ];
    }

    public static function age(?\DateTimeInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }
        $minutes = (int) floor((time() - $at->getTimestamp()) / 60);

        return match (true) {
            $minutes < 60 => max(1, $minutes).'m',
            $minutes < 1440 => intdiv($minutes, 60).'h',
            default => intdiv($minutes, 1440).'d',
        };
    }

    public static function aviator(AviatorCredential $c): array
    {
        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'display_name' => $c->user->displayName(),
            'licence_type' => $c->licence_type,
            'licence_number' => $c->licence_number,
            'issuing_authority' => $c->issuing_authority,
            'job_title' => $c->job_title,
            'organisation' => $c->organisation,
            'type_ratings' => $c->type_ratings ?? [],
            'licence' => MediaAssetPresenter::make($c->licence),
            'status' => $c->status->value,
            'submitted_at' => $c->submitted_at?->toIso8601ZuluString(),
            'pending' => $c->status === AviatorStatus::Pending,
        ];
    }
}
