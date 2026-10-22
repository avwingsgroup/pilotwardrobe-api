<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\FitKitProfile;
use App\Models\Listing;
use App\Models\User;
use Tests\TestCase;

final class ListingBrowseTest extends TestCase
{
    public function test_search_returns_only_public_listings_with_contract_shape(): void
    {
        $headsets = Category::factory()->create(['slug' => 'headsets', 'name' => 'Headsets & audio']);
        $anr = Category::factory()->create(['slug' => 'anr-headsets', 'name' => 'ANR headsets', 'parent_id' => $headsets->id]);

        Listing::factory()->active()->create(['title' => 'Bose A20 Aviation Headset', 'category_id' => $anr->id, 'price_amount' => 74500]);
        Listing::factory()->active()->create(['title' => 'Lightspeed Zulu 3 headset', 'category_id' => $anr->id, 'price_amount' => 78000, 'condition' => ListingCondition::Good]);
        Listing::factory()->draft()->create(['title' => 'Draft headset', 'category_id' => $anr->id]);
        Listing::factory()->status(ListingStatus::Removed)->create(['title' => 'Removed headset', 'category_id' => $anr->id]);
        $suspendedSeller = User::factory()->suspended()->create();
        Listing::factory()->active()->create(['title' => 'Suspended seller headset', 'seller_id' => $suspendedSeller->id, 'category_id' => $anr->id]);

        $response = $this->getJson('/api/v1/listings?search=head&category=headsets&sort=price_asc&per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.price.amount', 74500)
            ->assertJsonPath('data.0.price.currency', 'USD')
            ->assertJsonPath('data.0.price.formatted', '$745.00')
            ->assertJsonPath('data.0.category.slug', 'anr-headsets')
            ->assertJsonPath('meta.applied_currency', 'USD')
            ->assertJsonStructure([
                'data' => [['id', 'slug', 'title', 'images' => [['id', 'public_id', 'secure_url', 'width', 'height', 'variants' => ['thumbnail', 'small', 'medium', 'large', 'original']]],
                    'seller' => ['id', 'username', 'display_name', 'verified', 'rating'], 'shipping', 'freight_summary', 'badges', 'is_favorited']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to', 'facets' => ['categories', 'conditions', 'deal_types', 'seller', 'price_range']],
                'links' => ['first', 'last', 'next', 'prev'],
            ]);

        $this->assertArrayNotHasKey('allowed_actions', $response->json('data.0'));
        $this->assertArrayNotHasKey('email', $response->json('data.0.seller'));
    }

    public function test_condition_and_price_filters(): void
    {
        Listing::factory()->active()->create(['price_amount' => 5000, 'condition' => ListingCondition::New]);
        Listing::factory()->active()->create(['price_amount' => 50000, 'condition' => ListingCondition::Good]);
        Listing::factory()->active()->create(['price_amount' => 90000, 'condition' => ListingCondition::LikeNew]);

        $this->getJson('/api/v1/listings?condition=like_new,good&min_price=10000')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/listings?max_price=10000')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $this->getJson('/api/v1/listings?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_listing_detail_and_404_for_drafts(): void
    {
        $listing = Listing::factory()->active()->create();
        $draft = Listing::factory()->draft()->create();

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk()->assertJsonPath('data.id', $listing->id);
        $this->assertErrorEnvelope($this->getJson("/api/v1/listings/{$draft->slug}"), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->getJson('/api/v1/listings/does-not-exist'), 404, 'NOT_FOUND');

        // The seller can preview their own draft.
        $this->actingAsUser($draft->seller);
        $this->getJson("/api/v1/listings/{$draft->slug}")->assertOk()->assertJsonPath('data.allowed_actions.0', 'finish');
    }

    public function test_fit_match_is_computed_from_default_fit_kit(): void
    {
        $uniforms = Category::factory()->sized()->create(['slug' => 'uniforms']);
        $listing = Listing::factory()->active()->create(['category_id' => $uniforms->id, 'sizes' => ['available' => ['S', 'M', 'L'], 'unavailable' => ['XL']]]);
        $viewer = User::factory()->create();
        FitKitProfile::query()->create(['user_id' => $viewer->id, 'name' => 'Uniform fit', 'is_default' => true, 'collar' => '15.5"', 'sleeve' => '34']);

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk()->assertJsonPath('data.sizes.fit_match', null);

        $this->actingAsUser($viewer);
        $this->getJson("/api/v1/listings/{$listing->slug}")
            ->assertOk()
            ->assertJsonPath('data.sizes.fit_match.size', 'M')
            ->assertJsonPath('data.sizes.fit_match.profile_name', 'Uniform fit')
            ->assertJsonPath('data.badges.0.kind', 'fits_you');
    }

    public function test_favorite_is_idempotent_and_reflected_in_payloads(): void
    {
        $listing = Listing::factory()->active()->create();
        $this->actingAsUser();

        $this->postJson("/api/v1/listings/{$listing->id}/favorite")->assertOk()->assertJsonPath('data.favorites_count', 1);
        $this->postJson("/api/v1/listings/{$listing->id}/favorite")->assertOk()->assertJsonPath('data.favorites_count', 1);
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertJsonPath('data.is_favorited', true);
        $this->getJson('/api/v1/me/favorites')->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson("/api/v1/listings/{$listing->id}/favorite")->assertOk()->assertJsonPath('data.favorites_count', 0);
    }

    public function test_categories_and_seller_profile(): void
    {
        $parent = Category::factory()->create(['slug' => 'headsets']);
        Category::factory()->create(['slug' => 'anr-headsets', 'parent_id' => $parent->id]);
        $seller = User::factory()->kycApproved()->create(['username' => 'nehemiah_o', 'rating_breakdown' => ['5' => 3, '4' => 1]]);
        Listing::factory()->active()->count(2)->create(['seller_id' => $seller->id]);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.children.0.slug', 'anr-headsets');
        $this->getJson('/api/v1/categories/headsets')->assertOk()->assertJsonPath('data.slug', 'headsets');

        $this->getJson('/api/v1/sellers/nehemiah_o')
            ->assertOk()
            ->assertJsonPath('data.username', 'nehemiah_o')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.stats.listings_count', 2)
            ->assertJsonPath('data.is_following', false)
            // An object keyed by star rating, never a renumbered list.
            ->assertJsonPath('data.rating_breakdown', [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 3])
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone');

        $this->getJson('/api/v1/sellers/nehemiah_o/listings')->assertOk()->assertJsonPath('meta.total', 2);

        $this->actingAsUser();
        $this->postJson('/api/v1/sellers/nehemiah_o/follow')->assertOk()->assertJsonPath('data.followers', 1);
        $this->postJson('/api/v1/sellers/nehemiah_o/follow')->assertOk()->assertJsonPath('data.followers', 1);
    }

    public function test_home_feed(): void
    {
        Listing::factory()->active()->count(3)->create();

        $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonStructure(['data' => ['hero_slides', 'categories', 'sections' => [['key', 'title', 'listings']], 'gallery', 'trusted_count']]);
    }
}
