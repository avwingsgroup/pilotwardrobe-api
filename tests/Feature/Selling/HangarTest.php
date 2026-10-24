<?php

declare(strict_types=1);

namespace Tests\Feature\Selling;

use App\Models\Listing;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Tests\TestCase;

/** The Hangar overview formats every stat as a number, so none may be null. */
final class HangarTest extends TestCase
{
    public function test_new_seller_gets_numeric_stats_without_any_reviews(): void
    {
        $seller = User::factory()->kycApproved()->create();
        Listing::factory()->active()->create(['seller_id' => $seller->id]);
        $this->actingAsUser($seller);

        $stats = $this->getJson('/api/v1/me/hangar')->assertOk()->json('data.stats');

        foreach (['seller_rating', 'previous_rating', 'reviews_count', 'live_listings', 'listings_need_photos', 'escrow_orders'] as $key) {
            $this->assertIsNumeric($stats[$key], "stats.{$key} must be a number");
        }
        $this->assertEquals($stats['seller_rating'], $stats['previous_rating'], 'no history means no change');
        $this->assertSame(1, $stats['live_listings']);
    }

    public function test_previous_rating_reflects_reviews_older_than_thirty_days(): void
    {
        $seller = User::factory()->kycApproved()->create(['rating_avg' => 4.5, 'reviews_count' => 2]);
        $buyer = User::factory()->create();
        $listing = Listing::factory()->active()->create(['seller_id' => $seller->id]);
        $review = fn (int $rating, $at) => Review::query()->forceCreate([
            'order_id' => Order::query()->forceCreate([
                'number' => 'PW-'.random_int(100000, 999999), 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'status' => 'completed',
                'escrow_status' => 'released', 'kind' => 'purchase', 'currency' => 'USD', 'items_amount' => 1000, 'total_amount' => 1000,
            ])->id,
            'author_id' => $buyer->id, 'subject_id' => $seller->id, 'listing_id' => $listing->id, 'role' => 'seller', 'rating' => $rating,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $review(4, now()->subDays(45));
        $review(5, now()->subDays(2));
        $this->actingAsUser($seller);

        $this->getJson('/api/v1/me/hangar')->assertOk()
            ->assertJsonPath('data.stats.seller_rating', 4.5)
            ->assertJsonPath('data.stats.previous_rating', 4);
    }
}
