<?php

declare(strict_types=1);

/**
 * WWHN-219 permission catalog generator.
 *
 * The catalog is produced by querying the database AFTER `migrate:fresh --seed`,
 * never by copying a committed snapshot. DatabaseSeeder runs AuthDatabaseSeeder,
 * which in turn runs WmsPermissionCompatibilitySeeder and keeps upserting FE
 * alias permissions, so any hand-counted constant (notably the stale "31") is
 * wrong by construction.
 *
 * Emits permission-catalog.json, permission-catalog.csv, the route-permission
 * matrix, the live count and a SHA256 over the catalog bytes so the manifest can
 * pin exactly what was generated on the evidence build.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outDir = $argv[1] ?? (getenv('WWHN219_EVIDENCE_DIR') ?: 'storage/app/wwhn-219-evidence');
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create evidence directory: {$outDir}\n");
    exit(2);
}

$permissions = DB::table('permission')
    ->orderBy('permission_key')
    ->get(['permission_key', 'permission_name', 'scope_type', 'status'])
    ->map(static fn ($row): array => [
        'permission_key' => (string) $row->permission_key,
        'permission_name' => (string) $row->permission_name,
        'scope_type' => (string) $row->scope_type,
        'status' => (string) $row->status,
    ])
    ->values()
    ->all();

if ($permissions === []) {
    fwrite(STDERR, "Permission table is empty; the catalog must be generated after migrate:fresh --seed.\n");
    exit(3);
}

$catalogJson = json_encode(
    $permissions,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
)."\n";
file_put_contents($outDir.'/permission-catalog.json', $catalogJson);

$csv = fopen($outDir.'/permission-catalog.csv', 'wb');
fputcsv($csv, ['permission_key', 'permission_name', 'scope_type', 'status']);
foreach ($permissions as $permission) {
    fputcsv($csv, [
        $permission['permission_key'],
        $permission['permission_name'],
        $permission['scope_type'],
        $permission['status'],
    ]);
}
fclose($csv);

$routeMatrix = [];
foreach (app('router')->getRoutes() as $route) {
    $required = [];
    foreach ($route->gatherMiddleware() as $entry) {
        $entry = (string) $entry;
        if (str_starts_with($entry, 'permission:')) {
            $required[] = substr($entry, strlen('permission:'));
        }
    }
    if ($required === []) {
        continue;
    }

    $routeMatrix[] = [
        'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
        'uri' => $route->uri(),
        'name' => $route->getName(),
        'action' => $route->getActionName(),
        'permissions' => array_values(array_unique($required)),
    ];
}
usort($routeMatrix, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

file_put_contents(
    $outDir.'/route-permission-matrix.json',
    json_encode($routeMatrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

// The baseline forbids any independent stock-mutation surface outside
// Post Receipt / Post Issue / Reversal (REQ-SRS-013, BR-REV-01).
$forbiddenMutationRoutes = array_values(array_filter(
    $routeMatrix,
    static fn (array $row): bool => str_contains(strtolower($row['uri']), 'apply-adjustment')
        || str_contains(strtolower($row['uri']), 'stocktake')
));

$requiredRoutePermissions = [
    'api/v1/inbound-documents/{id}/reverse' => 'inventory.reversal.post',
    'api/v1/outbound-documents/{id}/reverse' => 'inventory.reversal.post',
    'api/v1/reversal-documents' => 'inventory.reversal.post',
];
$routeChecks = [];
foreach ($requiredRoutePermissions as $uri => $permission) {
    $routeChecks[$uri] = (bool) array_filter(
        $routeMatrix,
        static fn (array $row): bool => $row['uri'] === $uri && in_array($permission, $row['permissions'], true)
    );
}

$reversalActive = (bool) array_filter(
    $permissions,
    static fn (array $p): bool => $p['permission_key'] === 'inventory.reversal.post'
        && strtoupper($p['status']) === 'ACTIVE'
);

$catalogSha256 = hash('sha256', $catalogJson);
$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-219',
    'source' => 'LIVE_DATABASE_AFTER_MIGRATE_FRESH_SEED',
    'git_sha' => getenv('GITHUB_SHA') ?: null,
    'github_run_id' => getenv('GITHUB_RUN_ID') ?: null,
    'database_driver' => DB::connection()->getDriverName(),
    'permission_count' => count($permissions),
    'permission_catalog_sha256' => $catalogSha256,
    'permission_catalog_file' => 'permission-catalog.json',
    'stale_snapshot_31_rejected' => count($permissions) !== 31,
    'inventory_reversal_post_active' => $reversalActive,
    'route_permission_checks' => $routeChecks,
    'apply_adjustment_or_stocktake_mutation_routes' => $forbiddenMutationRoutes,
    'route_count_with_permission_middleware' => count($routeMatrix),
];

file_put_contents(
    $outDir.'/permission-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);
file_put_contents($outDir.'/PERMISSION_COUNT', count($permissions)."\n");
file_put_contents($outDir.'/PERMISSION_CATALOG_SHA256', $catalogSha256."\n");

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

$ok = $summary['stale_snapshot_31_rejected']
    && $reversalActive
    && ! in_array(false, $routeChecks, true)
    && $forbiddenMutationRoutes === [];

exit($ok ? 0 : 1);
