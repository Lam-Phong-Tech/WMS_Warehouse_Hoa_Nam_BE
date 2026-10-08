<?php

// Dependency-free policy regression checks; not a substitute for DB/native smoke.
require __DIR__.'/../Modules/Warehouse/app/Support/P07NfcPolicy.php';

use Modules\Warehouse\Support\P07NfcPolicy as Policy;

$count = 0;
$check = function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$scope = ['actor' => (object) ['user_id' => 'actor-a'], 'auth_session_id' => 'session-a', 'warehouse_id' => 'warehouse-a'];
$held = ['actor_id' => 'actor-a', 'auth_session_id' => 'session-a', 'warehouse_id' => 'warehouse-a', 'token' => 'token-a', 'expires_at' => '2026-10-08T12:05:00+07:00'];
$now = strtotime('2026-10-08T12:00:00+07:00');
$check(Policy::tab('ACTIVE', 'item-a') === 'ASSIGNED', 'linked ACTIVE');
$check(Policy::tab('ACTIVE', null) === 'UNASSIGNED', 'unlinked ACTIVE');
$check(Policy::tab('ACTIVE', '') === 'UNASSIGNED', 'empty association');
foreach (['INACTIVE', 'LOST', 'DAMAGED'] as $status) {
    $check(Policy::tab($status, 'item-a') === 'LOCKED', $status.' stays in canonical UI bucket');
    $check(Policy::LABELS[Policy::tab($status, null)] === 'Tạm khóa', 'no invented raw-status UI label');
}
$check(Policy::tab('NEW_UNKNOWN_STATUS', null) === null, 'unknown state is not mislabeled');
$check(Policy::reservationError($held, $scope, 'token-a', $now) === null, 'matching reservation');
$check(Policy::reservationError(null, $scope, 'token-a', $now) === 'NFC_RESERVATION_EXPIRED', 'missing reservation');
$check(Policy::reservationError($held, $scope, 'token-a', $now + 300) === 'NFC_RESERVATION_EXPIRED', 'expiry is inclusive');
$check(Policy::reservationError(array_replace($held, ['expires_at' => 'invalid']), $scope, 'token-a', $now) === 'NFC_RESERVATION_EXPIRED', 'bad expiration fails closed');
$check(Policy::reservationError($held, $scope, 'wrong-token', $now) === 'NFC_RESERVATION_CONTEXT_MISMATCH', 'wrong token');
foreach (['actor_id', 'auth_session_id', 'warehouse_id'] as $field) {
    $check(Policy::reservationError(array_replace($held, [$field => 'other']), $scope, 'token-a', $now) === 'NFC_RESERVATION_CONTEXT_MISMATCH', 'different '.$field);
}
$legacy = $held;
unset($legacy['auth_session_id']);
$check(! Policy::owns($legacy, $scope), 'legacy reservation cannot cross sessions');
if (function_exists('mb_strtolower')) {
    $check(Policy::pattern('  A%_!  ') === '%a!%!_!!%', 'literal wildcard escaping');
} else {
    echo "SKIP: keyword normalization (mbstring unavailable in this CLI).\n";
}
echo 'PASS: '.$count." P07 NFC policy checks. DB integration and native NFC smoke are separate.\n";
