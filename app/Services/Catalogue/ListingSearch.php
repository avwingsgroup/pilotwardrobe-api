<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ListingCondition;
use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use App\Support\Http\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `GET /listings` (V2): PostgreSQL full-text search plus the marketplace filters, named
 * sorts and filter-panel facets. Only `active`/`reserved` listings from sellers who are
 * not suspended or deactivated are ever returned.
 */
final class ListingSearch
{
    public const SORTS = ['newest', 'recommended', 'price_asc', 'price_desc', 'popular', 'rating', 'nearest'];

    /** @var array<string, mixed> */
    private array $filters = [];

    public function fromRequest(Request $request): self
    {
        $this->filters = [
            'search' => QueryParams::search($request),
            'category' => QueryParams::list($request, 'category'),
            'condition' => array_values(array_intersect(QueryParams::list($request, 'condition'), ListingCondition::values())),
            'deal_type' => array_values(array_intersect(QueryParams::list($request, 'deal_type'), ['buy_now', 'offers', 'swaps'])),
            'min_price' => QueryParams::int($request, 'min_price'),
            'max_price' => QueryParams::int($request, 'max_price'),
            'verified_only' => QueryParams::bool($request, 'verified_only') === true,
            'min_rating' => is_numeric($request->query('min_rating')) ? (float) $request->query('min_rating') : null,
            'free_delivery' => QueryParams::bool($request, 'free_delivery') === true,
            'format' => array_values(array_intersect(QueryParams::list($request, 'format'), ['physical', 'digital', 'print'])),
            'seller' => $request->query('seller') ? (string) $request->query('seller') : null,
            'aircraft_type' => QueryParams::list($request, 'aircraft_type'),
            'promoted' => QueryParams::bool($request, 'promoted') === true,
            'currency' => $request->query('currency') ? strtoupper((string) $request->query('currency')) : null,
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'newest',
        ];

        return $this;
    }

    public function paginate(int $perPage, ?User $viewer): LengthAwarePaginator
    {
        $query = $this->apply($this->base(), $this->filters);
        $this->applySort($query, $viewer);

        return $query->with(['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Facet counts for the filter panel. Each facet ignores its own dimension so counts stay useful. */
    public function facets(): array
    {
        $key = 'facets:'.md5(json_encode(array_diff_key($this->filters, ['sort' => 1])));

        return Cache::remember($key, 60, function () {
            $without = fn (string ...$dims) => $this->apply($this->base(), array_merge($this->filters, array_fill_keys($dims, null)));

            $categoryRows = $without('category')->toBase()
                ->select('listings.category_id', DB::raw('count(*) as c'))->groupBy('listings.category_id')->pluck('c', 'listings.category_id');
            $categories = Category::query()->whereIn('id', $categoryRows->keys())->get(['id', 'slug', 'name'])
                ->map(fn ($c) => ['value' => $c->slug, 'label' => $c->name, 'count' => (int) $categoryRows[$c->id]])
                ->sortByDesc('count')->values()->all();

            $conditionRows = $without('condition')->toBase()
                ->select('listings.condition', DB::raw('count(*) as c'))->groupBy('listings.condition')->pluck('c', 'listings.condition');
            $conditions = [];
            foreach (ListingCondition::cases() as $case) {
                $conditions[] = ['value' => $case->value, 'label' => $case->label(), 'count' => (int) ($conditionRows[$case->value] ?? 0)];
            }

            $deals = $without('deal_type')->toBase()->select(DB::raw('count(*) as buy_now, count(*) filter (where listings.negotiable) as offers, count(*) filter (where listings.swappable) as swaps'))->first();

            $seller = $without('verified_only', 'min_rating', 'free_delivery')->toBase()
                ->join('users as fu', 'fu.id', '=', 'listings.seller_id')
                ->leftJoin('kyc_verifications as fk', 'fk.user_id', '=', 'listings.seller_id')
                ->select(DB::raw("count(*) filter (where fk.status = 'approved' or fu.seller_type = 'official_store') as verified, count(*) filter (where fu.rating_avg >= 4.5) as rating_45, count(*) filter (where listings.free_delivery) as free_delivery"))
                ->first();

            $price = $without('min_price', 'max_price')->toBase()->select(DB::raw('min(listings.price_amount) as min, max(listings.price_amount) as max'))->first();

            return [
                'categories' => $categories,
                'conditions' => $conditions,
                'deal_types' => [
                    ['value' => 'buy_now', 'label' => 'Buy now', 'count' => (int) ($deals->buy_now ?? 0)],
                    ['value' => 'offers', 'label' => 'Accepts offers', 'count' => (int) ($deals->offers ?? 0)],
                    ['value' => 'swaps', 'label' => 'Open to swaps', 'count' => (int) ($deals->swaps ?? 0)],
                ],
                'seller' => [
                    ['value' => 'verified_only', 'label' => 'KYC verified only', 'count' => (int) ($seller->verified ?? 0)],
                    ['value' => 'rating_45', 'label' => 'Rating 4.5+', 'count' => (int) ($seller->rating_45 ?? 0)],
                    ['value' => 'free_delivery', 'label' => 'Free delivery', 'count' => (int) ($seller->free_delivery ?? 0)],
                ],
                'price_range' => [
                    'min' => (int) ($price->min ?? 0),
                    'max' => (int) ($price->max ?? 0),
                    'currency' => $this->filters['currency'] ?? 'USD',
                ],
            ];
        });
    }

    public function appliedCurrency(): string
    {
        return $this->filters['currency'] ?? 'USD';
    }

    private function base(): Builder
    {
        return Listing::query()->select('listings.*')->publiclyVisible();
    }

    /** @param array<string, mixed> $f */
    private function apply(Builder $query, array $f): Builder
    {
        if (! empty($f['search'])) {
            $tsquery = $this->prefixQuery($f['search']);
            if ($tsquery !== null) {
                $query->whereRaw("listings.search_vector @@ to_tsquery('english', ?)", [$tsquery]);
            }
        }

        if (! empty($f['category'])) {
            $ids = Category::query()->whereIn('slug', $f['category'])->get()->flatMap(fn (Category $c) => $c->selfAndDescendantIds())->unique()->all();
            $query->whereIn('listings.category_id', $ids ?: ['__none__']);
        }

        if (! empty($f['condition'])) {
            $query->whereIn('listings.condition', $f['condition']);
        }

        if (! empty($f['deal_type'])) {
            $query->where(function (Builder $q) use ($f) {
                foreach ($f['deal_type'] as $deal) {
                    match ($deal) {
                        'offers' => $q->orWhere('listings.negotiable', true),
                        'swaps' => $q->orWhere('listings.swappable', true),
                        default => $q->orWhereNotNull('listings.price_amount'),
                    };
                }
            });
        }

        if ($f['min_price'] ?? null) {
            $query->where('listings.price_amount', '>=', $f['min_price']);
        }
        if ($f['max_price'] ?? null) {
            $query->where('listings.price_amount', '<=', $f['max_price']);
        }
        if (! empty($f['currency'])) {
            $query->where('listings.currency', $f['currency']);
        }

        if (! empty($f['verified_only'])) {
            $query->whereHas('seller', fn (Builder $q) => $q->where('seller_type', 'official_store')
                ->orWhereHas('kyc', fn (Builder $k) => $k->where('status', 'approved')));
        }
        if (! empty($f['min_rating'])) {
            $query->whereHas('seller', fn (Builder $q) => $q->where('rating_avg', '>=', $f['min_rating']));
        }
        if (! empty($f['free_delivery'])) {
            $query->where('listings.free_delivery', true);
        }
        if (! empty($f['format'])) {
            $query->whereIn('listings.format', $f['format']);
        }
        if (! empty($f['seller'])) {
            $query->whereHas('seller', fn (Builder $q) => $q->whereRaw('lower(username) = ?', [mb_strtolower($f['seller'])]));
        }
        if (! empty($f['aircraft_type'])) {
            $query->where(function (Builder $q) use ($f) {
                foreach ($f['aircraft_type'] as $type) {
                    $q->orWhereRaw('listings.aircraft_types @> ?::jsonb', [json_encode([strtoupper($type)])]);
                }
            });
        }
        if (! empty($f['promoted'])) {
            $query->where('listings.promoted_until', '>', now());
        }

        return $query;
    }

    private function applySort(Builder $query, ?User $viewer): void
    {
        $search = $this->filters['search'] ? $this->prefixQuery($this->filters['search']) : null;

        match ($this->filters['sort']) {
            'price_asc' => $query->orderBy('listings.price_amount')->orderByDesc('listings.published_at'),
            'price_desc' => $query->orderByDesc('listings.price_amount')->orderByDesc('listings.published_at'),
            'popular' => $query->orderByRaw('(listings.favorites_count * 3 + listings.views_count + listings.sold_count * 5) desc')->orderByDesc('listings.published_at'),
            'rating' => $query->orderByRaw('(select rating_avg from users where users.id = listings.seller_id) desc nulls last')->orderByDesc('listings.published_at'),
            'nearest' => $viewer?->country_code
                ? $query->orderByRaw("(listings.location->>'country_code' = ?) desc", [$viewer->country_code])->orderByDesc('listings.published_at')
                : $query->orderByDesc('listings.published_at'),
            'recommended' => $search !== null
                ? $query->orderByRaw('(listings.promoted_until > now()) desc nulls last')->orderByRaw("ts_rank(listings.search_vector, to_tsquery('english', ?)) desc", [$search])
                : $query->orderByRaw('(listings.promoted_until > now()) desc nulls last')->orderByDesc('listings.favorites_count')->orderByDesc('listings.published_at'),
            default => $query->orderByDesc('listings.published_at'),
        };

        $query->orderBy('listings.id');
    }

    /** "bose head" → "bose:* & head:*" (alphanumerics only, so user input cannot break the tsquery). */
    private function prefixQuery(string $search): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($search), $m);
        $terms = array_slice(array_unique($m[0]), 0, 8);

        return $terms === [] ? null : implode(' & ', array_map(fn ($t) => $t.':*', $terms));
    }
}
