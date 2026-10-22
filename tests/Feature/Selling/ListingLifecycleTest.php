<?php

declare(strict_types=1);

namespace Tests\Feature\Selling;

use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;
use App\Services\Pricing\CommissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ListingLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function draftPayload(Category $category): array
    {
        return [
            'title' => 'David Clark H10-13.4 headset',
            'description' => 'Lightly used, new ear seals, original case and receipt included.',
            'category_id' => $category->slug,
            'brand' => 'David Clark',
            'model' => 'H10-13.4',
            'attributes' => ['connector' => 'Dual GA'],
            'condition' => 'good',
            'format' => 'physical',
            'price' => ['amount' => 18500, 'currency' => 'USD'],
            'negotiable' => true,
            'minimum_offer' => ['amount' => 16000, 'currency' => 'USD'],
            'freight' => ['method' => 'pilotwardrobe_freight', 'who_pays' => 'buyer'],
        ];
    }

    public function test_full_wizard_flow_publishes_for_kyc_verified_seller(): void
    {
        $category = Category::factory()->create(['slug' => 'headsets']);
        $seller = $this->actingAsUser(User::factory()->kycApproved()->create());

        $draft = $this->postJson('/api/v1/listings', $this->draftPayload($category))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.price.amount', 18500)
            ->assertJsonPath('data.shipping.0.id', 'shp_std')
            ->assertJsonPath('data.allowed_actions.0', 'finish')
            ->json('data');

        $this->postJson("/api/v1/listings/{$draft['id']}/publish")
            ->assertStatus(422)
            ->assertJsonPath('errors.media_ids.0', 'Add at least one photo.');

        $media = $this->post('/api/v1/media', ['file' => UploadedFile::fake()->image('bose.jpg', 1200, 800), 'type' => 'listing'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width', 1200)
            ->assertJsonStructure(['data' => ['id', 'public_id', 'variants' => ['thumbnail', 'original']]])
            ->json('data');

        $this->patchJson("/api/v1/listings/{$draft['id']}", ['media_ids' => [$media['id']]])->assertOk()
            ->assertJsonPath('data.images.0.id', $media['id']);

        $this->postJson("/api/v1/listings/{$draft['id']}/publish")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->id, 'type' => 'listing.published']);
        $this->getJson('/api/v1/me/listings?status=live')->assertOk()->assertJsonPath('meta.counts.live', 1);
    }

    public function test_unverified_seller_goes_to_review_and_is_capped_at_three_live_listings(): void
    {
        $category = Category::factory()->create();
        $seller = $this->actingAsUser(User::factory()->create());
        Listing::factory()->active()->count(3)->create(['seller_id' => $seller->id]);

        $draft = Listing::factory()->draft()->create(['seller_id' => $seller->id, 'category_id' => $category->id]);
        $draft->images()->save(Media::factory()->make(['owner_id' => $seller->id, 'collection' => 'images']));

        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$draft->id}/publish"), 403, 'KYC_REQUIRED');

        Listing::query()->where('seller_id', $seller->id)->where('status', 'active')->limit(1)->update(['status' => 'archived']);
        $this->postJson("/api/v1/listings/{$draft->id}/publish")->assertOk()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_illegal_state_transition_is_409(): void
    {
        $seller = $this->actingAsUser(User::factory()->kycApproved()->create());
        $listing = Listing::factory()->draft()->create(['seller_id' => $seller->id]);

        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$listing->id}/pause"), 409, 'INVALID_STATE_TRANSITION');

        $active = Listing::factory()->active()->create(['seller_id' => $seller->id]);
        $this->postJson("/api/v1/listings/{$active->id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/listings/{$active->id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/listings/{$active->id}/mark-sold")->assertOk()->assertJsonPath('data.status', 'sold');
        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$active->id}/pause"), 409, 'INVALID_STATE_TRANSITION');
        $this->postJson("/api/v1/listings/{$active->id}/relist")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_sellers_cannot_touch_other_sellers_listings(): void
    {
        $owner = User::factory()->kycApproved()->create();
        $listing = Listing::factory()->active()->create(['seller_id' => $owner->id]);
        $this->actingAsUser(User::factory()->kycApproved()->create());

        $this->assertErrorEnvelope($this->patchJson("/api/v1/listings/{$listing->id}", ['title' => 'Hijacked title']), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$listing->id}/pause"), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->deleteJson("/api/v1/listings/{$listing->id}"), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->getJson("/api/v1/me/listings/{$listing->id}"), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$listing->id}/reserve", ['buyer_id' => $owner->id]), 403, 'FORBIDDEN');
        $this->assertSame($listing->title, $listing->fresh()->title);
    }

    public function test_staff_without_member_permissions_cannot_create_listings(): void
    {
        $this->actingAsUser(User::factory()->staff('trust_safety')->create());
        $this->assertErrorEnvelope($this->postJson('/api/v1/listings', ['title' => 'x']), 403, 'FORBIDDEN');
    }

    public function test_fee_quote_uses_commission_rules(): void
    {
        $this->postJson('/api/v1/listings/fee-quote', ['price' => ['amount' => 18500, 'currency' => 'USD']])
            ->assertOk()->assertJsonPath('data.platform_fee_rate', 8)->assertJsonPath('data.platform_fee.amount', 1480)
            ->assertJsonPath('data.seller_receives.amount', 17020);

        CommissionRule::query()->create(['name' => 'Standard', 'kind' => 'sale_commission', 'scope' => 'all', 'rate_bps' => 750, 'min_amount' => 100, 'currency' => 'USD', 'status' => 'live']);
        CommissionService::flush();

        $this->postJson('/api/v1/listings/fee-quote', ['price' => ['amount' => 1000, 'currency' => 'USD']])
            ->assertOk()->assertJsonPath('data.platform_fee_rate', 7.5)->assertJsonPath('data.platform_fee.amount', 100);
    }

    public function test_upload_rejects_non_images(): void
    {
        $this->actingAsUser();
        $this->post('/api/v1/media', ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), 'type' => 'listing'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['file']]);
        $this->post('/api/v1/media', ['file' => UploadedFile::fake()->image('tiny.jpg', 100, 100), 'type' => 'listing'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_draft_delete_and_listing_with_orders_protected(): void
    {
        $seller = $this->actingAsUser(User::factory()->kycApproved()->create());
        $draft = Listing::factory()->draft()->create(['seller_id' => $seller->id]);
        $this->deleteJson("/api/v1/listings/{$draft->id}")->assertNoContent();
        $this->assertSoftDeleted('listings', ['id' => $draft->id]);

        $active = Listing::factory()->active()->create(['seller_id' => $seller->id]);
        $this->assertErrorEnvelope($this->deleteJson("/api/v1/listings/{$active->id}"), 409, 'INVALID_STATE_TRANSITION');
        $this->assertSame(ListingStatus::Active, $active->fresh()->status);
    }
}
