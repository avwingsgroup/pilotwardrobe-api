<?php

declare(strict_types=1);

namespace App\Jobs\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-verifies open (and recently expired) payments with their PSP. Catches missed or delayed
 * webhooks so no paid order stays `pending_payment`. Runs every 10 minutes and on demand (AD7).
 */
final class ReconcilePayments implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $hours = 24)
    {
        $this->onQueue('payments');
    }

    public function handle(PaymentService $payments, AuditLogger $audit): void
    {
        $checked = 0;
        $changed = 0;

        Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value, PaymentStatus::Expired->value])
            ->where('created_at', '>=', now()->subHours($this->hours))
            ->where('gateway', '!=', 'sandbox')
            ->whereNotNull('authorization_url')
            ->orderBy('created_at')
            ->chunkById(100, function ($chunk) use ($payments, &$checked, &$changed) {
                foreach ($chunk as $payment) {
                    $before = $payment->status;
                    $after = $payments->verify($payment)->status;
                    $checked++;
                    if ($after !== $before) {
                        $changed++;
                    }
                }
            });

        // Open attempts older than their expiry that the PSP still reports as pending are closed out.
        Payment::query()->whereIn('status', [PaymentStatus::Pending->value])
            ->whereNotNull('expires_at')->where('expires_at', '<', now()->subHours(6))
            ->update(['status' => PaymentStatus::Expired->value]);

        if ($checked > 0) {
            $audit->log('PAYMENTS.RECONCILED', 'payments', "{$checked} checked, {$changed} updated");
        }
    }
}
