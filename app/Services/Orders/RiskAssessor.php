<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\ExchangeRates;
use App\Support\Money\Money;

/**
 * Lightweight, explainable order risk signals for the Control Tower (AD2/AD7). Signals
 * inform staff; they never block a purchase on their own (staff can hold the order).
 */
final class RiskAssessor
{
    public function __construct(private readonly ExchangeRates $fx) {}

    /** @return array{level: 'low'|'medium'|'high', signals: list<string>} */
    public function assess(User $buyer, Money $total): array
    {
        $signals = [];
        $usd = rescue(fn () => $this->fx->convert($total, 'USD')->amount, $total->amount, false);

        $accountAgeHours = $buyer->created_at?->diffInHours(now()) ?? 0;
        if ($accountAgeHours < 24) {
            $signals[] = 'Account less than 1 day old';
        }
        if ($usd >= 100000) {
            $signals[] = 'High-value order';
        }
        if (! $buyer->isKycApproved()) {
            $signals[] = 'Buyer KYC not completed';
        }
        $recent = Order::query()->where('buyer_id', $buyer->id)->where('created_at', '>=', now()->subDay())->count();
        if ($recent >= 3) {
            $signals[] = "{$recent} orders in the last 24h";
        }

        $score = count($signals) + ($accountAgeHours < 24 && $usd >= 100000 ? 2 : 0);

        return ['level' => $score >= 3 ? 'high' : ($score === 2 ? 'medium' : 'low'), 'signals' => $signals];
    }
}
