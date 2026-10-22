<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\InitializeRequest;
use App\Integrations\Payments\VerificationResult;
use App\Integrations\ProviderException;
use App\Models\ListingPromotion;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Orders\RefundService;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gateway-agnostic payments (handoff §7). The SPA never decides that a payment succeeded:
 * a payment becomes `successful` only after `verify()` asked the PSP server-to-server and
 * the amount, currency and reference all match the payment row.
 */
final class PaymentService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OrderService $orders,
        private readonly RefundService $refunds,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** `POST /payments/initialize` */
    public function initialize(User $user, Order|ListingPromotion $payable, string $gatewayCode, string $returnUrl): Payment
    {
        $this->assertSafeReturnUrl($returnUrl);
        [$amount, $currency, $description] = $this->payableTerms($user, $payable);
        $gateway = $this->gateways->assertUsable($gatewayCode, $currency);

        // Idempotent per payable + gateway while the attempt is still open.
        $payment = DB::transaction(function () use ($user, $payable, $gatewayCode, $amount, $currency, $returnUrl) {
            $existing = Payment::query()->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())
                ->where('gateway', $gatewayCode)->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                ->lockForUpdate()->first();
            if ($existing !== null && $existing->amount === $amount && $existing->authorization_url) {
                return $existing;
            }
            if ($existing !== null) {
                // Totals changed (or init never completed): retire the stale attempt.
                $existing->forceFill(['status' => PaymentStatus::Cancelled, 'failure_reason' => 'Superseded'])->save();
            }

            return Payment::query()->create([
                'reference' => ReferenceGenerator::unique(Payment::class, 'reference', fn () => ReferenceGenerator::paymentReference()),
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'user_id' => $user->id,
                'gateway' => $gatewayCode,
                'amount' => $amount,
                'currency' => $currency,
                'status' => PaymentStatus::Pending,
                'return_url' => $returnUrl,
                'expires_at' => now()->addMinutes((int) config('platform.payments.pending_expiry_minutes')),
            ]);
        });

        if ($payment->authorization_url) {
            return $payment;
        }

        try {
            $result = $gateway->initialize($payment, new InitializeRequest(
                customerEmail: $user->email,
                customerName: $user->displayName(),
                returnUrl: $returnUrl,
                cancelUrl: $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($payment->reference).'&cancelled=1',
                description: $description,
                metadata: ['payable' => $payable->getMorphClass().':'.$payable->getKey()],
            ));
        } catch (ProviderException $e) {
            Log::warning('payments.initialize_failed', ['gateway' => $gatewayCode, 'reference' => $payment->reference, 'retryable' => $e->retryable]);
            $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => 'Could not start the payment.'])->save();

            throw ApiException::gatewayUnavailable();
        }

        $this->assertGatewayHost($gatewayCode, $result->authorizationUrl);

        $payment->forceFill([
            'authorization_url' => $result->authorizationUrl,
            'gateway_reference' => $result->gatewayReference,
            'gateway_metadata' => array_merge($payment->gateway_metadata ?? [], $result->metadata),
        ])->save();

        $this->audit->log('PAYMENT.INITIALIZED', $payment, "{$gatewayCode} ".$payment->money()->format(), actor: $user);

        return $payment;
    }

    /**
     * Verifies with the PSP and applies the result. Safe to call repeatedly and concurrently
     * (client polls + webhook): a per-reference lock serialises it and settled payments short-circuit.
     */
    public function verify(Payment $payment): Payment
    {
        if (! $payment->status->isOpen() && $payment->status !== PaymentStatus::Expired) {
            return $payment;
        }

        $lock = Cache::lock('payment-verify:'.$payment->reference, 30);
        if (! $lock->get()) {
            return $payment->fresh();
        }

        try {
            $payment->refresh();
            if (! $payment->status->isOpen() && $payment->status !== PaymentStatus::Expired) {
                return $payment;
            }

            $result = $this->gateways->get($payment->gateway)->verify($payment);
            $this->apply($payment, $result);

            return $payment->fresh();
        } catch (ProviderException $e) {
            Log::warning('payments.verify_failed', ['reference' => $payment->reference, 'retryable' => $e->retryable]);

            return $payment->fresh();
        } finally {
            $lock->release();
        }
    }

    /** Applies a PSP verification result to the payment and its payable. */
    public function apply(Payment $payment, VerificationResult $result): void
    {
        $orphan = false;

        DB::transaction(function () use ($payment, $result, &$orphan) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! $payment->status->isOpen() && $payment->status !== PaymentStatus::Expired) {
                return;
            }

            $metadata = array_merge($payment->gateway_metadata ?? [], array_filter($result->metadata, fn ($v) => $v !== null));

            if ($result->status === PaymentStatus::Successful) {
                $amountOk = $result->amount === $payment->amount;
                $currencyOk = $result->currency === null || strtoupper($result->currency) === $payment->currency;
                $referenceOk = $result->gatewayReference === null || $payment->gateway_reference === null
                    || in_array($payment->gateway, ['paystack', 'flutterwave'], true) // their verify ids differ from init references
                    || $result->gatewayReference === $payment->gateway_reference;

                if (! $amountOk || ! $currencyOk || ! $referenceOk) {
                    $payment->forceFill([
                        'status' => PaymentStatus::Failed,
                        'failure_reason' => 'We could not confirm this payment. Our team has been notified.',
                        'gateway_metadata' => $metadata + ['mismatch' => [
                            'expected' => [$payment->amount, $payment->currency],
                            'received' => [$result->amount, $result->currency],
                        ]],
                    ])->save();
                    $this->audit->log('PAYMENT.MISMATCH', $payment, sprintf('Expected %d %s, PSP reported %s %s',
                        $payment->amount, $payment->currency, $result->amount ?? '?', $result->currency ?? '?'), 'danger');

                    return;
                }

                $payment->forceFill([
                    'status' => PaymentStatus::Successful,
                    'verified_at' => now(),
                    'method_label' => $result->methodLabel ?? $payment->method_label,
                    'gateway_fee_amount' => $result->feeAmount,
                    'gateway_metadata' => $metadata,
                    'failure_reason' => null,
                ])->save();

                $accepted = $this->settlePayable($payment);
                if (! $accepted) {
                    $orphan = true;
                }

                return;
            }

            if ($result->status === $payment->status) {
                return;
            }

            if ($payment->status->canTransitionTo($result->status)) {
                $payment->forceFill([
                    'status' => $result->status,
                    'failure_reason' => $result->failureReason,
                    'method_label' => $result->methodLabel ?? $payment->method_label,
                    'gateway_metadata' => $metadata,
                ])->save();

                if ($result->status === PaymentStatus::Failed && $payment->payable_type === 'order') {
                    $order = Order::query()->find($payment->payable_id);
                    if ($order) {
                        DB::afterCommit(fn () => $this->notifications->notify($order->buyer, 'order.payment_failed', [
                            'order_id' => $order->id, 'number' => $order->number, 'reason' => (string) $result->failureReason,
                        ], "order.payment_failed:{$payment->id}"));
                    }
                }
            }
        });

        if ($orphan) {
            $this->refunds->refundOrphanPayment($payment->fresh(), 'Payment arrived after the order was paid by another attempt or cancelled.');
        }
    }

    /** `GET /payments/{reference}` shape. */
    public function present(Payment $payment): array
    {
        $out = [
            'id' => $payment->id,
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'gateway' => $payment->gateway,
            'amount' => $payment->money()->toArray(),
            'method_label' => $payment->method_label,
            'failure_reason' => $payment->status === PaymentStatus::Failed ? ($payment->failure_reason ?? 'The payment was not completed.') : null,
            'verified_at' => $payment->verified_at?->toIso8601ZuluString(),
        ];
        if ($payment->payable_type === 'order') {
            $out['order_id'] = $payment->payable_id;
        } else {
            $out['promotion_id'] = $payment->payable_id;
        }

        return $out;
    }

    /** @return bool false when the payable can no longer accept the money (caller refunds) */
    private function settlePayable(Payment $payment): bool
    {
        if ($payment->payable_type === 'order') {
            $order = Order::query()->findOrFail($payment->payable_id);

            return $this->orders->markPaid($order, $payment);
        }

        $promotion = ListingPromotion::query()->lockForUpdate()->findOrFail($payment->payable_id);
        if ($promotion->status !== 'pending_payment') {
            return false;
        }
        $days = $promotion->package->duration_days;
        $listing = $promotion->listing;
        $start = $listing->promoted_until && $listing->promoted_until->isFuture() ? $listing->promoted_until : now();
        $promotion->forceFill(['status' => 'active', 'starts_at' => $start, 'ends_at' => $start->copy()->addDays($days)])->save();
        $listing->forceFill(['promoted_until' => $promotion->ends_at])->save();
        $this->audit->log('LISTING.PROMOTED', $listing, $promotion->package->name, 'success', actor: $promotion->user);

        return true;
    }

    /** @return array{0: int, 1: string, 2: string} amount, currency, description */
    private function payableTerms(User $user, Order|ListingPromotion $payable): array
    {
        if ($payable instanceof Order) {
            if (! $payable->isBuyer($user)) {
                throw ApiException::notFound('Order not found.');
            }
            if ($payable->status !== OrderStatus::PendingPayment) {
                throw ApiException::invalidTransition('This order is not awaiting payment.');
            }
            if ($payable->reserved_until !== null && $payable->reserved_until->isPast()) {
                throw ApiException::invalidTransition('This order expired. Please check out again.');
            }

            return [$payable->total_amount, $payable->currency, 'Pilotwardrobe order #'.$payable->number];
        }

        if ($payable->user_id !== $user->id) {
            throw ApiException::notFound('Promotion not found.');
        }
        if ($payable->status !== 'pending_payment') {
            throw ApiException::invalidTransition('This promotion is not awaiting payment.');
        }

        return [$payable->amount, $payable->currency, 'Listing boost · '.$payable->package->name];
    }

    /** `return_url` must point at our own frontend — prevents open redirects through PSPs. */
    private function assertSafeReturnUrl(string $url): void
    {
        $frontend = parse_url(config('platform.frontend_url'));
        $target = parse_url($url);
        $ok = isset($target['scheme'], $target['host'])
            && in_array($target['scheme'], ['https', 'http'], true)
            && strtolower($target['host']) === strtolower($frontend['host'] ?? '')
            && ($target['port'] ?? null) === ($frontend['port'] ?? null)
            && ($target['scheme'] === 'https' || app()->environment('local', 'testing'));

        if (! $ok) {
            throw ApiException::validation(['return_url' => 'The return URL must be on the Pilotwardrobe site.']);
        }
    }

    /** The SPA only redirects to allow-listed PSP hosts; refuse to hand it anything else. */
    private function assertGatewayHost(string $gateway, string $url): void
    {
        if ($gateway === 'sandbox') {
            return;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = collect(config('platform.payments.redirect_hosts'))
            ->contains(fn (string $h) => $host === $h || str_ends_with($host, '.'.$h));

        if (! $allowed || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            Log::error('payments.unexpected_redirect_host', ['gateway' => $gateway, 'host' => $host]);

            throw ApiException::gatewayUnavailable();
        }
    }

    public function findPayable(?string $orderId, ?string $promotionId): Order|ListingPromotion
    {
        if ($orderId) {
            return Order::query()->find($orderId) ?? throw ApiException::notFound('Order not found.');
        }
        if ($promotionId) {
            return ListingPromotion::query()->find($promotionId) ?? throw ApiException::notFound('Promotion not found.');
        }

        throw ApiException::validation(['order_id' => 'Choose what you are paying for.']);
    }
}
