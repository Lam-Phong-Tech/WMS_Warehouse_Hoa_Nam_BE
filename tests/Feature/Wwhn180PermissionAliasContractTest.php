<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class Wwhn180PermissionAliasContractTest extends TestCase
{
    use RefreshDatabase;

    private const ALIAS_KEYS = [
        'inbound.post',
        'outbound.post',
        'scan.inbound',
        'scan.outbound',
        'scan.warranty',
        'inventory.trace',
        'warranty.manage',
        'report.export',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_alias_catalog_is_active_and_only_assigned_to_compatible_roles(): void
    {
        $this->assertSame(
            count(self::ALIAS_KEYS),
            Permission::query()
                ->whereIn('permission_key', self::ALIAS_KEYS)
                ->where('status', 'ACTIVE')
                ->count()
        );

        $keeper = Role::query()->where('role_code', 'WAREHOUSE_KEEPER')->firstOrFail();
        $manager = Role::query()->where('role_code', 'WAREHOUSE_MANAGER')->firstOrFail();
        $noPermission = Role::query()->where('role_code', 'NO_PERMISSION')->firstOrFail();

        $keeperKeys = $keeper->permissions()->pluck('permission_key')->all();
        $managerKeys = $manager->permissions()->pluck('permission_key')->all();
        $noPermissionKeys = $noPermission->permissions()->pluck('permission_key')->all();

        foreach (['inbound.post', 'outbound.post', 'scan.inbound', 'scan.outbound', 'inventory.trace'] as $key) {
            $this->assertContains($key, $keeperKeys);
            $this->assertContains($key, $managerKeys);
        }

        foreach (['scan.warranty', 'warranty.manage', 'report.export'] as $key) {
            $this->assertContains($key, $managerKeys);
            $this->assertNotContains($key, $keeperKeys);
        }

        $this->assertFalse(Permission::query()->where('permission_key', 'stocktake.count')->exists());

        foreach (self::ALIAS_KEYS as $key) {
            $this->assertNotContains($key, $noPermissionKeys);
        }
    }

    public function test_api_middleware_remains_canonical_and_is_not_replaced_by_fe_aliases(): void
    {
        $id = (string) Str::uuid();

        $this->assertCanonicalMiddleware(
            Request::create("/api/v1/inbound-documents/{$id}/post-receipt", 'POST'),
            'permission:stock_document.post',
            'permission:inbound.post'
        );
        $this->assertCanonicalMiddleware(
            Request::create("/api/v1/outbound-documents/{$id}/post-issue", 'POST'),
            'permission:stock_document.post',
            'permission:outbound.post'
        );
        $this->assertCanonicalMiddleware(
            Request::create("/api/v1/inbound-documents/{$id}/scan", 'POST'),
            'permission:stock_document.scan',
            'permission:scan.inbound'
        );
        $this->assertCanonicalMiddleware(
            Request::create("/api/v1/outbound-documents/{$id}/scan", 'POST'),
            'permission:stock_document.scan',
            'permission:scan.outbound'
        );
        $this->expectException(NotFoundHttpException::class);
        Route::getRoutes()->match(Request::create("/api/v1/stocktake/sessions/{$id}/apply-adjustment", 'POST'));
    }

    public function test_remaining_export_alias_does_not_replace_canonical_middleware(): void
    {
        $this->assertCanonicalMiddleware(
            Request::create('/api/v1/reports/inventory-xnt/export-excel', 'GET'),
            'permission:report.view',
            'permission:report.export'
        );
    }

    private function assertCanonicalMiddleware(
        Request $request,
        string $canonicalMiddleware,
        string $feAliasMiddleware
    ): void {
        $route = Route::getRoutes()->match($request);
        $middleware = $route->gatherMiddleware();

        $this->assertContains($canonicalMiddleware, $middleware);
        $this->assertNotContains($feAliasMiddleware, $middleware);
    }
}
