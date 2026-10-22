<?php

declare(strict_types=1);

namespace App\Jobs\Payments;

use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\ProviderException;
use App\Models\Refund;
use App\Services\Orders\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends a refund to the PSP. The refund reference doubles as the PSP idempotency key
 * (Stripe/PayPal), so a retried job never refunds twice.
 */
final class ProcessRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(public readonly string $refundId)
    {
        $this->onQueue('payments');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(GatewayRegistry $gateways, RefundService $refunds): void
    {
        $refund = Refund::query()->with('payment')->find($this->refundId);
        if ($refund === null || in_array($refund->status, ['succeeded', 'processing', 'failed'], true)) {
            return;
        }

        try {
            $result = $gateways->get($refund->payment->gateway)
                ->refund($refund->payment, $refund->amount, $refund->reference, $refund->reason ?? 'refund');
        } catch (ProviderException $e) {
            if (! $e->retryable) {
                $refunds->applyGatewayOutcome($refund, 'failed', null, mb_substr($e->getMessage(), 0, 255));

                return;
            }

            throw $e;
        }

        $refunds->applyGatewayOutcome($refund, $result->status, $result->gatewayRefundId, $result->failureReason);
    }

    public function failed(Throwable $e): void
    {
        $refund = Refund::query()->find($this->refundId);
        if ($refund) {
            app(RefundService::class)->applyGatewayOutcome($refund, 'failed', null, 'Retries exhausted: '.mb_substr($e->getMessage(), 0, 200));
        }
    }
}
