<?php

// Pure gate: no Laravel bootstrap, database, HTTP request or external writes.
require_once __DIR__.'/../Modules/Warehouse/app/Support/P08HistoryPolicy.php';
require_once __DIR__.'/../Modules/Warehouse/app/Support/P23ScanSessionPolicy.php';
require_once __DIR__.'/../Modules/Warehouse/app/Support/P23WarrantyDateFilter.php';
require_once __DIR__.'/../tests/Support/P23WarrantyDateCases.php';

use Modules\Warehouse\Support\P23ScanSessionPolicy;
use Modules\Warehouse\Support\P23WarrantyDateFilter;
use Tests\Support\P23WarrantyDateCases;

$count = 0;
$check = function (bool $condition, string $message) use (&$count): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $count++;
};
foreach (P23WarrantyDateCases::valid() as $name => [$query, $tz, $expected]) {
    $actual = P23WarrantyDateFilter::parse($query, new DateTimeZone($tz));
    if ($expected === null) {
        $check($actual === null, $name);
        continue;
    }
    $check($actual['mode'] === $expected['mode'] && $actual['field'] === $expected['field'], $name);
    if (isset($expected['start'])) {
        $check($actual['start']->format(DATE_ATOM) === $expected['start'], $name.' start');
        $check($actual['end']->format(DATE_ATOM) === $expected['end'], $name.' end');
    }
}
foreach (P23WarrantyDateCases::invalid() as $name => [$query, $code]) {
    try {
        P23WarrantyDateFilter::parse($query, new DateTimeZone('Asia/Ho_Chi_Minh'));
        throw new RuntimeException('Invalid input accepted: '.$name);
    } catch (InvalidArgumentException $error) {
        $check($error->getMessage() === $code, $name);
    }
}
$window = P23WarrantyDateFilter::parse(['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-10-10'], new DateTimeZone('Asia/Ho_Chi_Minh'));
$check(P23WarrantyDateFilter::bounds($window, 'TIMESTAMP_WITH_TIME_ZONE') === ['2026-10-09 17:00:00+00:00', '2026-10-10 17:00:00+00:00'], 'UTC storage boundaries');
$check(P23WarrantyDateFilter::bounds($window, 'APP_LOCAL_WALL_CLOCK') === ['2026-10-10 00:00:00', '2026-10-11 00:00:00'], 'Local storage boundaries');
$metrics = P23ScanSessionPolicy::counts(['ACCEPTED' => 2, 'DUPLICATE' => 1, 'RESOLVED' => 4, 'REJECTED' => 3]);
$check($metrics['total'] === 10 && $metrics['accepted'] === 2 && $metrics['duplicate'] === 1 && $metrics['other'] === 7, 'P08 partitions preserved');
$check($metrics['explicitRejected'] === 3 && $metrics['rejected'] === null, 'Explicit subset is not complete rejection total');
$check($metrics['quantity'] === null && $metrics['issuedQuantity'] === null && $metrics['isConfirmationReceipt'] === false, 'No quantity or posting fabrication');
$check(P23ScanSessionPolicy::counts([])['total'] === 0, 'Confirmed empty event source');
$check(P23ScanSessionPolicy::counts(null)['total'] === null, 'Unavailable is not zero');
echo json_encode(['gate' => 'P23_PURE_READ_RULES', 'status' => 'PASS', 'assertions' => $count,
    'http_database_or_live_tests' => 'NOT_EXECUTED_BY_THIS_SCRIPT'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
