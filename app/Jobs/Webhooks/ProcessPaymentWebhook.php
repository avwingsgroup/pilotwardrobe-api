<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\Orders\RefundService;
use App\Services\Payments\PaymentService;
use App\Services\Payouts\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Processes one stored, authenticated webhook. The payload only tells us *which* object
 * changed; the authoritative state is always fetched from the PSP (`verify`) or derived
 * from our own records, so a replayed or reordered webhook cannot corrupt state.
 */
final class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(public readonly string $webhookEventId)
    {
        $this->onQueue('payments');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 1800, 3600];
    }

    public function handle(PaymentService $payments, RefundService $refunds, PayoutService $payouts): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->forceFill(['status' => 'processing', 'attempts' => $event->attempts + 1])->save();

        $payload = $event->payload;
        $outcome = match ($this->kind($event)) {
            'payment' => $this->payment($event, $payments),
            'refund' => $this->refund($event, $payload, $refunds),
            'transfer' => $this->transfer($payload, $payouts),
            default => 'ignored',
        };

        $event->forceFill(['status' => $outcome, 'processed_at' => now(), 'last_error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => 'failed',
            'last_error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }

    private function payment(WebhookEvent $event, PaymentService $payments): string
    {
        $payment = Payment::query()->where('gateway', $event->provider)
            ->where(function ($q) use ($event) {
                $q->where('reference', (string) $event->reference);
                $gatewayRef = $this->gatewayReference($event);
                if ($gatewayRef) {
                    $q->orWhere('gateway_reference', $gatewayRef);
                }
            })->first();

        if ($payment === null) {
            return 'ignored';
        }

        $payments->verify($payment);

        return 'processed';
    }

    private function refund(WebhookEvent $event, array $payload, RefundService $refunds): string
    {
        $id = $payload['data']['id'] ?? $payload['data']['object']['id'] ?? $payload['resource']['id'] ?? $payload['eventData']['refundReference'] ?? null;
        $refund = $id ? Refund::query()->where('gateway_refund_id', (string) $id)->orWhere('reference', (string) $id)->first() : null;
        if ($refund === null) {
            return 'ignored';
        }

        $type = strtolower((string) $event->event_type);
        $status = match (true) {
            str_contains($type, 'fail') => 'failed',
            str_contains($type, 'processed'), str_contains($type, 'success'), str_contains($type, 'refunded'), str_contains($type, 'completed') => 'succeeded',
            default => 'processing',
        };
        $refunds->applyGatewayOutcome($refund, $status, (string) $id);

        return 'processed';
    }

    /** Like payments, the webhook only says which payout changed; the provider confirms the outcome. */
    private function transfer(array $payload, PayoutService $payouts): string
    {
        $reference = $payload['data']['reference'] ?? $payload['eventData']['reference'] ?? null;
        $payout = $reference ? Payout::query()->whereRaw('lower(reference) = ?', [strtolower((string) $reference)])->first() : null;
        if ($payout === null) {
            return 'ignored';
        }

        $payouts->reconcile($payout);

        return 'processed';
    }

    private function kind(WebhookEvent $event): string
    {
        $type = strtolower((string) $event->event_type);

        return match (true) {
            str_starts_with($type, 'transfer.'), str_contains($type, 'disbursement'), str_contains($type, 'payouts') => 'transfer',
            str_contains($type, 'refund') => 'refund',
            default => 'payment',
        };
    }

    private function gatewayReference(WebhookEvent $event): ?string
    {
        $p = $event->payload;

        return match ($event->provider) {
            'stripe' => $p['data']['object']['id'] ?? null,
            'paypal' => $p['resource']['supplementary_data']['related_ids']['order_id'] ?? (($p['event_type'] ?? '') === 'CHECKOUT.ORDER.APPROVED' ? ($p['resource']['id'] ?? null) : null),
            'monnify' => $p['eventData']['transactionReference'] ?? null,
            default => null,
        };
    }
}
