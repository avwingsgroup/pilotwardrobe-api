<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Payouts\TransferProviderRegistry;
use App\Integrations\ProviderException;
use App\Models\LedgerEntry;
use App\Models\PayoutMethod;
use App\Models\PayoutSchedule;
use App\Models\Wallet;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Payouts\PayoutService;
use App\Services\Wallet\StatementService;
use App\Services\Wallet\WalletService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Seller wallet (VB12), payout methods, schedule and withdrawals (VB13). */
final class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PayoutService $payouts,
        private readonly AuditLogger $audit,
    ) {}

    /** `GET /me/wallet?currency=` — defaults to the user's currency; other wallets listed in `wallets`. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $currencies = Wallet::query()->where('user_id', $user->id)->pluck('currency')->all();
        $currency = strtoupper((string) $request->query('currency', in_array($user->currency, $currencies, true) || $currencies === [] ? $user->currency : $currencies[0]));

        $summary = $this->wallets->summary($user, $currency);
        $summary['wallets'] = array_map(fn (string $c) => $this->wallets->summary($user, $c), array_values(array_diff($currencies, [$currency])));

        return ApiResponse::ok($summary);
    }

    /** `GET /me/wallet/transactions?type=&currency=` */
    public function transactions(Request $request): JsonResponse
    {
        $query = LedgerEntry::query()->where('user_id', $request->user()->id)
            ->when($request->query('type'), fn ($q) => $q->whereIn('type', QueryParams::list($request, 'type')))
            ->when($request->query('currency'), fn ($q, $c) => $q->where('currency', strtoupper((string) $c)))
            ->orderByDesc('occurred_at')->orderByDesc('id');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (LedgerEntry $e) => [
            'id' => $e->id,
            'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
            'description' => $e->description,
            'detail' => $e->detail,
            'type' => $e->type,
            'status' => $e->status,
            'amount' => Money::of($e->amount, $e->currency)->toArray(),
            'direction' => $e->direction,
            'order_id' => $e->order_id,
            'payout_id' => $e->payout_id,
        ]);
    }

    /** `GET /me/payout-methods` (masked; full account numbers are never returned). */
    public function payoutMethods(Request $request): JsonResponse
    {
        return ApiResponse::ok(PayoutMethod::query()->where('user_id', $request->user()->id)->orderByDesc('is_default')->latest()->get()
            ->map(fn (PayoutMethod $m) => $this->presentMethod($m))->values());
    }

    /** `POST /me/payout-methods` — validated with the PSP; stores only recipient codes + last 4. */
    public function addPayoutMethod(Request $request, TransferProviderRegistry $providers, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['bank', 'mobile_money', 'paypal', 'wise'])],
            'currency' => ['required', Rule::in(Currency::codes())],
            'country_code' => ['nullable', 'string', 'size:2'],
            'bank_code' => ['required_if:kind,bank', 'required_if:kind,mobile_money', 'nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_number' => ['required_if:kind,bank', 'required_if:kind,mobile_money', 'nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required_if:kind,paypal', 'nullable', 'email'],
            'is_default' => ['nullable', 'boolean'],
        ], ['account_number.regex' => 'Enter digits only.']);
        $user = $request->user();

        $method = new PayoutMethod([
            'user_id' => $user->id,
            'kind' => $data['kind'],
            'currency' => $data['currency'],
            'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : $user->country_code,
            'bank_code' => $data['bank_code'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'account_name' => $data['account_name'] ?? $user->displayName(),
            'account_number' => $data['account_number'] ?? $data['email'] ?? null,
            'last4' => isset($data['account_number']) ? substr($data['account_number'], -4) : null,
            'label' => trim(($data['bank_name'] ?? ucfirst(str_replace('_', ' ', $data['kind']))).' · '.$data['currency']),
        ]);
        $method->provider = $providers->providerCodeFor($method);

        if ($method->provider !== 'manual' && isset($data['account_number'])) {
            try {
                $recipient = $providers->get($method->provider)->createRecipient($method, $data['account_number']);
            } catch (ProviderException $e) {
                throw $e->retryable
                    ? ApiException::gatewayUnavailable('We could not verify the account right now. Try again shortly.')
                    : ApiException::validation(['account_number' => 'We could not verify this account. Check the details.']);
            }
            $method->provider_recipient_code = $recipient['recipient_code'];
            $method->account_name = $recipient['account_name'] ?? $method->account_name;
            $method->verified_at = now();
        }

        DB::transaction(function () use ($method, $data, $user) {
            $makeDefault = ($data['is_default'] ?? false) || ! PayoutMethod::query()->where('user_id', $user->id)->exists();
            if ($makeDefault) {
                PayoutMethod::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }
            $method->is_default = $makeDefault;
            $method->save();
            $this->audit->log('SECURITY.PAYOUT_METHOD_ADDED', $method, $method->label, 'warning', actor: $user);
        });

        $notifications->notify($user, 'security.payout_method_added', ['label' => $method->label.($method->last4 ? ' •••• '.$method->last4 : '')], "payout_method_added:{$method->id}");

        return ApiResponse::created($this->presentMethod($method));
    }

    /** `DELETE /me/payout-methods/{id}` (addition). */
    public function removePayoutMethod(Request $request, string $id): JsonResponse
    {
        $method = PayoutMethod::query()->where('user_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Payout method not found.');
        $method->forceFill(['is_default' => false])->save();
        $method->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    public function schedule(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->presentSchedule(PayoutSchedule::query()->firstOrNew(['user_id' => $request->user()->id])));
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'frequency' => ['required', Rule::in(['manual', 'daily', 'weekly', 'monthly'])],
            'day' => ['nullable', 'integer', 'between:1,28'],
            'minimum_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'payout_method_id' => ['nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        if (! empty($data['payout_method_id']) && ! PayoutMethod::query()->where('user_id', $user->id)->whereKey($data['payout_method_id'])->exists()) {
            throw ApiException::validation(['payout_method_id' => 'Choose one of your payout methods.']);
        }

        $schedule = PayoutSchedule::query()->updateOrCreate(['user_id' => $user->id], [
            'frequency' => $data['frequency'],
            'day' => $data['day'] ?? null,
            'minimum_amount' => $data['minimum_amount'] ?? 0,
            'currency' => $data['currency'] ?? $user->currency,
            'payout_method_id' => $data['payout_method_id'] ?? null,
        ]);

        return ApiResponse::ok($this->presentSchedule($schedule), 'Payout schedule saved.');
    }

    /** `POST /me/withdrawals/quote` */
    public function quote(Request $request): JsonResponse
    {
        [$amount, $method] = $this->withdrawalInput($request);

        return ApiResponse::ok($this->payouts->quote($request->user(), $amount, $method));
    }

    /** `POST /me/withdrawals` — idempotent with `Idempotency-Key`. */
    public function withdraw(Request $request): JsonResponse
    {
        [$amount, $method] = $this->withdrawalInput($request);
        $payout = $this->payouts->request($request->user(), $amount, $method, $request->input('two_factor_code'));
        $entry = LedgerEntry::query()->where('idempotency_key', "payout:{$payout->id}:reserve")->first();

        return ApiResponse::created([
            'id' => $entry->id ?? $payout->id,
            'payout_id' => $payout->id,
            'occurred_at' => now()->toIso8601ZuluString(),
            'description' => $entry?->description,
            'detail' => $payout->requires_approval ? 'Awaiting approval' : 'Processing',
            'type' => 'withdrawal',
            'status' => $payout->status->value === 'awaiting_approval' ? 'pending' : 'pending',
            'requires_approval' => $payout->requires_approval,
            'amount' => $payout->money()->toArray(),
            'direction' => 'debit',
        ], 'Withdrawal requested.');
    }

    /** `POST /me/wallet/statements` — signed URL to a monthly statement PDF. */
    public function statement(Request $request, StatementService $statements): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'currency' => ['nullable', Rule::in(Currency::codes())]]);

        return ApiResponse::ok($statements->link($request->user(), $data['month'], strtoupper($data['currency'] ?? $request->user()->currency)));
    }

    /** @return array{0: Money, 1: PayoutMethod} */
    private function withdrawalInput(Request $request): array
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'payout_method_id' => ['required', 'string', 'max:40'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
        ]);
        $method = PayoutMethod::query()->where('user_id', $request->user()->id)->whereKey($data['payout_method_id'])->first()
            ?? throw ApiException::validation(['payout_method_id' => 'Choose one of your payout methods.']);

        return [Money::of((int) $data['amount'], $data['currency']), $method];
    }

    private function presentMethod(PayoutMethod $m): array
    {
        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'label' => $m->label,
            'currency' => $m->currency,
            'last4' => $m->last4,
            'account_name' => $m->account_name ? mb_substr($m->account_name, 0, 1).str_repeat('•', 4).' '.mb_substr((string) strrchr(' '.$m->account_name, ' '), 1) : null,
            'verified' => $m->verified_at !== null,
            'is_default' => (bool) $m->is_default,
        ];
    }

    private function presentSchedule(PayoutSchedule $s): array
    {
        return [
            'frequency' => $s->frequency ?? 'manual',
            'day' => $s->day,
            'minimum_amount' => Money::of((int) ($s->minimum_amount ?? 0), $s->currency ?? 'USD')->toArray(),
            'currency' => $s->currency ?? 'USD',
            'payout_method_id' => $s->payout_method_id,
        ];
    }
}
