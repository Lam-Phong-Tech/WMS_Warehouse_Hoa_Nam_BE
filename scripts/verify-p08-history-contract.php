<?php

require __DIR__.'/../Modules/Warehouse/app/Support/P08HistoryPolicy.php';

use Modules\Warehouse\Support\P08HistoryPolicy as Policy;

$checks = 0;
$check = function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) {
        fwrite(STDERR, 'FAIL '.$name.PHP_EOL);
        exit(1);
    }
    $checks++;
};
$id = '01234567-89ab-cdef-0123-456789abcdef';
foreach (Policy::SOURCES as $source) {
    $check(Policy::identity($source.':'.$id) === [$source, $id], 'exact source identity '.$source);
}
foreach ([$id, 'AUTH:'.$id, 'DOCUMENT:1', 'SCAN:'.$id.'/../secret', 'SCAN:'] as $invalid) {
    try {
        Policy::identity($invalid);
        $check(false, 'reject '.$invalid);
    } catch (InvalidArgumentException) {
        $check(true, 'invalid ID rejected');
    }
}
$a = ['warehouse' => 'A', 'actor' => 'A', 'session' => 'A', 'tenant' => 'A', 'org' => 'A', 'rights' => 'history.read'];
$filters = ['source' => 'ALL', 'date_mode' => 'ALL'];
$fingerprint = Policy::fingerprint($a, $filters);
$check($fingerprint === Policy::fingerprint(array_reverse($a, true), array_reverse($filters, true)), 'stable field order');
foreach (array_keys($a) as $key) {
    $other = $a;
    $other[$key] = 'B';
    $check($fingerprint !== Policy::fingerprint($other, $filters), 'bind '.$key);
}
$cursor = ['v' => 1, 'expires' => 200, 'scope' => $fingerprint, 'asOf' => '2026-10-08', 'time' => '', 'activityId' => 'SCAN:'.$id];
$check(Policy::cursorMatches($cursor, $fingerprint, 100), 'valid cursor');
$check(! Policy::cursorMatches($cursor, $fingerprint, 200), 'expiry boundary');
$check(! Policy::cursorMatches($cursor, 'another-scope', 100), 'scope rejected');
foreach (array_keys($cursor) as $key) {
    $bad = $cursor;
    unset($bad[$key]);
    $check(! Policy::cursorMatches($bad, $fingerprint, 100), 'missing cursor '.$key);
}
$counts = Policy::scanCounts(['ACCEPTED' => '2', 'DUPLICATE' => '1', 'RESOLVED' => '4']);
$check($counts['total'] === 7 && $counts['accepted'] === 2 && $counts['duplicate'] === 1 && $counts['other'] === 4, 'canonical counts');
$check($counts['quantity'] === null, 'quantity never manufactured from events');
$check(Policy::scanCounts(['RESOLVED' => 2])['accepted'] === 0, 'resolved is not accepted');
$check(Policy::scanCounts([])['total'] === 0, 'empty count');
$check(Policy::pattern(' A%_! ') === '%A!%!_!!%', 'escape LIKE wildcards');
$routes = file_get_contents(__DIR__.'/../Modules/Warehouse/routes/p08_history.php');
$check(substr_count($routes, 'Route::get(') === 4, 'four GET routes');
$check(preg_match('/Route::(post|patch|put|delete|any|match)\(/i', $routes) === 0, 'no mutation routes');
$check(str_contains($routes, 'permission:history.read'), 'history permission required');
foreach (['P08HistoryController.php' => 'Http/Controllers', 'P08HistoryReadModel.php' => 'Support'] as $file => $dir) {
    $code = file_get_contents(__DIR__.'/../Modules/Warehouse/app/'.$dir.'/'.$file);
    $check(preg_match('/->(insert|update|delete|upsert|increment|decrement|save)\s*\(/', $code) === 0, 'no domain writes in '.$file);
}
fwrite(STDOUT, 'P08 policy/static checks: '.$checks.' PASS'.PHP_EOL);
