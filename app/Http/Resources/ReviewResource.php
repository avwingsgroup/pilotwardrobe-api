<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
final class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Review $review */
        $review = $this->resource;
        $viewer = $request->user();

        $out = [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'tags' => array_values($review->tags ?? []),
            'author' => [
                'display_name' => $review->author?->shortName() ?? 'Pilotwardrobe member',
                'initials' => $review->author?->initials() ?? 'PW',
            ],
            'created_at' => $review->created_at?->toIso8601ZuluString(),
            'role' => $review->role,
        ];

        // The order reference is only meaningful (and only shown) to the two parties.
        if ($viewer !== null && in_array($viewer->id, [$review->author_id, $review->subject_id], true)) {
            $out['order_id'] = $review->order_id;
            $out['direction'] = $viewer->id === $review->author_id ? 'given' : 'received';
        }

        return $out;
    }
}
