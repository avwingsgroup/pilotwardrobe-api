<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real provider.
        Http::preventStrayRequests();
    }

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** Asserts the contract's error envelope (§1.5). */
    protected function assertErrorEnvelope(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', $code)
            ->assertJsonStructure(['success', 'message', 'code']);
    }
}
