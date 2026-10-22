<?php

declare(strict_types=1);

namespace App\Jobs\Payouts;

use App\Models\Payout;
use App\Services\Payouts\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Sends one payout transfer. Unique per payout so it is never queued twice concurrently. */
final class ProcessPayout implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $payoutId)
    {
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return $this->payoutId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600, 1800, 3600];
    }

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if ($payout !== null) {
            $payouts->process($payout);
        }
    }

    public function failed(Throwable $e): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if ($payout !== null) {
            app(PayoutService::class)->markFailed($payout, 'Transfer could not be completed after several attempts.');
        }
    }
}
