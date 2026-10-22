<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\Category;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Suggested price range (VB2 "price check") from comparable completed sales over the
 * last 90 days: same category, same condition, brand/model when known. Falls back to
 * live comparable listings when there are too few sales. Returns the interquartile range.
 */
final class PriceCheck
{
    private const MIN_SAMPLES = 3;

    /** @param array<string, mixed> $input */
    public function suggest(array $input): array
    {
        $currency = strtoupper((string) ($input['price']['currency'] ?? 'USD'));
        $categoryIds = [];
        if (! empty($input['category_id'])) {
            $category = Category::query()->where('id', $input['category_id'])->orWhere('slug', $input['category_id'])->first();
            $categoryIds = $category?->selfAndDescendantIds() ?? [];
        }

        $sales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('listings as l', 'l.id', '=', 'oi.listing_id')
            ->whereIn('o.status', ['completed', 'delivered', 'in_transit', 'awaiting_dispatch', 'paid'])
            ->where('o.paid_at', '>=', now()->subDays(90))
            ->where('o.currency', $currency)
            ->when($categoryIds, fn ($q) => $q->whereIn('l.category_id', $categoryIds))
            ->when(! empty($input['condition']), fn ($q) => $q->where('oi.condition', $input['condition']))
            ->when(! empty($input['brand']), fn ($q) => $q->whereRaw('lower(l.brand) = ?', [mb_strtolower((string) $input['brand'])]))
            ->when(! empty($input['model']), fn ($q) => $q->whereRaw('lower(l.model) = ?', [mb_strtolower((string) $input['model'])]))
            ->pluck('oi.unit_price')->map(fn ($v) => (int) $v)->sort()->values()->all();

        $basis = 'sales';
        if (count($sales) < self::MIN_SAMPLES) {
            $sales = DB::table('listings')->whereIn('status', ['active', 'reserved'])->whereNull('deleted_at')
                ->where('currency', $currency)
                ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
                ->when(! empty($input['condition']), fn ($q) => $q->where('condition', $input['condition']))
                ->when(! empty($input['brand']), fn ($q) => $q->whereRaw('lower(brand) = ?', [mb_strtolower((string) $input['brand'])]))
                ->when(! empty($input['listing_id']), fn ($q) => $q->where('id', '!=', $input['listing_id']))
                ->pluck('price_amount')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $basis = 'live_listings';
        }

        if (count($sales) < self::MIN_SAMPLES) {
            return ['available' => false, 'comparables_count' => count($sales), 'basis' => $basis, 'currency' => $currency,
                'message' => 'Not enough comparable items yet to suggest a price.'];
        }

        $low = $this->percentile($sales, 25);
        $high = $this->percentile($sales, 75);
        $price = isset($input['price']['amount']) ? (int) $input['price']['amount'] : null;

        return [
            'available' => true,
            'range' => ['min' => Money::of($low, $currency)->toArray(), 'max' => Money::of($high, $currency)->toArray()],
            'median' => Money::of($this->percentile($sales, 50), $currency)->toArray(),
            'currency' => $currency,
            'comparables_count' => count($sales),
            'basis' => $basis,
            'window_days' => 90,
            'position' => $price === null ? null : ($price < $low ? 'below_range' : ($price > $high ? 'above_range' : 'fast_sell')),
            'summary' => sprintf('Based on %d %s in the last 90 days.', count($sales), $basis === 'sales' ? 'comparable sales' : 'comparable live listings'),
        ];
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $p): int
    {
        $index = ($p / 100) * (count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return (int) round($sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower));
    }
}
