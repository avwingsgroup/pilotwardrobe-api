<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Listing;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PlatformBehaviourTest extends TestCase
{
    public function test_maintenance_window_serves_503_to_members_but_not_staff_or_health(): void
    {
        $super = User::factory()->superAdmin()->create();
        MaintenanceWindow::query()->create(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(), 'message' => 'Upgrading', 'created_by' => $super->id]);

        $this->assertErrorEnvelope($this->getJson('/api/v1/listings'), 503, 'MAINTENANCE');
        $this->getJson('/api/v1/listings')->assertHeader('Retry-After')->assertJsonPath('message', 'Upgrading');
        $this->getJson('/health')->assertOk();

        $this->actingAsUser($super);
        $this->getJson('/api/v1/listings')->assertOk();
    }

    public function test_health_endpoint_reports_dependencies_without_secrets(): void
    {
        $response = $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame(['database', 'cache', 'queue'], array_keys($response->json('checks')));
        $this->assertStringNotContainsString(config('database.connections.pgsql.password') ?: '__none__', $response->getContent());
    }

    public function test_health_cache_check_works_with_redis_style_string_values(): void
    {
        // Redis returns stored numbers as strings ("1"); the array store used in tests does not.
        Cache::extend('redis-like', fn () => Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                $value = parent::get($key);

                return is_int($value) || is_float($value) ? (string) $value : $value;
            }
        }));
        config(['cache.stores.redis-like' => ['driver' => 'redis-like']]);
        Cache::setDefaultDriver('redis-like');

        $this->getJson('/health')->assertOk()->assertJsonPath('checks.cache.status', 'up');
    }

    public function test_home_route_is_the_health_check_without_a_session(): void
    {
        $response = $this->getJson('/')->assertOk()->assertJsonPath('status', 'ok')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['database', 'cache', 'queue'], array_keys($response->json('checks')));
        $response->assertCookieMissing(config('session.cookie'));
    }

    public function test_forwarded_client_ip_is_trusted_only_from_configured_proxies(): void
    {
        Route::get('/__client-ip', fn () => response()->json(['ip' => request()->ip()]));
        $spoof = ['HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        // A client cannot fake its IP (rate limits, audit trail) by sending the header itself.
        $this->call('GET', '/__client-ip', server: $spoof + ['REMOTE_ADDR' => '198.51.100.7'])->assertJsonPath('ip', '198.51.100.7');
        // Nginx on the same host is trusted by default and passes the real client IP.
        $this->call('GET', '/__client-ip', server: $spoof + ['REMOTE_ADDR' => '127.0.0.1'])->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_config_contains_only_cacheable_values(): void
    {
        // `php artisan config:cache` var_exports the whole config; objects without __set_state break deploys.
        $exported = var_export(config()->all(), true);
        $this->assertSame(config()->all(), eval("return {$exported};"));
    }

    public function test_production_errors_never_leak_internals(): void
    {
        config(['app.debug' => false]);
        $this->actingAsUser();
        Route::get('/api/v1/__boom', fn () => throw new \RuntimeException('SQLSTATE secret path C:\\app'))->middleware('api');

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('C:\\', $response->getContent());
        $this->assertNotNull($response->json('meta.request_id'));
    }

    public function test_rate_limit_returns_contract_envelope(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/email-lookup', ['email' => "x{$i}@example.com"]);
        }

        $this->postJson('/api/v1/auth/email-lookup', ['email' => 'y@example.com'])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_marketplace_sweeps_run_cleanly(): void
    {
        Listing::factory()->active()->create();
        $this->assertSame(0, Artisan::call('marketplace:sweep'));
        $this->assertSame(0, Artisan::call('status:record'));
        $this->getJson('/api/v1/help/summary')->assertOk()->assertJsonPath('data.status.overall', 'operational');
    }

    public function test_security_headers_present(): void
    {
        $this->getJson('/api/v1/categories')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Request-Id');
    }

    public function test_cors_allows_the_spa_origin_with_credentials_only(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ]);

        $preflight(config('platform.frontend_url'))
            ->assertHeader('Access-Control-Allow-Origin', config('platform.frontend_url'))
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        // Other origins are never echoed back, so the browser refuses the cross-origin call.
        $this->assertNotSame('https://evil.example', $preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_api_docs_are_staff_only_outside_local_unless_made_public(): void
    {
        $this->get('/openapi')->assertForbidden();
        $this->get('/api/documentation')->assertForbidden();

        $this->assertTrue(Gate::forUser(User::factory()->staff('support_agent')->create())->allows('viewApiDocs'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewApiDocs'));

        config(['platform.api_docs_public' => true]);
        $this->assertTrue(Gate::forUser(null)->allows('viewApiDocs'));
    }
}
