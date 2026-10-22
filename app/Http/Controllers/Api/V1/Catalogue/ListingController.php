<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\ListingStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingViewContext;
use App\Models\Listing;
use App\Services\Catalogue\ListingSearch;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Public marketplace reads (V2 search, V3 detail, related rail). */
final class ListingController extends Controller
{
    private const WITH = ['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'];

    /** `GET /listings` */
    public function index(Request $request, ListingSearch $search, ListingViewContext $context): JsonResponse
    {
        $search->fromRequest($request);
        $page = $search->paginate(QueryParams::perPage($request), $request->user());
        $context->preload($page->items(), $request->user());

        return ApiResponse::paginated($page, ListingResource::class, [
            'facets' => $search->facets(),
            'applied_currency' => $search->appliedCurrency(),
        ]);
    }

    /** `GET /listings/{slug}` — 404 for anything not publicly visible, except to its seller. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $listing = Listing::query()->with(self::WITH)->where('slug', $slug)->first();
        $viewer = $request->user();
        $public = $listing !== null
            && in_array($listing->status, ListingStatus::publiclyVisible(), true)
            && in_array($listing->seller->status, [UserStatus::Active, UserStatus::Restricted], true);
        $privileged = $listing !== null && $viewer !== null
            && ($viewer->id === $listing->seller_id || $viewer->hasPermission('listings.view'));
        $visible = $public || $privileged;

        if (! $visible) {
            if ($listing !== null && in_array($listing->status, [ListingStatus::Removed, ListingStatus::Sold, ListingStatus::Swapped], true)) {
                // Removed/sold listings stay addressable for history but tell crawlers they are gone.
                throw new ApiException(ErrorCode::NOT_FOUND, 'This listing is no longer available.', status: 410);
            }

            throw ApiException::notFound('Listing not found.');
        }

        $this->countView($request, $listing);

        return ApiResponse::ok(new ListingResource($listing));
    }

    /** `GET /listings/{slug}/related` — same category first, then same brand. */
    public function related(Request $request, string $slug, ListingViewContext $context): JsonResponse
    {
        $listing = Listing::query()->where('slug', $slug)->first() ?? throw ApiException::notFound('Listing not found.');

        $related = Listing::query()->publiclyVisible()->with(self::WITH)
            ->where('listings.id', '!=', $listing->id)
            ->where(fn ($q) => $q->where('category_id', $listing->category_id)->when($listing->brand, fn ($b) => $b->orWhere('brand', $listing->brand)))
            ->orderByRaw('(category_id = ?) desc', [$listing->category_id])
            ->orderByDesc('published_at')
            ->limit(8)->get();

        $context->preload($related, $request->user());

        return ApiResponse::ok(ListingResource::collection($related)->resolve($request));
    }

    /** Deduplicated per viewer per day; written after the response is sent. */
    private function countView(Request $request, Listing $listing): void
    {
        if ($request->user()?->id === $listing->seller_id) {
            return;
        }

        $viewer = $request->user()->id ?? hash('sha256', $request->ip().'|'.$request->userAgent());
        $key = "view:{$listing->id}:{$viewer}:".now()->format('Ymd');

        if (Cache::add($key, 1, now()->endOfDay())) {
            defer(fn () => DB::table('listings')->where('id', $listing->id)->increment('views_count'));
        }
    }
}
