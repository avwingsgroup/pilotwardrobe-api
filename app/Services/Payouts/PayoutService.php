<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Enums\PayoutStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payouts\TransferProviderRegistry;
use App\Integrations\ProviderException;
use App\Jobs\Payouts\ProcessPayout;
use App\Models\Payout;
use App\Models\PayoutMethod;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\PlatformSettings;
use App\Services\Pricing\ExchangeRates;
use App\Services\Wallet\WalletService;
use App\Support\Ids\ReferenceGenerator;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Seller withdrawals (VB13) and the staff payout queue (AD11). Funds are reserved from
 * `available` when requested; above the dual-control threshold a second staff member
 * must approve before the transfer is sent. Transfers run in a retried job.
 */
final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly ExchangeRates $fx,
        private readonly PlatformSettings $settings,
        private readonly TransferProviderRegistry $providers,
        private readonly TwoFactorService $twoFactor,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** `POST /me/withdrawals/quote` */
    public function quote(User $user, Money $amount, PayoutMethod $method): array
    {
        $fee = $amount->percentage((int) $this->settings->get('withdrawals.fee_bps', 0));
        $net = $amount->minus($fee);
        $rate = $this->fx->rate($amount->currency, $method->currency);
        $destination = $this->fx->convert($net, $method->currency);
        [$minDays, $maxDays] = $this->settings->get('withdrawals.eta_business_days', [1, 2]);

        return [
            'amount' => $amount->toArray(),
            'exchange_rate' => $amount->currency->value === $method->currency ? null : [
                'from' => $amount->currency->value,
                'to' => $method->currency,
                'rate' => round($rate, 4),
                'label' => sprintf('1 %s = %s', $amount->currency->value, Money::of((int) round($rate * 100), $method->currency)->format()),
            ],
            'fee' => $fee->toArray(),
            'recipient_receives' => $destination->toArray(),
            'arrives_between' => [
                'from' => now()->addWeekdays((int) $minDays)->toIso8601ZuluString(),
                'to' => now()->addWeekdays((int) $maxDays)->toIso8601ZuluString(),
            ],
            'requires_two_factor' => $user->hasTwoFactorEnabled(),
            'requires_approval' => $this->needsApproval($amount),
        ];
    }

    /** `POST /me/withdrawals` */
    public function request(User $user, Money $amount, PayoutMethod $method, ?string $twoFactorCode): Payout
    {
        if (! $user->isKycApproved()) {
            throw ApiException::kycRequired('Verify your identity before withdrawing.');
        }
        if (! $user->limits()['can_receive_payouts'] || $user->status->value !== 'active') {
            throw ApiException::forbidden('Withdrawals are restricted on your account. Contact support.');
        }
        if ($user->hasTwoFactorEnabled()) {
            $method2fa = preg_match('/^\d{6}$/', (string) $twoFactorCode) ? 'totp' : 'backup_code';
            if (! $twoFactorCode || ! DB::transaction(fn () => $this->twoFactor->verify($user, $method2fa, (string) $twoFactorCode))) {
                throw ApiException::validation(['two_factor_code' => 'Enter the code from your authenticator app.']);
            }
        }

        $minimum = $this->settings->get('withdrawals.minimum');
        if ($minimum && $this->fx->convert($amount, $minimum['currency'])->amount < (int) $minimum['amount']) {
            throw ApiException::validation(['amount' => 'The minimum withdrawal is '.Money::of((int) $minimum['amount'], $minimum['currency'])->format().'.']);
        }

        $dailyLimit = (int) ($user->limits()['daily_payout_limit'] ?? 0);
        if ($dailyLimit > 0) {
            $today = (int) Payout::query()->where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
                ->whereNotIn('status', [PayoutStatus::Rejected->value])->get()
                ->sum(fn (Payout $p) => $this->fx->convert($p->money(), 'USD')->amount);
            if ($today + $this->fx->convert($amount, 'USD')->amount > $dailyLimit) {
                throw ApiException::validation(['amount' => 'This exceeds your daily withdrawal limit.']);
            }
        }

        $payout = DB::transaction(function () use ($user, $amount, $method) {
            $wallet = $this->wallets->walletFor($user, $amount->currency->value);
            if ($wallet->available_amount < $amount->amount) {
                throw ApiException::validation(['amount' => 'You can withdraw up to '.Money::of($wallet->available_amount, $wallet->currency)->format().'.']);
            }

            $fee = $amount->percentage((int) $this->settings->get('withdrawals.fee_bps', 0));
            $net = $amount->minus($fee);
            $rate = $this->fx->rate($amount->currency, $method->currency);
            $needsApproval = $this->needsApproval($amount);
            $firstToMethod = ! Payout::query()->where('payout_method_id', $method->id)->where('status', PayoutStatus::Paid->value)->exists();

            $payout = Payout::query()->create([
                'reference' => ReferenceGenerator::unique(Payout::class, 'reference', fn () => 'PO-'.ReferenceGenerator::code(10)),
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'payout_method_id' => $method->id,
                'amount' => $amount->amount,
                'currency' => $amount->currency->value,
                'fee_amount' => $fee->amount,
                'net_amount' => $net->amount,
                'destination_currency' => $method->currency,
                'destination_amount' => $this->fx->convert($net, $method->currency)->amount,
                'exchange_rate' => $rate,
                'status' => $needsApproval ? PayoutStatus::AwaitingApproval : PayoutStatus::Pending,
                'requires_approval' => $needsApproval,
                'approval_reason' => $needsApproval ? 'Over '.$this->thresholdLabel().' threshold' : null,
                'flagged' => $firstToMethod && $method->created_at->gt(now()->subDay()),
                'provider' => $method->provider ?? $this->providers->providerCodeFor($method),
            ]);

            $this->wallets->reserveForPayout($payout);
            $this->audit->log('PAYOUT.REQUESTED', $payout, $amount->format(), $needsApproval ? 'warning' : 'info', actor: $user);

            if (! $needsApproval) {
                ProcessPayout::dispatch($payout->id)->afterCommit();
            }

            return $payout;
        });

        $this->notifications->notify($user, 'payout.requested', $this->notificationData($payout), "payout.requested:{$payout->id}");

        return $payout;
    }

    /** Second-approver step from AD11 (`POST /admin/payouts/approve`). */
    public function approve(Payout $payout, User $staff): Payout
    {
        return DB::transaction(function () use ($payout, $staff) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->user_id === $staff->id) {
                throw ApiException::conflict('You cannot approve your own payout.');
            }
            $payout->status->assertCanTransitionTo(PayoutStatus::Approved, 'This payout cannot be approved in its current state.');

            $payout->forceFill(['status' => PayoutStatus::Approved, 'approved_by' => $staff->id, 'approved_at' => now(), 'hold_reason' => null])->save();
            $this->audit->log('PAYOUT.APPROVED', $payout, $payout->money()->format(), 'success', actor: $staff);
            ProcessPayout::dispatch($payout->id)->afterCommit();

            return $payout;
        });
    }

    public function hold(Payout $payout, User $staff, string $reason): Payout
    {
        $payout = DB::transaction(function () use ($payout, $staff, $reason) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $payout->status->assertCanTransitionTo(PayoutStatus::OnHold, 'This payout cannot be put on hold now.');
            $payout->forceFill(['status' => PayoutStatus::OnHold, 'hold_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->log('PAYOUT.HELD', $payout, $reason, 'warning', actor: $staff);

            return $payout;
        });

        $this->notifications->notify($payout->user, 'payout.on_hold', $this->notificationData($payout) + ['reason' => $reason], "payout.on_hold:{$payout->id}:".now()->timestamp);

        return $payout;
    }

    public function reject(Payout $payout, User $staff, string $reason): Payout
    {
        $payout = DB::transaction(function () use ($payout, $staff, $reason) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $payout->status->assertCanTransitionTo(PayoutStatus::Rejected, 'This payout cannot be rejected now.');
            $payout->forceFill(['status' => PayoutStatus::Rejected, 'rejection_reason' => mb_substr($reason, 0, 255)])->save();
            $this->wallets->returnPayout($payout, 'Rejected: '.$reason);
            $this->audit->log('PAYOUT.REJECTED', $payout, $reason, 'danger', actor: $staff);

            return $payout;
        });

        $this->notifications->notify($payout->user, 'payout.rejected', $this->notificationData($payout) + ['reason' => $reason], "payout.rejected:{$payout->id}");

        return $payout;
    }

    /** Sends the transfer (job). */
    public function process(Payout $payout): void
    {
        $payout = DB::transaction(function () use ($payout) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($payout->status, [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Failed], true)) {
                return null;
            }
            $payout->forceFill(['status' => PayoutStatus::Processing])->save();

            return $payout;
        });
        if ($payout === null) {
            return;
        }

        try {
            $result = $this->providers->get((string) $payout->provider)->transfer($payout);
        } catch (ProviderException $e) {
            if ($e->retryable) {
                Payout::query()->whereKey($payout->id)->update(['status' => PayoutStatus::Failed->value, 'failure_reason' => 'Temporary provider error — will retry.']);

                throw $e;
            }
            $this->markFailed($payout, 'The transfer was rejected by the provider.');

            return;
        }

        Payout::query()->whereKey($payout->id)->update(['provider_reference' => $result['provider_reference']]);
        if ($result['status'] === 'paid') {
            $this->markPaid($payout->fresh());
        } elseif ($result['status'] === 'failed') {
            $this->markFailed($payout->fresh(), $result['failure_reason'] ?? 'Transfer failed.');
        }
    }

    /**
     * Settles a `processing` payout from the provider's authoritative status. Called by
     * transfer webhooks and the payout sweep; manual payouts wait for finance.
     */
    public function reconcile(Payout $payout): void
    {
        if ($payout->status !== PayoutStatus::Processing || $payout->provider === null || $payout->provider === 'manual') {
            return;
        }

        $result = $this->providers->get($payout->provider)->status($payout);
        if ($result['status'] === 'paid') {
            $this->markPaid($payout);
        } elseif ($result['status'] === 'failed') {
            $this->markFailed($payout, $result['failure_reason'] ?? 'Transfer failed.');
        }
    }

    public function markPaid(Payout $payout): void
    {
        $paid = DB::transaction(function () use ($payout) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status === PayoutStatus::Paid) {
                return null;
            }
            $payout->forceFill(['status' => PayoutStatus::Paid, 'paid_at' => now(), 'failure_reason' => null])->save();
            $this->wallets->completePayout($payout);
            $this->audit->log('PAYOUT.PAID', $payout, $payout->money()->format(), 'success');

            return $payout;
        });

        if ($paid) {
            $this->notifications->notify($paid->user, 'payout.sent', $this->notificationData($paid), "payout.sent:{$paid->id}");
        }
    }

    /** Permanent failure: funds return to the wallet. */
    public function markFailed(Payout $payout, string $reason): void
    {
        DB::transaction(function () use ($payout, $reason) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Rejected], true)) {
                return;
            }
            $payout->forceFill(['status' => PayoutStatus::Rejected, 'failure_reason' => mb_substr($reason, 0, 255), 'rejection_reason' => $reason])->save();
            $this->wallets->returnPayout($payout, $reason);
            $this->audit->log('PAYOUT.FAILED', $payout, $reason, 'danger');
        });

        $this->notifications->notify($payout->user, 'payout.rejected', $this->notificationData($payout) + ['reason' => $reason], "payout.failed:{$payout->id}");
    }

    public function needsApproval(Money $amount): bool
    {
        $threshold = $this->settings->get('dual_control.payout_threshold');

        return $threshold && $this->fx->convert($amount, $threshold['currency'])->amount > (int) $threshold['amount'];
    }

    public function walletForCurrency(User $user, string $currency): ?Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->where('currency', $currency)->first();
    }

    private function thresholdLabel(): string
    {
        $t = $this->settings->get('dual_control.payout_threshold');

        return Money::of((int) $t['amount'], $t['currency'])->format();
    }

    private function notificationData(Payout $payout): array
    {
        $method = $payout->payoutMethod;

        return [
            'amount' => $payout->money()->format(),
            'destination' => $method->label.($method->last4 ? ' •••• '.$method->last4 : ''),
            'reason' => (string) ($payout->hold_reason ?? $payout->rejection_reason ?? ''),
        ];
    }
}
