<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingViewContext;
use App\Models\Listing;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FavoriteController extends Controller
{
    /** `GET /me/favorites` */
    public function index(Request $request, ListingViewContext $context): JsonResponse
    {
        $page = Listing::query()
            ->join('favorites', 'favorites.listing_id', '=', 'listings.id')
            ->where('favorites.user_id', $request->user()->id)
            ->select('listings.*')
            ->with(['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'])
            ->orderByDesc('favorites.created_at')
            ->paginate(QueryParams::perPage($request))->withQueryString();
        $context->preload($page->items(), $request->user());

        return ApiResponse::paginated($page, ListingResource::class);
    }

    /** `POST /listings/{id}/favorite` — idempotent. */
    public function store(Request $request, string $id): JsonResponse
    {
        $listing = Listing::query()->publiclyVisible()->whereKey($id)->first() ?? throw ApiException::notFound('Listing not found.');

        $count = DB::transaction(function () use ($listing, $request) {
            $inserted = DB::table('favorites')->insertOrIgnore([
                'user_id' => $request->user()->id,
                'listing_id' => $listing->id,
                'price_at_save' => $listing->price_amount,
                'created_at' => now(),
            ]);
            if ($inserted) {
                $listing->increment('favorites_count');
            }

            return (int) $listing->fresh()->favorites_count;
        });

        return ApiResponse::ok(['favorites_count' => $count, 'is_favorited' => true]);
    }

    /** `DELETE /listings/{id}/favorite` — idempotent. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $listing = Listing::query()->whereKey($id)->first() ?? throw ApiException::notFound('Listing not found.');

        $count = DB::transaction(function () use ($listing, $request) {
            $deleted = DB::table('favorites')->where('user_id', $request->user()->id)->where('listing_id', $listing->id)->delete();
            if ($deleted) {
                DB::table('listings')->where('id', $listing->id)->where('favorites_count', '>', 0)->decrement('favorites_count');
            }

            return (int) $listing->fresh()->favorites_count;
        });

        return ApiResponse::ok(['favorites_count' => $count, 'is_favorited' => false]);
    }
}
