<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Seller balances (handoff §8.6, VB12).
 *
 * Buckets: `escrow` (paid, not delivered) → `pending_release` (delivered, dispute window /
 * new-seller hold running) → `available` (withdrawable) → `reserved` (withdrawal in flight).
 *
 * Every movement locks the wallet row (`SELECT … FOR UPDATE`) and writes ledger entries in
 * the same transaction. Each movement carries an idempotency key; repeating a movement
 * (retried job, replayed webhook) is a no-op. Must be called inside a DB transaction.
 */
final class WalletService
{
    public function walletFor(User|string $user, string $currency, bool $lock = true): Wallet
    {
        $userId = $user instanceof User ? $user->id : $user;
        Wallet::query()->insertOrIgnore([
            'id' => Wallet::newPrefixedId(),
            'user_id' => $userId,
            'currency' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = Wallet::query()->where('user_id', $userId)->where('currency', $currency);

        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }

    /** Payment verified → seller proceeds held in escrow. */
    public function holdInEscrow(Order $order): void
    {
        $this->assertInTransaction();
        if ($order->seller_net_amount <= 0) {
            return;
        }

        $wallet = $this->walletFor($order->seller_id, $order->currency);
        if ($this->applied("sale:{$order->id}:escrow")) {
            return;
        }

        $wallet->escrow_amount += $order->seller_net_amount;
        $wallet->save();

        $this->entry($wallet, 'sale', 'credit', 'escrow', $order->seller_net_amount, 'in_escrow',
            'Sale · '.$this->itemLabel($order), $this->saleDetail($order), $order, null, "sale:{$order->id}:escrow");
    }

    /** Buyer confirmed / auto-release → proceeds wait out the dispute window. */
    public function releaseToPending(Order $order): void
    {
        $this->assertInTransaction();
        if ($order->seller_net_amount <= 0) {
            return;
        }

        $wallet = $this->walletFor($order->seller_id, $order->currency);
        if ($this->applied("sale:{$order->id}:pending")) {
            return;
        }

        $amount = min($order->seller_net_amount, $wallet->escrow_amount);
        $wallet->escrow_amount -= $amount;
        $wallet->pending_release_amount += $amount;
        $wallet->save();

        LedgerEntry::query()->where('idempotency_key', "sale:{$order->id}:escrow")->update(['status' => 'pending']);
        $this->marker($wallet, $order, "sale:{$order->id}:pending");
    }

    /** Dispute window over → withdrawable. */
    public function makeAvailable(Order $order): void
    {
        $this->assertInTransaction();
        $wallet = $this->walletFor($order->seller_id, $order->currency);
        if ($order->seller_net_amount <= 0 || $this->applied("sale:{$order->id}:available")) {
            return;
        }

        $amount = min($order->seller_net_amount, $wallet->pending_release_amount);
        $wallet->pending_release_amount -= $amount;
        $wallet->available_amount += $amount;
        $wallet->lifetime_earnings_amount += $amount;
        $wallet->save();

        LedgerEntry::query()->where('idempotency_key', "sale:{$order->id}:escrow")->update(['status' => 'completed']);
        $this->marker($wallet, $order, "sale:{$order->id}:available");
    }

    /**
     * Reduces the seller's held proceeds after a refund (full or partial). Takes from
     * escrow first, then pending release. `$key` makes each refund apply once.
     */
    public function reverseSale(Order $order, int $amount, string $key, string $description): void
    {
        $this->assertInTransaction();
        if ($amount <= 0) {
            return;
        }

        $wallet = $this->walletFor($order->seller_id, $order->currency);
        if ($this->applied($key)) {
            return;
        }

        $fromEscrow = min($amount, $wallet->escrow_amount);
        $fromPending = min($amount - $fromEscrow, $wallet->pending_release_amount);
        $wallet->escrow_amount -= $fromEscrow;
        $wallet->pending_release_amount -= $fromPending;
        $wallet->save();

        $this->entry($wallet, 'refund', 'debit', $fromEscrow > 0 ? 'escrow' : 'pending', $fromEscrow + $fromPending ?: $amount, 'completed',
            $description, 'Order #'.$order->number, $order, null, $key);
    }

    /** Withdrawal requested → funds reserved out of `available`. */
    public function reserveForPayout(Payout $payout): void
    {
        $this->assertInTransaction();
        $wallet = Wallet::query()->whereKey($payout->wallet_id)->lockForUpdate()->firstOrFail();
        if ($this->applied("payout:{$payout->id}:reserve")) {
            return;
        }
        if ($wallet->available_amount < $payout->amount) {
            throw new LogicException('Insufficient available balance.');
        }

        $wallet->available_amount -= $payout->amount;
        $wallet->reserved_amount += $payout->amount;
        $wallet->save();

        $method = $payout->payoutMethod;
        $this->entry($wallet, 'withdrawal', 'debit', 'available', $payout->amount, 'pending',
            'Payout to '.$method->label.($method->last4 ? ' •••• '.$method->last4 : ''), 'Processing', null, $payout, "payout:{$payout->id}:reserve");
    }

    /** Transfer confirmed. */
    public function completePayout(Payout $payout): void
    {
        $this->assertInTransaction();
        $wallet = Wallet::query()->whereKey($payout->wallet_id)->lockForUpdate()->firstOrFail();
        if ($this->applied("payout:{$payout->id}:paid")) {
            return;
        }

        $wallet->reserved_amount = max(0, $wallet->reserved_amount - $payout->amount);
        $wallet->save();

        LedgerEntry::query()->where('idempotency_key', "payout:{$payout->id}:reserve")->update(['status' => 'completed', 'detail' => 'Paid']);
        $this->markerForPayout($wallet, $payout, "payout:{$payout->id}:paid");
    }

    /** Rejected or permanently failed → funds back to `available`. */
    public function returnPayout(Payout $payout, string $reason): void
    {
        $this->assertInTransaction();
        $wallet = Wallet::query()->whereKey($payout->wallet_id)->lockForUpdate()->firstOrFail();
        if ($this->applied("payout:{$payout->id}:returned") || ! $this->applied("payout:{$payout->id}:reserve")) {
            return;
        }

        $wallet->reserved_amount = max(0, $wallet->reserved_amount - $payout->amount);
        $wallet->available_amount += $payout->amount;
        $wallet->save();

        LedgerEntry::query()->where('idempotency_key', "payout:{$payout->id}:reserve")->update(['status' => 'reversed', 'detail' => mb_substr($reason, 0, 255)]);
        $this->entry($wallet, 'payout_reversal', 'credit', 'available', $payout->amount, 'completed',
            'Payout returned to wallet', mb_substr($reason, 0, 255), null, $payout, "payout:{$payout->id}:returned");
    }

    /** `GET /me/wallet` shape for one currency. */
    public function summary(User $user, string $currency): array
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->where('currency', $currency)->first();
        $money = fn (int $amount) => Money::of($amount, $currency)->toArray();

        $escrowOrders = Order::query()->where('seller_id', $user->id)->where('currency', $currency)
            ->whereIn('escrow_status', ['held', 'frozen', 'on_hold'])->count();
        $nextRelease = Order::query()->where('seller_id', $user->id)->where('currency', $currency)
            ->whereNull('funds_settled_at')->whereNotNull('funds_available_at')->min('funds_available_at');
        $thisMonth = (int) LedgerEntry::query()->where('user_id', $user->id)->where('currency', $currency)
            ->where('type', 'sale')->where('direction', 'credit')->where('occurred_at', '>=', now()->startOfMonth())->sum('amount');

        $available = (int) ($wallet->available_amount ?? 0);
        $escrow = (int) ($wallet->escrow_amount ?? 0);
        $pending = (int) ($wallet->pending_release_amount ?? 0);

        return [
            'currency' => $currency,
            'available' => $money($available),
            'in_escrow' => $money($escrow),
            'pending_release' => $money($pending),
            'reserved' => $money((int) ($wallet->reserved_amount ?? 0)),
            'lifetime_earnings' => $money((int) ($wallet->lifetime_earnings_amount ?? 0)),
            'total' => $money($available + $escrow + $pending),
            'escrow_orders' => $escrowOrders,
            'pending_release_in' => $nextRelease ? max(0, (int) ceil(now()->diffInHours(Carbon::parse($nextRelease), false))).'h' : null,
            'this_month_earnings' => $money($thisMonth),
        ];
    }

    private function entry(Wallet $wallet, string $type, string $direction, string $bucket, int $amount, string $status, string $description, ?string $detail, ?Order $order, ?Payout $payout, string $key): void
    {
        LedgerEntry::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => $type,
            'direction' => $direction,
            'bucket' => $bucket,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'status' => $status,
            'description' => mb_substr($description, 0, 200),
            'detail' => $detail ? mb_substr($detail, 0, 255) : null,
            'order_id' => $order?->id,
            'payout_id' => $payout?->id,
            'idempotency_key' => $key,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Zero-value movement markers are not ledger rows; idempotency for bucket moves uses the cache-free marker table below. */
    private function marker(Wallet $wallet, Order $order, string $key): void
    {
        DB::table('wallet_movements')->insert(['key' => $key, 'wallet_id' => $wallet->id, 'order_id' => $order->id, 'created_at' => now()]);
    }

    private function markerForPayout(Wallet $wallet, Payout $payout, string $key): void
    {
        DB::table('wallet_movements')->insert(['key' => $key, 'wallet_id' => $wallet->id, 'payout_id' => $payout->id, 'created_at' => now()]);
    }

    private function applied(string $key): bool
    {
        return LedgerEntry::query()->where('idempotency_key', $key)->exists()
            || DB::table('wallet_movements')->where('key', $key)->exists();
    }

    private function itemLabel(Order $order): string
    {
        $first = $order->items()->first();

        return $first ? mb_substr($first->title, 0, 60) : 'Order #'.$order->number;
    }

    private function saleDetail(Order $order): string
    {
        return 'Order #'.$order->number.($order->platform_fee_amount > 0 ? ' · fee '.Money::of($order->platform_fee_amount, $order->currency)->format() : '');
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Wallet movements must run inside a database transaction.');
        }
    }
}
