<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class BackendMigrationSeedHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_and_seed_are_idempotent_and_create_required_markers(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, User::where('username', 'admin')->count());
        $this->assertSame(1, Warehouse::where('code', 'KHO-TONG-HN')->count());
        $this->assertSame(1, Product::where('code', 'HN-PV-BASE-001')->count());
        $this->assertDatabaseHas('app_user', ['username' => 'admin', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('warehouses', ['code' => 'KHO-TONG-HN', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('products', ['code' => 'HN-PV-BASE-001', 'published_to_app' => true]);
    }

    public function test_versioned_health_endpoint_returns_200_with_canonical_contract_after_seed(): void
    {
        config(['health.version' => '1.0.0-test']);
        $this->seed(DatabaseSeeder::class);
        $this->getJson('/api/v1/health', ['X-Request-ID' => 'WWHN-155-HEALTHY'])
            ->assertOk()->assertHeader('X-Request-ID', 'WWHN-155-HEALTHY')
            ->assertJsonPath('success', true)->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', '1.0.0-test')->assertJsonPath('data.environment', 'testing')
            ->assertJsonPath('data.request_id', 'WWHN-155-HEALTHY')
            ->assertJsonPath('data.checks.database.status', 'ok')
            ->assertJsonPath('data.checks.migrations.status', 'ok')
            ->assertJsonPath('data.checks.seed.status', 'ok')
            ->assertJsonStructure(['success', 'message', 'data' => ['status', 'version', 'environment', 'server_time', 'request_id', 'checks'], 'meta' => ['request_id', 'timestamp']]);
    }

    public function test_versioned_health_endpoint_returns_secret_safe_503_when_seed_is_missing(): void
    {
        config(['health.version' => '1.0.0-test']);
        $response = $this->getJson('/api/v1/health', ['X-Request-ID' => 'WWHN-155-DEGRADED'])
            ->assertStatus(503)->assertHeader('X-Request-ID', 'WWHN-155-DEGRADED')
            ->assertJsonPath('success', false)->assertJsonPath('error_code', 'HEALTH_CHECK_DEGRADED')
            ->assertJsonPath('errors.status', 'degraded')->assertJsonPath('errors.version', '1.0.0-test')
            ->assertJsonPath('errors.request_id', 'WWHN-155-DEGRADED')->assertJsonPath('errors.checks.seed.status', 'fail');
        $body = strtolower($response->getContent());
        foreach (['password', 'exception', 'stack', 'hostname'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_legacy_unversioned_health_endpoint_is_not_exposed(): void
    {
        $this->getJson('/api/health')->assertNotFound();
    }

    public function test_seed_markers_are_directly_queryable_for_deployment_evidence(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(DB::table('app_user')->where('username', 'admin')->exists());
        $this->assertTrue(DB::table('warehouses')->where('code', 'KHO-TONG-HN')->exists());
        $this->assertTrue(DB::table('products')->where('code', 'HN-PV-BASE-001')->exists());
    }
}
