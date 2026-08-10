<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class BackendMigrationSeedHealthTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migrations_and_seed_are_idempotent_for_backend_baseline(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('username', 'admin')->count());
        $this->assertSame(1, Warehouse::where('code', 'KHO-TONG-HN')->count());
        $this->assertSame(1, Sku::where('sku_code', 'HN-PV-BASE-001')->count());
        $this->assertSame(1, Sku::where('sku_code', 'HN-COMP-BASE-001')->count());

        $this->assertDatabaseHas('role', ['role_code' => 'SUPER_ADMIN', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'stock_document.post', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'audit_log.view', 'status' => 'ACTIVE']);
    }

    public function test_health_endpoint_reports_app_database_migration_and_seed_status_without_auth(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(DB::table('app_user')->where('username', 'admin')->exists());
        $this->assertTrue(DB::table('warehouses')->where('code', 'KHO-TONG-HN')->exists());
        $this->assertTrue(DB::table('sku')->where('sku_code', 'HN-PV-BASE-001')->exists());

        $this->assertTrue(Route::has('api.health'));
    }

    public function test_health_endpoint_response_shape_is_public_and_secret_safe(): void
    {
        request()->attributes->set('request_id', 'HN-DEVOPS-001-HEALTH-SHAPE');

        $response = app(HealthController::class)->show();
        $payload = $response->getData(true);

        $this->assertSame(503, $response->getStatusCode(), $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertSame([
            'success',
            'message',
            'error_code',
            'errors',
            'error',
            'meta',
        ], array_keys($payload));
        $this->assertSame(['status', 'checks'], array_keys($payload['errors']));
        $this->assertSame(['app', 'database', 'migrations', 'seed'], array_keys($payload['errors']['checks']));
        $this->assertSame(['request_id', 'timestamp'], array_keys($payload['meta']));
    }

    public function test_health_endpoint_http_route_is_public_and_uses_standard_degraded_error_when_seed_is_missing(): void
    {
        $this->getJson('/api/health', ['X-Request-ID' => 'HN-DEVOPS-001-HEALTH'])
            ->assertStatus(503)
            ->assertHeader('X-Request-ID', 'HN-DEVOPS-001-HEALTH')
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'HEALTH_CHECK_DEGRADED')
            ->assertJsonMissingPath('errors.checks.database.host')
            ->assertJsonMissingPath('errors.checks.database.password')
            ->assertJsonStructure([
                'success',
                'message',
                'error_code',
                'errors' => [
                    'status',
                    'checks' => [
                        'app' => ['status', 'environment', 'timezone'],
                        'database' => ['status', 'connection'],
                        'migrations' => ['status', 'ran_count'],
                        'seed' => ['status', 'markers'],
                    ],
                ],
                'error' => ['code', 'details'],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_migrate_fresh_seed_command_produces_deployable_baseline(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('app_user', ['username' => 'admin', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('warehouses', ['code' => 'KHO-TONG-HN', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('sku', ['sku_code' => 'HN-PV-BASE-001', 'published_to_app' => true]);

        $this->assertSame(1, User::where('username', 'admin')->count());
        $this->assertSame(1, Warehouse::where('code', 'KHO-TONG-HN')->count());
        $this->assertSame(1, Sku::where('sku_code', 'HN-PV-BASE-001')->count());
    }
}
