<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-request viewer context for listing payloads, preloaded in one query per page
 * so `is_favorited` does not cause N+1 queries. Bound as a scoped singleton.
 */
final class ListingViewContext
{
    /** @var array<string, bool> listing id => favourited */
    private array $favorites = [];

    /** @param iterable<Listing> $listings */
    public function preload(iterable $listings, ?User $viewer): void
    {
        if ($viewer === null) {
            return;
        }

        $ids = [];
        foreach ($listings as $listing) {
            if (! array_key_exists($listing->id, $this->favorites)) {
                $ids[] = $listing->id;
                $this->favorites[$listing->id] = false;
            }
        }
        if ($ids === []) {
            return;
        }

        foreach (DB::table('favorites')->where('user_id', $viewer->id)->whereIn('listing_id', $ids)->pluck('listing_id') as $id) {
            $this->favorites[$id] = true;
        }
    }

    public function isFavorited(Listing $listing, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }
        if (! array_key_exists($listing->id, $this->favorites)) {
            $this->preload([$listing], $viewer);
        }

        return $this->favorites[$listing->id];
    }

    public function forget(string $listingId): void
    {
        unset($this->favorites[$listingId]);
    }
}
