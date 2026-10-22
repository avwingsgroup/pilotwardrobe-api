<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Money\Money;

/** Promo-code validation and discount calculation. Discounts are funded by the platform. */
final class PromoCodes
{
    /** @return array{promo: PromoCode, discount: Money} */
    public function apply(string $code, User $buyer, Money $itemsTotal): array
    {
        $promo = PromoCode::query()->whereRaw('upper(code) = ?', [mb_strtoupper(trim($code))])->first();
        $now = now();

        $invalid = $promo === null
            || ! $promo->is_active
            || ($promo->starts_at && $promo->starts_at->gt($now))
            || ($promo->ends_at && $promo->ends_at->lte($now))
            || ($promo->usage_limit !== null && $promo->times_used >= $promo->usage_limit);
        if ($invalid) {
            throw ApiException::validation(['promo_code' => 'This promo code is not valid.']);
        }

        if ($promo->discount_type === 'fixed' && $promo->currency !== $itemsTotal->currency->value) {
            throw ApiException::validation(['promo_code' => "This promo code can't be used with {$itemsTotal->currency->value}."]);
        }
        if ($promo->min_order_amount !== null && (! $promo->currency || $promo->currency === $itemsTotal->currency->value) && $itemsTotal->amount < $promo->min_order_amount) {
            throw ApiException::validation(['promo_code' => 'Your order is below the minimum for this code.']);
        }

        $used = Order::query()->where('buyer_id', $buyer->id)->where('promo_code_id', $promo->id)->whereNotIn('status', ['cancelled'])->count();
        if ($used >= $promo->per_user_limit) {
            throw ApiException::validation(['promo_code' => "You've already used this promo code."]);
        }

        $discount = $promo->discount_type === 'percent'
            ? $itemsTotal->percentage((int) $promo->discount_value)
            : Money::of((int) $promo->discount_value, $itemsTotal->currency);

        if ($promo->max_discount_amount !== null && (! $promo->currency || $promo->currency === $itemsTotal->currency->value)) {
            $discount = $discount->min(Money::of((int) $promo->max_discount_amount, $itemsTotal->currency));
        }

        return ['promo' => $promo, 'discount' => $discount->min($itemsTotal)];
    }
}
