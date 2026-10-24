<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\HeroSlideResource;
use App\Http\Resources\ListingResource;
use App\Http\Resources\MediaAssetPresenter;
use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\Listing;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** `GET /home` (V1). Public and identical for everyone, so it is cached for 60 s. */
final class HomeController extends Controller
{
    public const CACHE_KEY = 'home:feed';

    private const WITH = ['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'];

    public function __invoke(Request $request, PlatformSettings $settings): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, 60, function () use ($request, $settings) {
            // Rendered as a visitor so the cached payload never contains personal data.
            $request->setUserResolver(fn () => null);

            $slides = HeroSlide::query()->where('active', true)->orderBy('position')->get()
                ->filter(fn (HeroSlide $s) => ($s->published['status'] ?? 'live') !== 'draft')
                ->map(fn (HeroSlide $s) => (new HeroSlideResource($s))->resolve($request))->values()->all();

            $categories = CategoryResource::collection(
                Category::query()->whereNull('parent_id')->where('is_active', true)->with(['image', 'children'])->orderBy('position')->get()
            )->resolve($request);

            $sections = [];
            foreach ($settings->get('home.sections', config('home.sections')) as $section) {
                $query = Listing::query()->publiclyVisible()->with(self::WITH)->limit((int) ($section['limit'] ?? 8));
                if (! empty($section['category'])) {
                    $category = Category::query()->where('slug', $section['category'])->first();
                    $query->whereIn('category_id', $category?->selfAndDescendantIds() ?? ['__none__']);
                }
                match ($section['sort'] ?? 'newest') {
                    'popular' => $query->orderByDesc('favorites_count')->orderByDesc('views_count'),
                    'promoted' => $query->orderByRaw('(promoted_until > now()) desc nulls last')->orderByDesc('published_at'),
                    default => $query->orderByDesc('published_at'),
                };
                $listings = $query->get();
                if ($listings->isEmpty()) {
                    continue;
                }
                $sections[] = [
                    'key' => $section['key'],
                    'title' => $section['title'],
                    'subtitle' => $section['subtitle'] ?? null,
                    'view_all_to' => $section['view_all_to'] ?? '/marketplace',
                    'listings' => ListingResource::collection($listings)->resolve($request),
                ];
            }

            $gallery = Listing::query()->publiclyVisible()->whereIn('format', ['print', 'digital'])
                ->with(['seller', 'images'])->orderByDesc('favorites_count')->limit(6)->get()
                ->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'photographer' => $l->seller->displayName(),
                    'image' => MediaAssetPresenter::make($l->images->first(), $l->title),
                    'to' => $l->publicPath(),
                ])->values()->all();

            return [
                'hero_slides' => $slides,
                'categories' => $categories,
                'sections' => $sections,
                'gallery' => $gallery,
                'trusted_count' => User::query()->whereIn('role', ['user', 'aviator'])->where('status', 'active')->count(),
            ];
        });

        return ApiResponse::ok($payload)->header('Cache-Control', 'public, max-age=60');
    }
}
