<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Category tree node (`GET /categories`). Detail (`GET /categories/{slug}`) adds the
 * attribute definitions, size chart and allowed sizes used by the listing wizard.
 *
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    private bool $detail = false;

    public function detailed(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        $data = [
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'listings_count' => (int) $category->listings_count,
            'image' => MediaAssetPresenter::make($category->image, $category->name),
            'sized' => (bool) $category->sized,
        ];

        if ($category->relationLoaded('children') && $category->children->isNotEmpty()) {
            $data['children'] = $category->children->map(fn (Category $child) => [
                'id' => $child->id,
                'slug' => $child->slug,
                'name' => $child->name,
                'parent_id' => $child->parent_id,
                'listings_count' => (int) $child->listings_count,
                'sized' => (bool) $child->sized,
            ])->values()->all();
        }

        if ($this->detail) {
            $data['description'] = $category->description;
            $data['attributes'] = $category->attributes ?? [];
            $data['sizes'] = $category->sizes ?? [];
            $data['size_chart'] = $category->size_chart
                ? array_intersect_key($category->size_chart, array_flip(['columns', 'rows', 'note']))
                : null;
            $data['breadcrumbs'] = array_map(fn (Category $c) => self::summary($c), $category->lineage());
        }

        return $data;
    }

    /** `{ id, slug, name }` */
    public static function summary(Category $category): array
    {
        return ['id' => $category->id, 'slug' => $category->slug, 'name' => $category->name];
    }
}
