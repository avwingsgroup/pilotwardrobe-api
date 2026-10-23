<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\MediaType;
use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Storefront imagery on a fresh install: seeded once, never overwritten, never pruned. */
final class StorefrontImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_command_attaches_images_once_and_keeps_existing_ones(): void
    {
        $custom = Media::factory()->create(['type' => MediaType::Content]);
        Category::query()->where('slug', 'eyewear')->update(['image_media_id' => $custom->id]);

        $this->artisan('content:seed-images', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Media::query()->where('type', MediaType::Content->value)->whereKeyNot($custom->id)->count(), 'dry run uploads nothing');

        $this->artisan('content:seed-images')->assertSuccessful();

        $headsets = Category::query()->where('slug', 'headsets')->firstOrFail();
        $this->assertNotNull($headsets->image_media_id);
        $this->assertSame($custom->id, Category::query()->where('slug', 'eyewear')->value('image_media_id'), 'an image set in the Control Tower is kept');
        $this->assertNotNull((HeroSlide::query()->where('position', 1)->firstOrFail()->published ?? [])['image_media_id'] ?? null);

        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('data.'.$this->categoryIndex('headsets').'.image.width', fn ($w) => $w > 0);
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.hero_slides.0.image.width', fn ($w) => $w > 0);

        $count = Media::query()->count();
        $this->artisan('content:seed-images')->assertSuccessful();
        $this->assertSame($count, Media::query()->count(), 're-running uploads nothing new');
    }

    public function test_storefront_images_survive_the_nightly_prune(): void
    {
        $this->artisan('content:seed-images')->assertSuccessful();
        $categoryImage = Category::query()->where('slug', 'headsets')->value('image_media_id');
        $slideImage = HeroSlide::query()->where('position', 1)->firstOrFail()->published['image_media_id'];

        $this->travel(3)->days();
        $this->artisan('pilotwardrobe:prune')->assertSuccessful();

        $this->assertNotNull(Media::query()->find($categoryImage));
        $this->assertNotNull(Media::query()->find($slideImage));
    }

    public function test_hero_slide_image_set_in_control_tower_is_claimed_and_survives_prune(): void
    {
        $upload = Media::factory()->create(['type' => MediaType::Content]);
        $slide = HeroSlide::query()->where('position', 2)->firstOrFail();

        $this->actingAsUser(User::factory()->staff('marketing')->create());
        $this->patchJson("/api/v1/admin/content/hero-slides/{$slide->id}", ['image_media_id' => $upload->id])->assertOk();

        $this->assertSame($slide->id, $upload->fresh()->attachable_id);
        $this->travel(3)->days();
        $this->artisan('pilotwardrobe:prune')->assertSuccessful();
        $this->assertNotNull(Media::query()->find($upload->id));
    }

    private function categoryIndex(string $slug): int
    {
        $slugs = array_column($this->getJson('/api/v1/categories')->json('data'), 'slug');

        return (int) array_search($slug, $slugs, true);
    }
}
