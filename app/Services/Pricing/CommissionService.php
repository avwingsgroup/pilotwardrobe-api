<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CommissionRule;
use App\Models\Listing;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Prices platform charges from AD12 commission rules (never from client input):
 *
 *  - sale_commission  — taken from the seller's proceeds on release
 *  - buyer_protection — added to the buyer's total at checkout
 *  - swap_fee         — charged to each side of a swap
 *
 * Rule selection: live (or scheduled-and-in-window) rules of the kind whose scope matches,
 * most specific scope first (categories → seller type → country → all), then priority.
 * `min_amount`/`cap_amount` are defined in the rule currency and converted when needed.
 */
final class CommissionService
{
    private const SCOPE_RANK = ['categories' => 0, 'seller_type' => 1, 'country' => 2, 'all' => 3];

    public function __construct(private readonly ExchangeRates $fx) {}

    /** @return array{rule: ?CommissionRule, rate_bps: int, fee: Money} */
    public function saleCommission(Money $price, ?Listing $listing = null, ?User $seller = null): array
    {
        return $this->charge('sale_commission', $price, $listing, $seller ?? $listing?->seller, 800);
    }

    /** @return array{rule: ?CommissionRule, rate_bps: int, fee: Money} */
    public function buyerProtection(Money $itemsTotal): array
    {
        return $this->charge('buyer_protection', $itemsTotal, null, null, 200);
    }

    /** @return array{rule: ?CommissionRule, rate_bps: int, fee: Money} */
    public function swapFee(Money $value): array
    {
        return $this->charge('swap_fee', $value, null, null, 400);
    }

    /** `POST /listings/fee-quote` shape. */
    public function feeQuote(Money $price, ?Listing $listing = null, ?User $seller = null): array
    {
        $commission = $this->saleCommission($price, $listing, $seller);

        return [
            'platform_fee_rate' => $commission['rate_bps'] / 100,
            'platform_fee' => $commission['fee']->toArray(),
            'seller_receives' => $price->minus($commission['fee'])->toArray(),
        ];
    }

    /** @return array{rule: ?CommissionRule, rate_bps: int, fee: Money} */
    private function charge(string $kind, Money $base, ?Listing $listing, ?User $seller, int $fallbackBps): array
    {
        $rule = $this->matchingRule($kind, $listing, $seller);
        $bps = $rule->rate_bps ?? $fallbackBps;
        $fee = $base->percentage($bps);

        if ($rule !== null) {
            if ($rule->min_amount !== null) {
                $fee = $fee->max($this->fx->convert(Money::of((int) $rule->min_amount, $rule->currency), $base->currency));
            }
            if ($rule->cap_amount !== null) {
                $fee = $fee->min($this->fx->convert(Money::of((int) $rule->cap_amount, $rule->currency), $base->currency));
            }
        }

        // A fee can never exceed the amount it is charged on.
        $fee = $fee->min($base->isNegative() ? Money::zero($base->currency) : $base);

        return ['rule' => $rule, 'rate_bps' => (int) $bps, 'fee' => $fee];
    }

    private function matchingRule(string $kind, ?Listing $listing, ?User $seller): ?CommissionRule
    {
        $categoryIds = [];
        if ($listing?->category_id) {
            $categoryIds = [$listing->category_id];
            if ($listing->category?->parent_id) {
                $categoryIds[] = $listing->category->parent_id;
            }
        }

        return $this->activeRules($kind)
            ->filter(fn (CommissionRule $rule) => match ($rule->scope) {
                'categories' => array_intersect($categoryIds, $rule->scope_values ?? []) !== [],
                'seller_type' => $seller !== null && in_array($seller->seller_type, $rule->scope_values ?? [], true),
                'country' => $seller !== null && in_array($seller->country_code, $rule->scope_values ?? [], true),
                default => true,
            })
            ->sortBy(fn (CommissionRule $rule) => sprintf('%d-%05d', self::SCOPE_RANK[$rule->scope] ?? 9, $rule->priority))
            ->first();
    }

    /** @return Collection<int, CommissionRule> */
    private function activeRules(string $kind): Collection
    {
        // Cache raw rows (never serialised models) and hydrate per request.
        $rows = Cache::remember('commission:rules', now()->addMinutes(5), fn () => CommissionRule::query()
            ->whereIn('status', ['live', 'scheduled'])->get()->map(fn (CommissionRule $r) => $r->getAttributes())->all());
        $all = CommissionRule::hydrate($rows);

        $now = now();

        return $all->filter(fn (CommissionRule $r) => $r->kind === $kind
            && ($r->starts_at === null || $r->starts_at->lte($now))
            && ($r->ends_at === null || $r->ends_at->gt($now)))->values();
    }

    public static function flush(): void
    {
        Cache::forget('commission:rules');
    }
}
