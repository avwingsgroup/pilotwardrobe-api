<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\EscrowStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Jobs\Payments\ProcessRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Wallet\WalletService;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Refunds are initiated only by Laravel (staff or system), never by the SPA calling a PSP.
 *
 * The ledger side (order totals, seller wallet reversal, escrow status) is applied
 * atomically first; the PSP call happens in a retried job. A refund that the PSP
 * permanently rejects is flagged `failed` for finance to settle manually.
 */
final class RefundService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $finalise  when true the order ends `refunded` (full) / `partially_refunded`; disputes pass false and set state themselves
     */
    public function refundOrder(Order $order, int $amount, string $reason, ?string $note, ?User $actor, bool $approved = true, bool $finalise = true, ?User $approver = null): Refund
    {
        $refund = DB::transaction(function () use ($order, $amount, $reason, $note, $actor, $finalise, $approver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $refundable = $order->total_amount - $order->refunded_amount;
            if ($amount <= 0 || $amount > $refundable) {
                throw ApiException::validation(['amount' => 'The refund must be between 1 and '.$order->money($refundable)->format().'.']);
            }

            $payment = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)
                ->whereIn('status', [PaymentStatus::Successful->value, PaymentStatus::PartiallyRefunded->value])
                ->lockForUpdate()->latest()->first();
            if ($payment === null) {
                throw ApiException::conflict('This order has no captured payment to refund.');
            }

            $refund = Refund::query()->create([
                'reference' => ReferenceGenerator::unique(Refund::class, 'reference', fn () => 'RF-'.ReferenceGenerator::code(10)),
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'currency' => $order->currency,
                'status' => 'pending',
                'reason' => mb_substr($reason, 0, 60),
                'note' => $note,
                'requested_by' => $actor?->id,
                'approved_by' => $approver->id ?? $actor->id,
            ]);

            $full = $amount === $refundable;
            $sellerShare = $full ? $order->seller_net_amount : min($amount, $order->seller_net_amount);

            $order->refunded_amount += $amount;
            $order->seller_net_amount -= $sellerShare;
            if ($full) {
                $order->escrow_status = EscrowStatus::Refunded;
            }
            if ($finalise && $order->status !== OrderStatus::Cancelled) {
                $target = $full ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded;
                if ($order->status->canTransitionTo($target)) {
                    $order->status = $target;
                }
            }
            $order->save();

            $this->wallets->reverseSale($order, $sellerShare, "refund:{$refund->id}", 'Refund · Order #'.$order->number);

            app(OrderService::class)->event($order, 'refunded', 'Refund issued', $order->money($amount)->format(), $actor);
            $this->audit->log('REFUND.ISSUED', $order, $order->money($amount)->format()." · {$reason}", 'warning', actor: $actor, metadata: ['refund_id' => $refund->id]);

            ProcessRefund::dispatch($refund->id)->afterCommit();

            return $refund;
        });

        $order->refresh();
        $this->notifications->notify($order->buyer, 'order.refunded', [
            'order_id' => $order->id,
            'number' => $order->number,
            'amount' => $order->money($amount)->format(),
        ], "order.refunded:{$refund->id}");

        return $refund;
    }

    /** Applies the PSP outcome of a refund (job result or `refund.*` webhook). Idempotent. */
    public function applyGatewayOutcome(Refund $refund, string $status, ?string $gatewayRefundId, ?string $failure = null): void
    {
        DB::transaction(function () use ($refund, $status, $gatewayRefundId, $failure) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if (in_array($refund->status, ['succeeded', 'failed'], true)) {
                return;
            }

            $refund->forceFill([
                'status' => $status,
                'gateway_refund_id' => $gatewayRefundId ?? $refund->gateway_refund_id,
                'failure_reason' => $failure,
                'processed_at' => in_array($status, ['succeeded', 'failed'], true) ? now() : null,
            ])->save();

            if ($status === 'succeeded' || $status === 'processing') {
                $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
                $already = (int) Refund::query()->where('payment_id', $payment->id)->whereIn('status', ['succeeded', 'processing'])->sum('amount');
                $payment->refunded_amount = min($payment->amount, $already);
                $payment->status = $payment->refunded_amount >= $payment->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
                $payment->save();
            }

            if ($status === 'failed') {
                $this->audit->log('REFUND.FAILED', $refund->order ?? $refund, (string) $failure, 'danger', metadata: ['refund_id' => $refund->id]);
            }
        });
    }

    /** Refunds a payment that arrived for an order that can no longer accept it (duplicate or late). */
    public function refundOrphanPayment(Payment $payment, string $why): void
    {
        DB::transaction(function () use ($payment, $why) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->refunds()->exists()) {
                return;
            }
            $refund = Refund::query()->create([
                'reference' => ReferenceGenerator::unique(Refund::class, 'reference', fn () => 'RF-'.ReferenceGenerator::code(10)),
                'payment_id' => $payment->id,
                'order_id' => $payment->payable_type === 'order' ? $payment->payable_id : null,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => 'pending',
                'reason' => 'duplicate_payment',
                'note' => $why,
            ]);
            $this->audit->log('PAYMENT.ORPHAN_REFUND', $payment, $why, 'warning', metadata: ['refund_id' => $refund->id]);
            ProcessRefund::dispatch($refund->id)->afterCommit();
        });
    }
}
