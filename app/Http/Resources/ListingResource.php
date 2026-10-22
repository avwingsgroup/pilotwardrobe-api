<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ListingFormat;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Services\Catalogue\FitMatcher;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Full `Listing` payload (V2/V3, seller views). Viewer-specific fields: `is_favorited`,
 * `sizes.fit_match`, and `allowed_actions` (owner only).
 *
 * @mixin Listing
 */
final class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Listing $listing */
        $listing = $this->resource;
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $listing->seller_id;
        $category = $listing->category;
        $fitMatch = app(FitMatcher::class)->match($listing, $viewer);

        $data = [
            'id' => $listing->id,
            'slug' => $listing->slug,
            'title' => $listing->title,
            'description' => $listing->description,
            'category' => $category ? CategoryResource::summary($category) : null,
            'breadcrumbs' => $category ? array_map(fn ($c) => CategoryResource::summary($c), $category->lineage()) : [],
            'condition' => $listing->condition?->value,
            'condition_label' => $listing->condition?->label(),
            'format' => $listing->format->value,
            'price' => $listing->price()?->toArray(),
            'compare_at_price' => $listing->compareAtPrice()?->toArray(),
            'minimum_offer' => $listing->negotiable ? $listing->minimumOffer()?->toArray() : null,
            'seller' => SellerSummaryResource::fields($listing->seller),
            'images' => MediaAssetPresenter::many($listing->images, $listing->title),
            'location' => $this->presentLocation($listing),
            'shipping' => $this->presentShipping($listing),
            'freight_summary' => $this->presentFreightSummary($listing),
            'negotiable' => (bool) $listing->negotiable,
            'swappable' => (bool) $listing->swappable,
            'status' => $listing->status->value,
            'promoted' => $listing->isPromoted(),
            'badges' => $this->presentBadges($listing, $fitMatch),
            'brand' => $listing->brand,
            'model' => $listing->model,
            'attributes' => $this->presentAttributes($listing),
            'aircraft_types' => array_values($listing->aircraft_types ?? []),
            'tags' => array_values($listing->tags ?? []),
            'sizes' => $this->presentSizes($listing, $fitMatch),
            'stock' => $listing->format === ListingFormat::Physical ? $listing->availableQuantity() : null,
            'licensing' => $listing->licensing,
            'rating' => $listing->rating_avg !== null ? round($listing->rating_avg, 1) : null,
            'sold_count' => (int) $listing->sold_count,
            'views_count' => (int) $listing->views_count,
            'favorites_count' => (int) $listing->favorites_count,
            'offers_count' => (int) $listing->offers_count,
            'is_favorited' => app(ListingViewContext::class)->isFavorited($listing, $viewer),
            'reserved_for_you' => $listing->status === ListingStatus::Reserved && $viewer !== null && $listing->reserved_for_user_id === $viewer->id,
            'created_at' => $listing->created_at?->toIso8601ZuluString(),
            'updated_at' => $listing->updated_at?->toIso8601ZuluString(),
            'published_at' => $listing->published_at?->toIso8601ZuluString(),
        ];

        if ($isOwner) {
            $data['allowed_actions'] = self::ownerActions($listing);
            $data['moderation_note'] = $listing->moderation_note;
            $data['serial_number'] = $listing->serial_number;
            $data['year_purchased'] = $listing->year_purchased;
            $data['auto_decline_below_minimum'] = (bool) $listing->auto_decline_below_minimum;
            $data['freight'] = $listing->freight;
            $data['stock_total'] = $listing->stock;
            $data['raw_attributes'] = $listing->attributes ?? (object) [];
        }

        return $data;
    }

    /** Buttons the seller may use, derived from the listing state machine. */
    public static function ownerActions(Listing $listing): array
    {
        return match ($listing->status) {
            ListingStatus::Draft => ['finish', 'preview', 'delete'],
            ListingStatus::PendingReview => ['preview', 'edit'],
            ListingStatus::NeedsChanges => ['edit', 'resubmit', 'preview', 'delete'],
            ListingStatus::Active => ['edit', 'preview', 'pause', 'mark_sold', 'mark_unavailable', 'archive', 'promote', 'share'],
            ListingStatus::Paused => ['edit', 'preview', 'resume', 'mark_sold', 'archive'],
            ListingStatus::Reserved => ['preview', 'mark_sold', 'message'],
            ListingStatus::Unavailable => ['edit', 'resume', 'archive'],
            ListingStatus::Sold => ['preview', 'relist'],
            ListingStatus::Archived => ['relist', 'delete'],
            ListingStatus::Swapped, ListingStatus::Rejected, ListingStatus::Removed => ['preview'],
        };
    }

    /** Small listing card used inside offers, swaps, carts and orders. */
    public static function snippet(Listing $listing, bool $withMinimumOffer = false): array
    {
        $out = [
            'id' => $listing->id,
            'slug' => $listing->slug,
            'title' => $listing->title,
            'image' => MediaAssetPresenter::make($listing->images->first(), $listing->title),
            'price' => $listing->price()?->toArray(),
            'condition' => $listing->condition?->value,
            'status' => $listing->status->value,
        ];
        if ($withMinimumOffer && $listing->minimum_offer_amount !== null) {
            $out['minimum_offer'] = $listing->minimumOffer()?->toArray();
        }

        return $out;
    }

    private function presentLocation(Listing $listing): ?array
    {
        $location = $listing->location;
        if (! empty($location)) {
            return [
                'country_code' => $location['country_code'] ?? null,
                'state' => $location['state'] ?? null,
                'city' => $location['city'] ?? null,
                'airport_code' => $location['airport_code'] ?? null,
            ];
        }

        $seller = $listing->seller;

        return $seller->city || $seller->country_code
            ? ['country_code' => $seller->country_code, 'state' => $seller->state, 'city' => $seller->city, 'airport_code' => null]
            : null;
    }

    private function presentShipping(Listing $listing): array
    {
        return array_map(fn (array $option) => [
            'id' => $option['id'],
            'method' => $option['method'],
            'label' => $option['label'],
            'description' => $option['description'] ?? null,
            'price' => Money::of((int) ($option['price_amount'] ?? 0), $listing->currency)->toArray(),
            'estimated_days' => $option['estimated_days'] ?? null,
        ], $listing->shipping ?? []);
    }

    private function presentFreightSummary(Listing $listing): ?string
    {
        if ($listing->format === ListingFormat::Digital) {
            return 'Instant download';
        }
        $options = $listing->shipping ?? [];
        if ($options === []) {
            return null;
        }
        $prices = array_map(fn ($o) => (int) ($o['price_amount'] ?? 0), $options);
        $cheapest = min($prices);
        if ($cheapest === 0 || $listing->free_delivery) {
            return count(array_filter($options, fn ($o) => $o['method'] === 'handoff')) === count($options) ? 'Airfield hand-off' : 'Free delivery';
        }

        return '+'.Money::of($cheapest, $listing->currency)->format().' tracked freight';
    }

    private function presentBadges(Listing $listing, ?array $fitMatch): array
    {
        $badges = [];
        if ($fitMatch !== null) {
            $badges[] = ['kind' => 'fits_you', 'label' => 'Fits you · '.$fitMatch['size']];
        }
        if ($listing->seller->isOfficialStore()) {
            $badges[] = ['kind' => 'official_store', 'label' => 'Official store'];
        }
        if ($listing->sold_count >= 100) {
            $badges[] = ['kind' => 'best_seller', 'label' => 'Best seller'];
        }
        if ($listing->swappable) {
            $badges[] = ['kind' => 'swap_ok', 'label' => 'Swap OK'];
        }
        if ($listing->free_delivery) {
            $badges[] = ['kind' => 'free_delivery', 'label' => 'Free delivery'];
        }

        return $badges;
    }

    private function presentAttributes(Listing $listing): array
    {
        $out = [];
        if ($listing->brand) {
            $out[] = ['label' => 'Brand', 'value' => $listing->brand];
        }
        if ($listing->model) {
            $out[] = ['label' => 'Model', 'value' => $listing->model];
        }

        $definitions = collect($listing->category->attributes ?? [])->keyBy('key');
        foreach ($listing->attributes ?? [] as $key => $value) {
            if ($value === null || $value === '' || in_array($key, ['brand', 'model'], true)) {
                continue;
            }
            $out[] = [
                'label' => $definitions->get($key)['label'] ?? Str::headline((string) $key),
                'value' => is_array($value) ? implode(', ', $value) : (string) $value,
            ];
        }

        if ($listing->category) {
            $out[] = ['label' => 'Category', 'value' => $listing->category->name];
        }

        return $out;
    }

    private function presentSizes(Listing $listing, ?array $fitMatch): ?array
    {
        $sizes = $listing->sizes;
        if (empty($sizes['available']) && empty($sizes['unavailable'])) {
            return null;
        }

        $chart = $listing->category?->size_chart;

        return [
            'available' => array_values($sizes['available'] ?? []),
            'unavailable' => array_values($sizes['unavailable'] ?? []),
            'chart' => $chart ? ['columns' => $chart['columns'] ?? [], 'rows' => $chart['rows'] ?? [], 'note' => $chart['note'] ?? null] : null,
            'fit_match' => $fitMatch,
        ];
    }
}
