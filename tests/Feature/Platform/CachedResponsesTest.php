<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cached endpoints must return the same payload from the cache as when freshly built.
 * Laravel 13 refuses to unserialize objects from the cache (serializable_classes=false), so a
 * Collection left in a cached payload turns into {"__PHP_Incomplete_Class_Name": …} on every
 * cache hit (this hid the home page hero in production). phpunit.xml serializes the array
 * cache, so the second request here reads back exactly what production would.
 */
final class CachedResponsesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(ReferenceDataSeeder::class);
        $this->artisan('content:seed-images')->assertSuccessful();

        $seller = User::factory()->kycApproved()->create();
        $category = Category::query()->where('slug', 'prints')->firstOrFail();
        Listing::factory()->active()->count(3)->create(['seller_id' => $seller->id]);
        Listing::factory()->active()->create(['seller_id' => $seller->id, 'category_id' => $category->id, 'format' => 'print']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function cachedEndpoints(): array
    {
        return [
            'home' => ['/api/v1/home', false],
            'categories' => ['/api/v1/categories', false],
            'help summary' => ['/api/v1/help/summary', false],
            'support contact info' => ['/api/v1/support/contact-info', false],
            'listing search facets' => ['/api/v1/listings?search=a', false],
            'admin overview' => ['/api/v1/admin/overview', true],
            'admin analytics' => ['/api/v1/admin/analytics?period=30d', true],
        ];
    }

    #[DataProvider('cachedEndpoints')]
    public function test_cache_hit_matches_fresh_response(string $uri, bool $asStaff): void
    {
        if ($asStaff) {
            $this->actingAsUser(User::factory()->superAdmin()->create());
        }

        $fresh = $this->getJson($uri)->assertOk()->json();
        $cached = $this->getJson($uri)->assertOk()->json();

        $this->assertStringNotContainsString('__PHP_Incomplete_Class', json_encode($cached));
        $this->assertSame($fresh, $cached);
    }
}
