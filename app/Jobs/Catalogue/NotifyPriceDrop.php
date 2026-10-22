<?php

declare(strict_types=1);

namespace App\Jobs\Catalogue;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Tells users who saved a listing that its price dropped (`listing.price_drop`, opt-out via preferences). */
final class NotifyPriceDrop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $listingId, public readonly int $previousAmount)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationService $notifications): void
    {
        $listing = Listing::query()->find($this->listingId);
        if ($listing === null || $listing->status !== ListingStatus::Active || $listing->price_amount >= $this->previousAmount) {
            return;
        }

        DB::table('favorites')->where('listing_id', $listing->id)->orderBy('user_id')
            ->chunk(500, function ($rows) use ($listing, $notifications) {
                foreach (User::query()->whereIn('id', $rows->pluck('user_id'))->get() as $user) {
                    $notifications->notify($user, 'listing.price_drop', [
                        'title' => $listing->title,
                        'slug' => $listing->slug,
                        'price' => $listing->price()->format(),
                        'listing_id' => $listing->id,
                    ], "listing.price_drop:{$listing->id}:{$listing->price_amount}:{$user->id}");
                }
            });
    }
}
