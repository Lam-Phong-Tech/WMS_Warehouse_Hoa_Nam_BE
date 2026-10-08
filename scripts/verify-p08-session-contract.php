<?php

require_once __DIR__.'/../Modules/Warehouse/app/Http/Middleware/P08HistoryReadContract.php';

use Modules\Warehouse\Http\Middleware\P08HistoryReadContract;

$checks = 0;
$check = static function (bool $pass, string $name) use (&$checks): void {
    if (! $pass) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $checks++;
};
$check(P08HistoryReadContract::unexpectedFilters('SCAN_SESSION', ['warehouse_id' => 'w', 'limit' => 1, 'cursor' => 'c']) === [], 'session scope/pagination');
foreach (['source', 'status', 'business', 'keyword', 'date_mode', 'date', 'from', 'to', 'activityId', 'tenant_id', 'org_id', 'user_id'] as $key) {
    $check(P08HistoryReadContract::unexpectedFilters('SCAN_SESSION', [$key => 'x']) === [$key], 'reject session '.$key);
}
foreach (['limit', 'cursor', 'activityId'] as $key) {
    $check(P08HistoryReadContract::unexpectedFilters('SUMMARY', [$key => 'x']) === [$key], 'unpaged summary '.$key);
}
$check(P08HistoryReadContract::unexpectedFilters('SUMMARY', ['source' => 'SCAN', 'date_mode' => 'DATE', 'date' => '2026-10-08']) === [], 'summary date/source');
$check(P08HistoryReadContract::unexpectedFilters('RECORDS', ['status' => 'ACCEPTED', 'limit' => 1]) === [], 'records keep filters');
$routes = file_get_contents(__DIR__.'/../Modules/Warehouse/routes/p08_history.php');
$check(substr_count($routes, 'Route::get(') === 4, 'four GET operations');
$check(str_contains($routes, 'P08HistoryReadContract::class'), 'guard is wired');
$check(! preg_match('/Route::(?:post|put|patch|delete)\s*\(/i', $routes), 'no write routes');
$check(! str_contains(strtolower($routes), 'export'), 'no export');
echo "P08 session/read contract: {$checks} checks passed.\n";
