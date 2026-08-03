<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Product;
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
        $this->assertSame(1, Product::where('code', 'HN-PV-BASE-001')->count());
        $this->assertSame(1, Product::where('code', 'HN-COMP-BASE-001')->count());

        $this->assertDatabaseHas('role', ['role_code' => 'SUPER_ADMIN', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'stock_document.post', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'stock_document.scan', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'scan.classify', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'report.view', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('permission', ['permission_key' => 'audit_log.view', 'status' => 'ACTIVE']);
    }

    public function test_seeded_permission_catalog_covers_be1_routes_and_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'stock_document.post' => ['WAREHOUSE_KEEPER', 'WAREHOUSE_MANAGER'],
            'stock_document.scan' => ['WAREHOUSE_KEEPER', 'WAREHOUSE_MANAGER'],
            'scan.classify' => ['WAREHOUSE_MANAGER'],
            'report.view' => ['WAREHOUSE_STAFF', 'WAREHOUSE_APPROVER', 'WAREHOUSE_KEEPER', 'VIEWER', 'WAREHOUSE_MANAGER'],
            'stocktake.adjust' => ['WAREHOUSE_MANAGER'],
            'warranty.temp_only' => ['WAREHOUSE_MANAGER'],
        ];

        foreach ($expected as $permissionKey => $roleCodes) {
            $this->assertDatabaseHas('permission', ['permission_key' => $permissionKey, 'status' => 'ACTIVE']);

            foreach ($roleCodes as $roleCode) {
                $exists = DB::table('role_permission as rp')
                    ->join('role as r', 'r.role_id', '=', 'rp.role_id')
                    ->join('permission as p', 'p.permission_id', '=', 'rp.permission_id')
                    ->where('r.role_code', $roleCode)
                    ->where('p.permission_key', $permissionKey)
                    ->exists();

                $this->assertTrue($exists, "{$roleCode} must include {$permissionKey}");
            }
        }
    }

    public function test_permission_evidence_export_matches_scan_route_seed_and_role_boundary(): void
    {
        $this->seed(DatabaseSeeder::class);
        $catalogPath = storage_path('framework/testing/wwhn150-permission-catalog.json');
        $seedDiffPath = storage_path('framework/testing/wwhn150-permission-seed-diff.json');
        File::delete([$catalogPath, $seedDiffPath]);

        try {
            $exitCode = Artisan::call('evidence:permission-catalog', [
                '--output' => $catalogPath,
                '--seed-diff-output' => $seedDiffPath,
            ]);

            $this->assertSame(0, $exitCode, Artisan::output());
            $this->assertFileExists($catalogPath);
            $this->assertFileExists($seedDiffPath);

            $catalog = json_decode(File::get($catalogPath), true, 512, JSON_THROW_ON_ERROR);
            $seedDiff = json_decode(File::get($seedDiffPath), true, 512, JSON_THROW_ON_ERROR);
            $scanMatrix = collect($catalog['endpoint_permission_role_matrix'])->first(
                fn (array $row): bool => $row['uri'] === 'api/v1/scan/classify'
                    && in_array('POST', $row['methods'], true)
            );

            $this->assertSame('PASS', $catalog['integrity']['status']);
            $this->assertSame([], $catalog['integrity']['missing_route_permissions']);
            $this->assertSame('scan.classify', $scanMatrix['permission']);
            $this->assertTrue($scanMatrix['backend_enforced']);
            $this->assertEqualsCanonicalizing(
                ['SUPER_ADMIN', 'WAREHOUSE_MANAGER'],
                $scanMatrix['roles']
            );
            $this->assertSame('PASS', $seedDiff['status']);
            $this->assertSame('ACTIVE', $seedDiff['actual']['permission_status']);
            $this->assertSame([], $seedDiff['missing_roles']);
            $this->assertSame([], $seedDiff['unexpected_roles']);
        } finally {
            File::delete([$catalogPath, $seedDiffPath]);
        }
    }

    public function test_health_endpoint_reports_app_database_migration_and_seed_status_without_auth(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(DB::table('app_user')->where('username', 'admin')->exists());
        $this->assertTrue(DB::table('warehouses')->where('code', 'KHO-TONG-HN')->exists());
        $this->assertTrue(DB::table('products')->where('code', 'HN-PV-BASE-001')->exists());

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
        $this->assertDatabaseHas('products', ['code' => 'HN-PV-BASE-001', 'published_to_app' => true]);

        $this->assertSame(1, User::where('username', 'admin')->count());
        $this->assertSame(1, Warehouse::where('code', 'KHO-TONG-HN')->count());
        $this->assertSame(1, Product::where('code', 'HN-PV-BASE-001')->count());
    }
}
