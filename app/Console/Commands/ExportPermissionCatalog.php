<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use RuntimeException;

class ExportPermissionCatalog extends Command
{
    protected $signature = 'evidence:permission-catalog
        {--output=build/evidence/api-data/permission-catalog.json}
        {--seed-diff-output=build/evidence/api-data/permission-seed-diff.json}
        {--previous= : Catalog đã publish trước đó (JSON); có thì payload kèm danh sách thay đổi route/permission/role so với bản đó}';

    protected $description = 'Xuất permission catalog, role mapping, route-permission matrix và source hash làm evidence';

    public function handle(): int
    {
        $output = (string) $this->option('output');
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không thể tạo thư mục evidence: {$directory}");
        }

        // Đọc bản trước TRƯỚC khi ghi: --previous được phép trỏ đúng file --output.
        $previous = $this->readPreviousCatalog();

        // Role fixture chỉ được seed ở local/testing/demo (SeedEnvironment). Chúng vẫn nằm
        // trong catalog để QA thấy đúng DB đang kiểm, nhưng hợp đồng production WWHN-150 chỉ
        // xét role production — nếu không, một role kiểm thử làm đỏ gate của production.
        $fixtureRoleCodes = AuthDatabaseSeeder::NON_PRODUCTION_ROLES;

        $permissions = DB::table('permission')
            ->select('permission_key', 'permission_name', 'scope_type', 'status')
            ->orderBy('permission_key')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values();

        $roleRows = DB::table('role as r')
            ->leftJoin('role_permission as rp', 'rp.role_id', '=', 'r.role_id')
            ->leftJoin('permission as p', 'p.permission_id', '=', 'rp.permission_id')
            ->select('r.role_code', 'r.role_name', 'r.scope_type', 'r.status', 'p.permission_key')
            ->orderBy('r.role_code')
            ->orderBy('p.permission_key')
            ->get();

        $roles = $roleRows
            ->groupBy('role_code')
            ->map(function ($rows, $roleCode) use ($fixtureRoleCodes) {
                $first = $rows->first();

                return [
                    'role_code' => $roleCode,
                    'role_name' => $first->role_name,
                    'scope_type' => $first->scope_type,
                    'status' => $first->status,
                    'fixture_only' => in_array($roleCode, $fixtureRoleCodes, true),
                    'permissions' => $rows->pluck('permission_key')->filter()->values()->all(),
                ];
            })
            ->values();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->map(function ($route) {
                $middleware = $route->gatherMiddleware();
                $permission = collect($middleware)
                    ->first(fn ($item) => is_string($item) && str_starts_with($item, 'permission:'));

                $expression = $permission ? substr($permission, strlen('permission:')) : null;

                return [
                    'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                    'uri' => $route->uri(),
                    'action' => $route->getActionName(),
                    'permission' => $expression,
                    'accepted_permissions' => self::acceptedPermissionKeys($expression),
                    'middleware' => array_values($middleware),
                ];
            })
            ->sortBy(fn ($route) => $route['uri'].'|'.implode(',', $route['methods']))
            ->values();

        $activePermissionKeys = $permissions
            ->where('status', 'ACTIVE')
            ->pluck('permission_key')
            ->values();
        // `permission:A|B` là biểu thức OR do CheckPermission tách bằng `|`; catalog phải
        // đối chiếu theo từng permission key thành phần, không so khớp cả chuỗi biểu thức.
        $routePermissionKeys = $routes
            ->pluck('accepted_permissions')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $missingRoutePermissions = $routePermissionKeys
            ->diff($activePermissionKeys)
            ->values();

        $endpointPermissionRoleMatrix = $routes->map(function (array $route) use ($roles) {
            $permission = $route['permission'];
            $accepted = $route['accepted_permissions'];
            $roleCodes = $permission === null
                ? []
                : $roles
                    ->filter(fn (array $role) => $role['status'] === 'ACTIVE'
                        && ($role['role_code'] === 'SUPER_ADMIN'
                            || array_intersect($accepted, $role['permissions']) !== []))
                    ->pluck('role_code')
                    ->values()
                    ->all();

            return [
                'methods' => $route['methods'],
                'uri' => $route['uri'],
                'permission' => $permission,
                'accepted_permissions' => $accepted,
                'roles' => $roleCodes,
                'backend_enforced' => $permission !== null,
            ];
        })->values();

        $sourcePaths = [
            'permission_seed' => 'Modules/Auth/database/seeders/AuthDatabaseSeeder.php',
            'warehouse_routes' => 'Modules/Warehouse/routes/api.php',
            'auth_routes' => 'Modules/Auth/routes/api.php',
            'audit_routes' => 'Modules/AuditLog/routes/api.php',
            'permission_middleware' => 'Modules/Auth/app/Http/Middleware/CheckPermission.php',
            'permission_exporter' => 'app/Console/Commands/ExportPermissionCatalog.php',
            'user_permission_model' => 'Modules/Auth/app/Models/User.php',
        ];
        $sourceHashes = collect($sourcePaths)->mapWithKeys(function (string $relativePath, string $key) {
            $absolutePath = base_path($relativePath);

            return [$key => [
                'path' => $relativePath,
                'sha256' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            ]];
        })->all();

        $scanMatrix = $endpointPermissionRoleMatrix->first(
            fn (array $row): bool => $row['uri'] === 'api/v1/scan/classify'
                && in_array('POST', $row['methods'], true)
        );
        // Baseline role set của POST /scan-batches/classify:
        // - WWHN-150 chốt 4 role đầu qua permission key chuẩn `scan.classify`.
        // - SRS v1.12 §5 (bảng vai trò, "Nhân viên kho ... scan/match Item") cộng Annex C alias
        //   `scan.execute` mở thêm WAREHOUSE_KEEPER/WAREHOUSE_STAFF; alias được seed trong
        //   AuthDatabaseSeeder và được CheckPermission chấp nhận theo OR.
        // Danh sách dưới đây là baseline hợp nhất; lệch theo cả hai chiều đều FAIL để phát hiện drift.
        //
        // WAREHOUSE_MANAGER gỡ khỏi baseline 06/09/2026: tài liệu BA taikhoantheorole v2
        // đưa vai này về giám sát và chỉ cho "xem kết quả scan/import", không cho thao
        // tác quét — nên seeder không còn cấp scan.classify/scan.execute cho nó. Không
        // sửa dòng này thì lần khôi phục cổng WWHN-309 sau sẽ ghim lại đúng cái baseline
        // đã sai.
        $expectedScanRoles = [
            'ADMIN',
            'SUPER_ADMIN',
            'WAREHOUSE_KEEPER',
            'WAREHOUSE_STAFF',
            'WARRANTY_STAFF',
        ];
        $scanRoles = collect($scanMatrix['roles'] ?? [])->sort()->values();
        $actualScanRoles = $scanRoles
            ->reject(fn (string $role): bool => in_array($role, $fixtureRoleCodes, true))
            ->values();
        $fixtureScanRoles = $scanRoles
            ->filter(fn (string $role): bool => in_array($role, $fixtureRoleCodes, true))
            ->values();
        $missingScanRoles = collect($expectedScanRoles)->diff($actualScanRoles)->values();
        $unexpectedScanRoles = $actualScanRoles->diff($expectedScanRoles)->values();
        $seededFixtureRoles = $roles
            ->where('fixture_only', true)
            ->pluck('role_code')
            ->values()
            ->all();
        $permissionSeedDiff = [
            'schema_version' => '1.2.0',
            'contract' => 'WWHN-150 + SRS v1.12 Annex C scan classify permission seed diff',
            'evaluation_scope' => 'PRODUCTION_ROLES',
            'expected' => [
                'permission_key' => 'scan.classify',
                'annex_c_alias_key' => 'scan.execute',
                'permission_status' => 'ACTIVE',
                'roles' => $expectedScanRoles,
                'roles_source' => 'WWHN-150 (scan.classify) + SRS v1.12 §5 role table via Annex C alias scan.execute',
                'super_admin_rule' => 'backend bypass through User::isSuperAdmin',
            ],
            'actual' => [
                'permission_status' => $permissions->firstWhere('permission_key', 'scan.classify')['status'] ?? null,
                'roles' => $actualScanRoles->all(),
                'route_permission' => $scanMatrix['permission'] ?? null,
                'route_accepted_permissions' => $scanMatrix['accepted_permissions'] ?? [],
                'backend_enforced' => $scanMatrix['backend_enforced'] ?? false,
            ],
            'fixture_roles' => [
                'source' => AuthDatabaseSeeder::class.'::NON_PRODUCTION_ROLES',
                'declared' => array_values($fixtureRoleCodes),
                'seeded' => $seededFixtureRoles,
                'holding_scan_permission' => $fixtureScanRoles->all(),
                'rule' => 'Role fixture không được seed ở production (SeedEnvironment) và wms:golive-check chặn chúng ngoài development; chúng được báo cáo ở đây và KHÔNG tính vào missing_roles/unexpected_roles của hợp đồng WWHN-150.',
            ],
            'missing_roles' => $missingScanRoles->all(),
            'unexpected_roles' => $unexpectedScanRoles->all(),
            'status' => $missingScanRoles->isEmpty()
                && $unexpectedScanRoles->isEmpty()
                && ($permissions->firstWhere('permission_key', 'scan.classify')['status'] ?? null) === 'ACTIVE'
                && in_array('scan.classify', $scanMatrix['accepted_permissions'] ?? [], true)
                && ($scanMatrix['backend_enforced'] ?? false)
                ? 'PASS'
                : 'FAIL',
        ];

        $payload = [
            'schema_version' => '1.3.0',
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'approval_status' => 'PENDING_FE_BA_REVIEW',
        ];
        if ($previous !== null) {
            $payload['changes_since_previous'] = $this->changesSince($previous, $permissions, $roles, $routes);
        }
        $payload += [
            'source_hashes' => $sourceHashes,
            'integrity' => [
                'active_permission_count' => $activePermissionKeys->count(),
                'route_permission_count' => $routePermissionKeys->count(),
                'missing_route_permissions' => $missingRoutePermissions->all(),
                'status' => $missingRoutePermissions->isEmpty() ? 'PASS' : 'FAIL',
            ],
            'fixture_roles' => $seededFixtureRoles,
            'permissions' => $permissions,
            'roles' => $roles,
            'route_permissions' => $routes,
            'endpoint_permission_role_matrix' => $endpointPermissionRoleMatrix,
            'permission_seed_diff' => $permissionSeedDiff,
        ];

        file_put_contents(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        $seedDiffOutput = (string) $this->option('seed-diff-output');
        $seedDiffDirectory = dirname($seedDiffOutput);
        if (! is_dir($seedDiffDirectory) && ! mkdir($seedDiffDirectory, 0777, true) && ! is_dir($seedDiffDirectory)) {
            throw new RuntimeException("Không thể tạo thư mục seed diff evidence: {$seedDiffDirectory}");
        }
        file_put_contents(
            $seedDiffOutput,
            json_encode($permissionSeedDiff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        if ($missingRoutePermissions->isNotEmpty() || $permissionSeedDiff['status'] !== 'PASS') {
            $this->error('Permission catalog FAIL: route/seed/role matrix không khớp contract WWHN-150.');

            return self::FAILURE;
        }

        $this->info("Đã xuất permission catalog và role matrix: {$output}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function readPreviousCatalog(): ?array
    {
        $path = (string) $this->option('previous');
        if ($path === '') {
            return null;
        }

        if (! is_file($path)) {
            throw new RuntimeException("Không đọc được catalog trước đó: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Danh sách thay đổi so với catalog đã publish trước đó — thứ FE/BA phải review, thay vì
     * đọc lại toàn bộ catalog để tự đoán cái gì đã đổi.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function changesSince(array $previous, Collection $permissions, Collection $roles, Collection $routes): array
    {
        $routeKey = static fn (array $route): string => implode('|', $route['methods'] ?? []).' '.($route['uri'] ?? '');

        $routesBefore = collect($previous['route_permissions'] ?? [])
            ->mapWithKeys(fn (array $route): array => [$routeKey($route) => $route['permission'] ?? null]);
        $routesAfter = $routes
            ->mapWithKeys(fn (array $route): array => [$routeKey($route) => $route['permission']]);

        $routesAdded = $routesAfter->keys()->diff($routesBefore->keys())->sort()->values()
            ->map(fn (string $key): array => ['route' => $key, 'permission' => $routesAfter[$key]])
            ->all();
        $routesRemoved = $routesBefore->keys()->diff($routesAfter->keys())->sort()->values()
            ->map(fn (string $key): array => ['route' => $key, 'permission' => $routesBefore[$key]])
            ->all();
        $routePermissionChanged = $routesAfter->keys()->intersect($routesBefore->keys())->sort()->values()
            ->filter(fn (string $key): bool => $routesAfter[$key] !== $routesBefore[$key])
            ->map(fn (string $key): array => ['route' => $key, 'from' => $routesBefore[$key], 'to' => $routesAfter[$key]])
            ->values()
            ->all();

        $permissionsBefore = collect($previous['permissions'] ?? [])
            ->mapWithKeys(fn (array $permission): array => [$permission['permission_key'] => $permission['status'] ?? null]);
        $permissionsAfter = $permissions
            ->mapWithKeys(fn (array $permission): array => [$permission['permission_key'] => $permission['status']]);

        $permissionStatusChanged = $permissionsAfter->keys()->intersect($permissionsBefore->keys())->sort()->values()
            ->filter(fn (string $key): bool => $permissionsAfter[$key] !== $permissionsBefore[$key])
            ->map(fn (string $key): array => ['permission_key' => $key, 'from' => $permissionsBefore[$key], 'to' => $permissionsAfter[$key]])
            ->values()
            ->all();

        $rolesBefore = collect($previous['roles'] ?? [])
            ->mapWithKeys(fn (array $role): array => [$role['role_code'] => $role['permissions'] ?? []]);
        $rolesAfter = $roles
            ->mapWithKeys(fn (array $role): array => [$role['role_code'] => $role['permissions']]);

        $rolePermissionChanges = [];
        foreach ($rolesAfter->keys()->intersect($rolesBefore->keys())->sort()->values() as $roleCode) {
            $added = array_values(array_diff($rolesAfter[$roleCode], $rolesBefore[$roleCode]));
            $removed = array_values(array_diff($rolesBefore[$roleCode], $rolesAfter[$roleCode]));
            if ($added === [] && $removed === []) {
                continue;
            }
            sort($added);
            sort($removed);
            $rolePermissionChanges[$roleCode] = ['added' => $added, 'removed' => $removed];
        }

        $changes = [
            'previous_generated_at_utc' => $previous['generated_at_utc'] ?? null,
            'previous_schema_version' => $previous['schema_version'] ?? null,
            'routes_added' => $routesAdded,
            'routes_removed' => $routesRemoved,
            'route_permission_changed' => $routePermissionChanged,
            'permissions_added' => $permissionsAfter->keys()->diff($permissionsBefore->keys())->sort()->values()->all(),
            'permissions_removed' => $permissionsBefore->keys()->diff($permissionsAfter->keys())->sort()->values()->all(),
            'permission_status_changed' => $permissionStatusChanged,
            'roles_added' => $rolesAfter->keys()->diff($rolesBefore->keys())->sort()->values()->all(),
            'roles_removed' => $rolesBefore->keys()->diff($rolesAfter->keys())->sort()->values()->all(),
            'role_permission_changes' => $rolePermissionChanges,
        ];

        return ['summary' => [
            'routes_added' => count($changes['routes_added']),
            'routes_removed' => count($changes['routes_removed']),
            'route_permission_changed' => count($changes['route_permission_changed']),
            'permissions_added' => count($changes['permissions_added']),
            'permissions_removed' => count($changes['permissions_removed']),
            'permission_status_changed' => count($changes['permission_status_changed']),
            'roles_with_permission_changes' => count($changes['role_permission_changes']),
        ]] + $changes;
    }

    /**
     * Tách biểu thức permission của route thành danh sách key được chấp nhận.
     * Phải giữ đúng semantics runtime của Modules\Auth CheckPermission: `A|B` là OR.
     *
     * @return list<string>
     */
    private static function acceptedPermissionKeys(?string $expression): array
    {
        if ($expression === null || trim($expression) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $expression))));
    }
}
