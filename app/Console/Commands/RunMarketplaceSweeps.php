<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DisputeStatus;
use App\Enums\KycStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\SwapStatus;
use App\Models\Dispute;
use App\Models\KycVerification;
use App\Models\Listing;
use App\Models\ListingPromotion;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payout;
use App\Models\SwapRequest;
use App\Services\Catalogue\ListingService;
use App\Services\Disputes\DisputeService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Payouts\PayoutService;
use App\Services\Trading\OfferService;
use App\Services\Trading\SwapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Time-based state transitions. Each sweep processes rows one at a time through the same
 * services the API uses (so locks, transitions, audit and notifications apply) and is
 * safe to re-run: every step re-checks its preconditions under a row lock.
 *
 *   php artisan marketplace:sweep {sweep?}
 */
final class RunMarketplaceSweeps extends Command
{
    protected $signature = 'marketplace:sweep {sweep? : one of '.self::SWEEPS.' (default: all)}';

    protected $description = 'Expire unpaid orders, offers and swaps; auto-release escrow; settle funds; escalate disputes.';

    private const SWEEPS = 'orders,offers,swaps,reservations,release,settle,reminders,disputes,promotions,kyc,payouts';

    public function handle(OrderService $orders, OfferService $offers, SwapService $swaps, ListingService $listings, DisputeService $disputes, NotificationService $notifications, PayoutService $payouts): int
    {
        $only = $this->argument('sweep');
        $run = fn (string $name) => $only === null || $only === $name;

        if ($run('orders')) {
            $this->each(Order::query()->where('status', OrderStatus::PendingPayment->value)->where('reserved_until', '<', now()), fn (Order $o) => $orders->expireUnpaid($o), 'expired unpaid orders');
        }
        if ($run('offers')) {
            $this->each(Offer::query()->whereIn('status', [OfferStatus::Pending->value, OfferStatus::Accepted->value])->where('expires_at', '<', now()), fn (Offer $o) => $offers->expire($o), 'expired offers');
        }
        if ($run('swaps')) {
            $this->each(SwapRequest::query()->where('status', SwapStatus::Pending->value)->where('expires_at', '<', now()), fn (SwapRequest $s) => $swaps->expire($s), 'expired swaps');
        }
        if ($run('reservations')) {
            // Seller-made reservations that lapsed without an order.
            $this->each(Listing::query()->where('status', 'reserved')->where('reserved_until', '<', now())->where('reserved_quantity', 0)->whereIn('reserved_reason', ['seller']),
                fn (Listing $l) => $listings->releaseReservation($l), 'released reservations');
        }
        if ($run('release')) {
            $this->each(Order::query()->where('status', OrderStatus::Delivered->value)->where('escrow_status', 'held')->where('auto_release_at', '<', now()),
                fn (Order $o) => $orders->autoRelease($o), 'auto-released orders');
        }
        if ($run('settle')) {
            $this->each(Order::query()->whereNull('funds_settled_at')->whereNotNull('funds_available_at')->where('funds_available_at', '<=', now()),
                fn (Order $o) => $orders->settleFunds($o), 'settled seller funds');
        }
        if ($run('reminders')) {
            $hours = (int) config('platform.orders.delivered_reminder_hours');
            $this->each(Order::query()->where('status', OrderStatus::Delivered->value)->whereNull('delivered_reminder_sent_at')->where('delivered_at', '<', now()->subHours($hours)),
                function (Order $o) use ($orders, $notifications) {
                    $notifications->notify($o->buyer, 'order.delivered_confirm', $orders->notificationData($o), "order.delivered_confirm:{$o->id}");
                    $o->forceFill(['delivered_reminder_sent_at' => now()])->save();
                }, 'delivery confirmation reminders');
        }
        if ($run('disputes')) {
            $this->each(Dispute::query()->where('status', DisputeStatus::AwaitingSeller->value)->where('respond_by', '<', now()), fn (Dispute $d) => $disputes->escalateOverdue($d), 'escalated disputes');
        }
        if ($run('payouts')) {
            // Transfers still processing at the provider (missed or delayed webhooks).
            $this->each(Payout::query()->where('status', PayoutStatus::Processing->value)->whereIn('provider', ['paystack', 'flutterwave'])->where('updated_at', '<', now()->subMinutes(10)),
                fn (Payout $p) => $payouts->reconcile($p), 'reconciled payouts');
        }
        if ($run('promotions')) {
            ListingPromotion::query()->where('status', 'active')->where('ends_at', '<', now())->update(['status' => 'expired']);
            ListingPromotion::query()->where('status', 'pending_payment')->where('created_at', '<', now()->subDay())->update(['status' => 'cancelled']);
        }
        if ($run('kyc')) {
            $expired = KycVerification::query()->where('status', KycStatus::Approved->value)->whereNotNull('document_expires_on')->where('document_expires_on', '<', now()->toDateString())->get();
            foreach ($expired as $kyc) {
                $kyc->forceFill(['status' => KycStatus::Expired, 'step' => 'document'])->save();
                $kyc->user->bumpPermissionsVersion();
            }
            if ($expired->isNotEmpty()) {
                $this->info("expired {$expired->count()} identity verifications");
            }
        }

        return self::SUCCESS;
    }

    /** Processes each row independently; one failure never stops the sweep. */
    private function each($query, callable $step, string $label): void
    {
        $done = 0;
        $query->orderBy('id')->chunkById(200, function ($rows) use ($step, &$done) {
            foreach ($rows as $row) {
                try {
                    $step($row);
                    $done++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        if ($done > 0) {
            $this->info("{$label}: {$done}");
        }
    }
}
