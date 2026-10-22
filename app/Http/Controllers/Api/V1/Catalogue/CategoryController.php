<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\BoostPackage;
use App\Models\Category;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class CategoryController extends Controller
{
    /** `GET /categories` — tree with counts (cached; busted when categories change). */
    public function index(Request $request): JsonResponse
    {
        $tree = Cache::remember('catalogue:tree', now()->addMinutes(10), fn () => CategoryResource::collection(
            Category::query()->whereNull('parent_id')->where('is_active', true)
                ->with(['image', 'children' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('position')->get()
        )->resolve($request));

        return ApiResponse::ok($tree);
    }

    /** `GET /categories/{slug}` */
    public function show(Request $request, string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)
            ->with(['image', 'children' => fn ($q) => $q->where('is_active', true), 'parent'])->first()
            ?? throw ApiException::notFound('Category not found.');

        return ApiResponse::ok((new CategoryResource($category))->detailed());
    }

    /** `GET /boost-packages` */
    public function boostPackages(): JsonResponse
    {
        $packages = BoostPackage::query()->where('is_active', true)->orderBy('position')->get()
            ->map(fn (BoostPackage $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'placement' => $p->placement,
                'duration_days' => $p->duration_days,
                'price' => $p->price()->toArray(),
            ]);

        return ApiResponse::ok($packages);
    }
}
