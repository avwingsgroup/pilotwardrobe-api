<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Listing;
use App\Models\Media;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Temporary demo data on a production server: opt-in, nothing sent out, photos removable. */
final class DemoProductionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(ReferenceDataSeeder::class);
        $this->app['env'] = 'production';
    }

    public function test_demo_seeder_still_refuses_production_on_its_own(): void
    {
        $this->expectException(RuntimeException::class);
        $this->app->make(DemoSeeder::class)->run();
    }

    public function test_seed_needs_confirmation_then_creates_members_only_and_sends_nothing(): void
    {
        $this->artisan('demo:production', ['action' => 'seed'])
            ->expectsConfirmation('Have you taken a database backup you will restore after testing?', 'no')
            ->assertFailed();
        $this->assertSame(0, User::query()->whereIn('email', DemoSeeder::EMAILS)->count());

        // Production-like channels: any real delivery attempt would be an outbound HTTP call,
        // which Http::preventStrayRequests() turns into a failure.
        config(['services.sms.providers' => ['termii'], 'services.whatsapp.driver' => 'cloud', 'services.fcm.driver' => 'fcm']);

        $this->artisan('demo:production', ['action' => 'seed', '--force' => true])->assertSuccessful();

        $this->assertSame(6, User::query()->whereIn('email', DemoSeeder::EMAILS)->count(), 'six member accounts');
        $this->assertSame(0, User::query()->whereIn('email', ['admin@example.com', 'finance@example.com', 'super@example.com'])->count(), 'no staff demo logins in production');
        $this->assertGreaterThanOrEqual(20, Listing::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertFalse(DemoSeeder::$allowInProduction, 'the opt-in is switched off again');
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.sections.0.listings.0.images.0.width', fn ($w) => $w > 0);
    }

    public function test_cleanup_removes_demo_uploads_from_storage(): void
    {
        $this->artisan('demo:production', ['action' => 'seed', '--force' => true])->assertSuccessful();
        $owners = User::query()->whereIn('email', DemoSeeder::EMAILS)->pluck('id');
        $uploads = Media::query()->whereIn('owner_id', $owners)->get();
        $this->assertNotEmpty($uploads);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('media'));

        $this->artisan('demo:production', ['action' => 'cleanup', '--force' => true])->assertSuccessful();

        foreach ($uploads as $media) {
            $this->assertFalse(Storage::disk('public')->exists("media/{$media->public_id}.{$media->format}"), "{$media->public_id} still stored");
        }
    }
}
