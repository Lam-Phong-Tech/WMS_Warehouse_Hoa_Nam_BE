<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class ExportPermissionCatalog extends Command
{
    protected $signature = 'evidence:permission-catalog {--output=build/evidence/api-data/permission-catalog.json}';

    protected $description = 'Xuất permission catalog, role mapping, route-permission matrix và source hash làm evidence';

    public function handle(): int
    {
        $output = (string) $this->option('output');
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không thể tạo thư mục evidence: {$directory}");
        }

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
            ->map(function ($rows, $roleCode) {
                $first = $rows->first();

                return [
                    'role_code' => $roleCode,
                    'role_name' => $first->role_name,
                    'scope_type' => $first->scope_type,
                    'status' => $first->status,
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

                return [
                    'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                    'uri' => $route->uri(),
                    'action' => $route->getActionName(),
                    'permission' => $permission ? substr($permission, strlen('permission:')) : null,
                    'middleware' => array_values($middleware),
                ];
            })
            ->sortBy(fn ($route) => $route['uri'].'|'.implode(',', $route['methods']))
            ->values();

        $activePermissionKeys = $permissions
            ->where('status', 'ACTIVE')
            ->pluck('permission_key')
            ->values();
        $routePermissionKeys = $routes
            ->pluck('permission')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $missingRoutePermissions = $routePermissionKeys
            ->diff($activePermissionKeys)
            ->values();

        $endpointPermissionRoleMatrix = $routes->map(function (array $route) use ($roles) {
            $permission = $route['permission'];
            $roleCodes = $permission === null
                ? []
                : $roles
                    ->filter(fn (array $role) => $role['status'] === 'ACTIVE'
                        && (in_array($permission, $role['permissions'], true) || $role['role_code'] === 'SUPER_ADMIN'))
                    ->pluck('role_code')
                    ->values()
                    ->all();

            return [
                'methods' => $route['methods'],
                'uri' => $route['uri'],
                'permission' => $permission,
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
        ];
        $sourceHashes = collect($sourcePaths)->mapWithKeys(function (string $relativePath, string $key) {
            $absolutePath = base_path($relativePath);

            return [$key => [
                'path' => $relativePath,
                'sha256' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            ]];
        })->all();

        $payload = [
            'schema_version' => '1.1.0',
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'approval_status' => 'PENDING_FE_BA_REVIEW',
            'source_hashes' => $sourceHashes,
            'integrity' => [
                'active_permission_count' => $activePermissionKeys->count(),
                'route_permission_count' => $routePermissionKeys->count(),
                'missing_route_permissions' => $missingRoutePermissions->all(),
                'status' => $missingRoutePermissions->isEmpty() ? 'PASS' : 'FAIL',
            ],
            'permissions' => $permissions,
            'roles' => $roles,
            'route_permissions' => $routes,
            'endpoint_permission_role_matrix' => $endpointPermissionRoleMatrix,
        ];

        file_put_contents(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        if ($missingRoutePermissions->isNotEmpty()) {
            $this->error('Permission catalog FAIL: route đang tham chiếu permission chưa được seed: '.$missingRoutePermissions->implode(', '));

            return self::FAILURE;
        }

        $this->info("Đã xuất permission catalog và role matrix: {$output}");

        return self::SUCCESS;
    }
}
