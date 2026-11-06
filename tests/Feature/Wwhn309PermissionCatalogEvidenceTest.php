<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Tests\TestCase;

/**
 * WWHN-309 — permission catalog là handover artifact FE/BA/QA tiêu thụ trực tiếp.
 *
 * Defect gốc: exporter so khớp cả biểu thức `permission:A|B` như một permission key duy
 * nhất, trong khi runtime `CheckPermission` tách theo `|` và chấp nhận OR. Hệ quả: mọi
 * endpoint dùng alias bị trả sai danh sách role và bị tính là route permission "lạ".
 * Test này chốt semantics để không tái diễn.
 */
class Wwhn309PermissionCatalogEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private string $catalogPath;

    private string $seedDiffPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        $this->catalogPath = storage_path('framework/testing/wwhn309-permission-catalog.json');
        $this->seedDiffPath = storage_path('framework/testing/wwhn309-permission-seed-diff.json');
    }

    protected function tearDown(): void
    {
        foreach ([$this->catalogPath, $this->seedDiffPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function exportCatalog(): array
    {
        $exitCode = Artisan::call('evidence:permission-catalog', [
            '--output' => $this->catalogPath,
            '--seed-diff-output' => $this->seedDiffPath,
        ]);

        $this->assertSame(0, $exitCode, 'Exporter phải PASS trên seed baseline: '.Artisan::output());

        return json_decode((string) file_get_contents($this->catalogPath), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_or_alias_permission_expression_is_split_into_accepted_keys(): void
    {
        $catalog = $this->exportCatalog();

        $aliasRoutes = array_values(array_filter(
            $catalog['route_permissions'],
            fn (array $route): bool => is_string($route['permission']) && str_contains($route['permission'], '|')
        ));

        $this->assertNotEmpty($aliasRoutes, 'Baseline phải còn route dùng OR-alias để test có ý nghĩa.');

        foreach ($aliasRoutes as $route) {
            $expectedGroups = [];
            foreach ($route['middleware'] as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $expectedGroups[] = array_values(array_filter(array_map('trim', explode('|', substr($middleware, 11)))));
                }
            }
            $expected = array_values(array_unique(array_merge(...$expectedGroups)));
            $this->assertSame($expectedGroups, $route['permission_groups']);
            $this->assertSame($expected, $route['accepted_permissions'], $route['uri']);
            $this->assertGreaterThan(1, count($route['accepted_permissions']), $route['uri']);
        }
    }

    public function test_alias_routes_resolve_roles_through_any_accepted_key(): void
    {
        $catalog = $this->exportCatalog();

        $rolePermissions = [];
        foreach ($catalog['roles'] as $role) {
            $rolePermissions[$role['role_code']] = [
                'status' => $role['status'],
                'permissions' => $role['permissions'],
            ];
        }

        $aliasRows = array_values(array_filter(
            $catalog['endpoint_permission_role_matrix'],
            fn (array $row): bool => is_string($row['permission']) && str_contains($row['permission'], '|')
        ));

        $this->assertNotEmpty($aliasRows);

        foreach ($aliasRows as $row) {
            $expectedRoles = [];
            foreach ($rolePermissions as $code => $definition) {
                if ($definition['status'] !== 'ACTIVE') {
                    continue;
                }
                $allGroups = true;
                foreach ($row['permission_groups'] as $group) {
                    $allGroups = $allGroups && array_intersect($group, $definition['permissions']) !== [];
                }
                if ($code === 'SUPER_ADMIN' || $allGroups) {
                    $expectedRoles[] = $code;
                }
            }

            $this->assertSame($expectedRoles, $row['roles'], $row['uri']);
            $this->assertNotSame(['SUPER_ADMIN'], $row['roles'], "Route alias {$row['uri']} chỉ còn SUPER_ADMIN — dấu hiệu matrix so khớp cả chuỗi biểu thức.");
        }
    }

    public function test_all_permission_middleware_groups_are_required_and_aliases_remain_or(): void
    {
        Route::get('api/v1/wwhn309-composed-probe', fn () => [])
            ->middleware(['permission:inbound.view|outbound.view', 'permission:inbound.scan|outbound.scan']);
        $catalog = $this->exportCatalog();
        $targets = [
            'api/v1/inbound-documents/{id}/checks',
            'api/v1/outbound-documents/{id}/checks',
            'api/v1/inbound-documents/{id}/checks/requests/{key}',
            'api/v1/outbound-documents/{id}/checks/requests/{key}',
            'api/v1/wwhn309-composed-probe',
        ];
        $seen = 0;
        foreach ($catalog['endpoint_permission_role_matrix'] as $row) {
            if (! in_array($row['uri'], $targets, true)) {
                continue;
            }
            $seen++;
            $this->assertSame('ALL_GROUPS_ANY_KEY', $row['permission_match'] ?? null);
            $groups = $row['permission_groups'] ?? [];
            $this->assertCount(2, $groups);
            $expected = [];
            foreach ($catalog['roles'] as $role) {
                if ($role['status'] !== 'ACTIVE') {
                    continue;
                }
                $all = true;
                foreach ($groups as $group) {
                    $all = $all && array_intersect($group, $role['permissions']) !== [];
                }
                if ($role['role_code'] === 'SUPER_ADMIN' || $all) {
                    $expected[] = $role['role_code'];
                }
            }
            $this->assertSame($expected, $row['roles'], $row['uri']);
            foreach (['LEADERSHIP', 'VIEWER', 'WAREHOUSE_APPROVER', 'WAREHOUSE_MANAGER'] as $viewOnly) {
                $this->assertNotContains($viewOnly, $row['roles']);
            }
        }
        $this->assertSame(5, $seen);
    }

    public function test_empty_permission_group_denies_every_role_including_super_admin(): void
    {
        Route::get('api/v1/wwhn309-empty-probe', fn () => [])
            ->middleware(['permission:inbound.view', 'permission:']);
        $catalog = $this->exportCatalog();
        $row = collect($catalog['endpoint_permission_role_matrix'])->firstWhere('uri', 'api/v1/wwhn309-empty-probe');
        $this->assertNotNull($row);
        $this->assertSame([['inbound.view'], []], $row['permission_groups']);
        $this->assertSame([], $row['roles']);
    }

    public function test_route_permission_integrity_uses_split_keys(): void
    {
        $catalog = $this->exportCatalog();

        $this->assertSame([], $catalog['integrity']['missing_route_permissions']);
        $this->assertSame('PASS', $catalog['integrity']['status']);

        $activeKeys = [];
        foreach ($catalog['permissions'] as $permission) {
            if ($permission['status'] === 'ACTIVE') {
                $activeKeys[] = $permission['permission_key'];
            }
        }

        foreach ($catalog['route_permissions'] as $route) {
            foreach ($route['accepted_permissions'] as $key) {
                $this->assertContains($key, $activeKeys, "Route {$route['uri']} tham chiếu permission không ACTIVE: {$key}");
                $this->assertStringNotContainsString('|', $key);
            }
        }
    }

    public function test_scan_classify_seed_diff_matches_runtime_authorization(): void
    {
        $this->exportCatalog();
        $seedDiff = json_decode((string) file_get_contents($this->seedDiffPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('PASS', $seedDiff['status']);
        $this->assertSame([], $seedDiff['missing_roles']);
        $this->assertSame([], $seedDiff['unexpected_roles']);
        $this->assertContains('scan.classify', $seedDiff['actual']['route_accepted_permissions']);
        $this->assertContains('scan.execute', $seedDiff['actual']['route_accepted_permissions']);
        $this->assertTrue($seedDiff['actual']['backend_enforced']);
    }

    public function test_catalog_covers_every_guarded_api_v1_route(): void
    {
        $catalog = $this->exportCatalog();

        $runtimeRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $runtimeRoutes[] = $route->uri();
        }

        $catalogRoutes = array_column($catalog['route_permissions'], 'uri');

        foreach (array_unique($runtimeRoutes) as $uri) {
            $this->assertContains($uri, $catalogRoutes, "Catalog thiếu route runtime: {$uri}");
        }
    }

    public function test_published_catalog_document_is_not_stale(): void
    {
        $published = json_decode(
            (string) file_get_contents(base_path('docs/permissions/WMS_PERMISSION_CATALOG_BE1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($published['source_hashes'] as $key => $source) {
            $absolute = base_path($source['path']);
            $this->assertFileExists($absolute, $key);
            $this->assertSame(
                hash_file('sha256', $absolute),
                $source['sha256'],
                "Permission catalog đã publish bị stale so với {$source['path']} — chạy lại evidence:permission-catalog và cập nhật docs/permissions."
            );
        }

        foreach ($published['route_permissions'] as $route) {
            $this->assertArrayHasKey('accepted_permissions', $route, 'Catalog publish phải theo schema >= 1.2.0.');
        }
    }
}
