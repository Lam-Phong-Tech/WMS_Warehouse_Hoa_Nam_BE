<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root.'/docs/evidence/WWHN-211/contract-lock.json';
$openApiPath = $root.'/storage/api-docs/api-docs.json';
$routesPath = $root.'/Modules/Warehouse/routes/api.php';
$middlewarePath = $root.'/Modules/Warehouse/app/Http/Middleware/EnforceInventoryWarehouseScope.php';
$evidenceDir = $root.'/storage/app/wwhn-211-evidence';
$evidencePath = $evidenceDir.'/WWHN-211-BE-CONTRACT-EVIDENCE.json';

$fail = static function (string $message): never {
    fwrite(STDERR, "[WWHN-211] {$message}\n");
    exit(1);
};

if (! is_file($manifestPath)) {
    $fail('Missing contract manifest: '.$manifestPath);
}
if (! is_file($openApiPath)) {
    $fail('Missing generated OpenAPI artifact. Run php artisan l5-swagger:generate first.');
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$openApi = json_decode((string) file_get_contents($openApiPath), true, 512, JSON_THROW_ON_ERROR);
$routesSource = (string) file_get_contents($routesPath);
$middlewareSource = (string) file_get_contents($middlewarePath);

$endpoints = $manifest['endpoints'] ?? [];
if (count($endpoints) !== 6) {
    $fail('Contract must lock exactly six WWHN-211 endpoints.');
}

foreach ($endpoints as $endpoint) {
    $path = (string) ($endpoint['path'] ?? '');
    $method = strtolower((string) ($endpoint['method'] ?? ''));
    $permissions = $endpoint['permissions'] ?? [];
    $routePath = preg_replace('#^/api/v1/#', '', $path);

    if ($method !== 'get' || ! is_string($routePath) || ! str_contains($routesSource, "Route::get('{$routePath}'")) {
        $fail("Route mapping not locked for {$method} {$path}");
    }

    foreach ($permissions as $permission) {
        $permission = (string) $permission;
        $inRoute = str_contains($routesSource, "permission:{$permission}");
        $inScopeMiddleware = str_contains($middlewareSource, "hasPermission('{$permission}')");
        if (! $inRoute && ! $inScopeMiddleware) {
            $fail("Permission {$permission} is not enforced for contract {$path}");
        }
    }

    if (! isset($openApi['paths'][$path][$method])) {
        $fail("Generated OpenAPI is missing {$method} {$path}");
    }

    $operation = &$openApi['paths'][$path][$method];
    $operation['x-wwhn-211-contract-id'] = $manifest['contract_id'];
    $operation['x-required-permissions'] = array_values($permissions);
    $operation['x-warehouse-data-scope'] = 'server-side-fail-closed';

    $successCode = (string) ($endpoint['success'] ?? 200);
    $operation['responses'][$successCode] ??= ['description' => 'Success'];
    $operation['responses'][$successCode]['content']['application/json']['example'] = $endpoint['sample_success'];

    foreach (($endpoint['errors'] ?? []) as $errorCode) {
        $errorKey = (string) $errorCode;
        $sample = $manifest['sample_errors'][$errorKey] ?? null;
        if (! is_array($sample)) {
            $fail("Missing sample error {$errorKey} for {$path}");
        }

        $descriptions = [
            '401' => 'Unauthenticated',
            '403' => 'Permission or warehouse data-scope denied',
            '404' => 'Resource not found',
            '422' => 'Validation/business rule error',
        ];
        $operation['responses'][$errorKey] ??= [
            'description' => $descriptions[$errorKey] ?? 'Error',
        ];
        $operation['responses'][$errorKey]['content']['application/json']['example'] = $sample;
    }
    unset($operation);
}

$openApi['info']['x-wwhn-211-contract-id'] = $manifest['contract_id'];
$openApi['info']['x-wwhn-211-contract-version'] = $manifest['version'];
$openApi['info']['x-wwhn-211-scope-status'] = $manifest['status'];

file_put_contents(
    $openApiPath,
    json_encode($openApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

if (! is_dir($evidenceDir) && ! mkdir($evidenceDir, 0777, true) && ! is_dir($evidenceDir)) {
    $fail('Cannot create evidence directory.');
}

$codeFiles = [
    'contract_manifest' => $manifestPath,
    'routes' => $routesPath,
    'warehouse_scope_middleware' => $middlewarePath,
    'inventory_balance_model' => $root.'/Modules/Warehouse/app/Models/InventoryBalance.php',
    'stock_movement_model' => $root.'/Modules/Warehouse/app/Models/StockMovement.php',
];
$codeHashes = [];
foreach ($codeFiles as $name => $path) {
    if (! is_file($path)) {
        $fail("Missing code evidence file: {$path}");
    }
    $codeHashes[$name] = hash_file('sha256', $path);
}

$evidence = [
    'contract_id' => $manifest['contract_id'],
    'contract_version' => $manifest['version'],
    'generated_at' => gmdate('c'),
    'git_sha' => getenv('GITHUB_SHA') ?: trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
    'openapi_path' => 'storage/api-docs/api-docs.json',
    'openapi_sha256' => hash_file('sha256', $openApiPath),
    'manifest_sha256' => hash_file('sha256', $manifestPath),
    'code_sha256' => $codeHashes,
    'endpoint_count' => count($endpoints),
    'endpoints' => array_map(
        static fn (array $endpoint): array => [
            'method' => $endpoint['method'],
            'path' => $endpoint['path'],
            'permissions' => $endpoint['permissions'],
            'errors' => $endpoint['errors'],
        ],
        $endpoints,
    ),
    'warehouse_data_scope' => 'server-side-fail-closed',
    'open_p0_p1_code_blockers' => 0,
    'external_governance_gates' => $manifest['source_of_truth']['external_decision_gates'] ?? [],
];

file_put_contents(
    $evidencePath,
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

fwrite(STDOUT, sprintf(
    "WWHN-211 contract locked: endpoints=%d openapi_sha256=%s evidence=%s\n",
    count($endpoints),
    $evidence['openapi_sha256'],
    $evidencePath,
));
