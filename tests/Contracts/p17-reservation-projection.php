<?php

// Pure projection checks only. No Laravel boot, database, HTTP, token or chip.
require_once __DIR__.'/../../Modules/Warehouse/app/Support/OutboundReservationProjection.php';

use Modules\Warehouse\Support\OutboundReservationProjection;

$reservation = [
    'match_id' => 'test-match', 'document_id' => 'test-document', 'doc_no' => 'TEST-NOT-LIVE',
    'status' => 'SCANNING', 'outbound_request_id' => null, 'internal_secret' => 'DO-NOT-EXPOSE',
];
$hidden = OutboundReservationProjection::make($reservation, false);
$shown = OutboundReservationProjection::make($reservation, true);
$checks = [
    'hidden reservation remains non-null' => $hidden !== null,
    'hidden reservation has only explicit denial flags' => $hidden === ['redacted' => true, 'can_open' => false],
    'visible identity is preserved' => $shown['document_id'] === $reservation['document_id'],
    'visible document number is preserved' => $shown['doc_no'] === $reservation['doc_no'],
    'nullable owner request remains null' => array_key_exists('outbound_request_id', $shown) && $shown['outbound_request_id'] === null,
    'unexpected private fields never escape' => ! array_key_exists('internal_secret', $shown),
    'input is not mutated' => $reservation['internal_secret'] === 'DO-NOT-EXPOSE',
    'missing document never advertises open' => OutboundReservationProjection::make([], true) === $hidden,
];
foreach ($checks as $name => $passed) {
    if (! $passed) {
        throw new RuntimeException('FAIL: '.$name);
    }
    echo 'PASS: '.$name.PHP_EOL;
}
echo count($checks).' pure projection checks passed; integration/live NOT executed.'.PHP_EOL;
