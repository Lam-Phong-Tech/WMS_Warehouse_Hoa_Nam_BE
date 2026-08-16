<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Modules\Auth\Models\Permission;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outDir = $argv[1] ?? 'storage/app/wwhn-218-evidence';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create evidence directory: {$outDir}\n");
    exit(2);
}

$permissions = Permission::query()
    ->orderBy('permission_key')
    ->get(['permission_key', 'permission_name', 'scope_type', 'status'])
    ->map(fn ($permission): array => [
        'permission_key' => (string) $permission->permission_key,
        'permission_name' => (string) $permission->permission_name,
        'scope_type' => (string) $permission->scope_type,
        'status' => (string) $permission->status,
    ])
    ->values()
    ->all();

$routeMatrix = [];
foreach (app('router')->getRoutes() as $route) {
    $middleware = array_values(array_map('strval', $route->gatherMiddleware()));
    $requiredPermissions = [];
    foreach ($middleware as $entry) {
        if (str_starts_with($entry, 'permission:')) {
            $requiredPermissions[] = substr($entry, strlen('permission:'));
        }
    }
    if ($requiredPermissions === []) {
        continue;
    }

    $routeMatrix[] = [
        'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
        'uri' => $route->uri(),
        'name' => $route->getName(),
        'action' => $route->getActionName(),
        'permissions' => array_values(array_unique($requiredPermissions)),
    ];
}

usort($routeMatrix, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

file_put_contents(
    $outDir.'/permission-catalog.json',
    json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);
file_put_contents(
    $outDir.'/route-permission-matrix.json',
    json_encode($routeMatrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

$permissionKeys = array_column($permissions, 'permission_key');
$activeReversal = collect($permissions)->contains(
    fn (array $row): bool => $row['permission_key'] === 'inventory.reversal.post' && strtoupper($row['status']) === 'ACTIVE'
);

// Module routes are mounted under Laravel's global /api prefix and then the
// Warehouse module's /v1 prefix. The evidence matrix must use the effective
// runtime URI exposed by `route:list`, not the module-local path fragment.
$requiredRoutePermissions = [
    'api/v1/inbound-documents/{id}/reverse' => 'inventory.reversal.post',
    'api/v1/outbound-documents/{id}/reverse' => 'inventory.reversal.post',
    'api/v1/reversal-documents' => 'inventory.reversal.post',
];
$routeChecks = [];
foreach ($requiredRoutePermissions as $uri => $permission) {
    $matched = collect($routeMatrix)->contains(
        fn (array $row): bool => $row['uri'] === $uri && in_array($permission, $row['permissions'], true)
    );
    $routeChecks[$uri] = $matched;
}

$forbiddenMutationRoutes = collect($routeMatrix)
    ->filter(fn (array $row): bool => str_contains(strtolower($row['uri']), 'apply-adjustment') || str_contains(strtolower($row['uri']), 'stocktake'))
    ->values()
    ->all();

$summary = [
    'generated_at' => now()->toIso8601String(),
    'permission_count' => count($permissions),
    'permission_catalog_not_stale_31' => count($permissions) > 31,
    'inventory_reversal_post_active' => $activeReversal,
    'route_checks' => $routeChecks,
    'apply_adjustment_or_stocktake_mutation_routes' => $forbiddenMutationRoutes,
    'legacy_permission_count' => count(array_filter($permissionKeys, static fn (string $key): bool => in_array($key, [
        'product.view', 'product.manage', 'master.manage', 'stock_document.view', 'stock_document.create',
        'stock_document.post', 'stock_document.scan', 'stock_document.cancel', 'stock_document.reverse',
    ], true))),
];

file_put_contents(
    $outDir.'/permission-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

$ok = $summary['permission_catalog_not_stale_31']
    && $summary['inventory_reversal_post_active']
    && ! in_array(false, $routeChecks, true)
    && $forbiddenMutationRoutes === [];

exit($ok ? 0 : 1);
