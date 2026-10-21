<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$expectedBuild = getenv('EXPECTED_BUILD_SHA') ?: 'e2442df9cb03a50f57847aca0bcba78f0b2b2717';
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'wms_hoanam_green'
    || ! preg_match('/\A[0-9a-f]{40}\z/D', $expectedBuild) || config('health.build.sha') !== $expectedBuild) {
    throw new RuntimeException('NFC timestamp inspection target mismatch.');
}
$db->beginTransaction();
$db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
try {
    $rows = $db->table('physical_codes as pc')->join('item_instances as i', 'i.item_id', '=', 'pc.item_id')
        ->join('sku as s', 's.sku_id', '=', 'i.sku_id')->where('pc.carrier_type', 'NFC')
        ->where('pc.hardware_uid', '53AC7BB4A30001')->where('i.serial_number', 'SN-PN-COV-P00051-007')
        ->where('s.sku_code', 'CLMP-DON-S01')->limit(2)
        ->get(['pc.physical_code_id', 'pc.item_id', 'pc.nfc_warehouse_id', 'pc.status',
            'pc.assigned_at', 'pc.created_at', 'pc.updated_at', 'pc.deactivated_at']);
    if ($rows->count() !== 1) {
        throw new RuntimeException('Selected NFC identity is missing or ambiguous.');
    }
    $tag = $rows->first();
    $owner = $db->table('physical_codes')->where('physical_code_id', $tag->physical_code_id)
        ->first(['assigned_by', 'nfc_auth_session_id']);
    $types = [];
    foreach (['physical_codes' => ['assigned_at', 'created_at', 'updated_at', 'deactivated_at'],
        'audit_log' => ['created_at'], 'idempotency_keys' => ['created_at', 'updated_at', 'finished_at']] as $table => $fields) {
        $columns = Schema::getColumnListing($table);
        foreach ($fields as $field) {
            if (in_array($field, $columns, true)) {
                $types[$table][$field] = Schema::getColumnType($table, $field);
            }
        }
    }
    $epochs = [];
    foreach ($types['physical_codes'] as $field => $type) {
        if (in_array($type, ['timestamptz', 'timestamp with time zone'], true)) {
            $epochs[$field] = $db->selectOne('SELECT extract(epoch from '.$field.')::text AS value FROM physical_codes WHERE physical_code_id = ?', [$tag->physical_code_id])->value;
        }
    }
    $receiptQuery = $db->table('idempotency_keys')->where('operation', 'mini_app_nfc_confirm')
        ->where('status', 'done')->whereRaw("CAST(response_body AS jsonb) #>> '{data,nfc,physical_code_id}' = ?", [$tag->physical_code_id]);
    $receiptBefore = (clone $receiptQuery)->selectRaw('md5(COALESCE(string_agg(CAST(response_body AS text), \'\' ORDER BY id), \'\')) AS hash')->value('hash');
    $receipts = (clone $receiptQuery)->select(['id', 'status', 'response_code'])
        ->selectRaw("CAST(response_body AS jsonb) #>> '{meta,request_id}' AS request_id, "
            ."CAST(response_body AS jsonb) #>> '{meta,timestamp}' AS receipt_response_time, "
            ."CAST(response_body AS jsonb) #>> '{data,confirmation,state}' AS confirmation_state, "
            ."CAST(response_body AS jsonb) #>> '{data,nfc,assigned_at}' AS assigned_at, "
            ."CAST(response_body AS jsonb) #>> '{data,nfc,created_at}' AS created_at, "
            ."CAST(response_body AS jsonb) #>> '{data,nfc,updated_at}' AS updated_at, "
            ."CAST(response_body AS jsonb) #>> '{data,nfc,deactivated_at}' AS deactivated_at")
        ->selectRaw("(CAST(response_body AS jsonb) #>> '{data,confirmation,actor_id}' = ? AND CAST(user_id AS text) = ?) AS actor_matches_tag_and_receipt_owner, "
            ."CAST(response_body AS jsonb) #>> '{data,confirmation,auth_session_id}' = ? AS auth_session_matches_tag, "
            ."CAST(response_body AS jsonb) #>> '{data,confirmation,warehouse_id}' = ? AS warehouse_matches_tag, "
            ."CAST(response_body AS jsonb) #>> '{data,confirmation,item_id}' = ? AS item_matches_tag, "
            ."CAST(response_body AS jsonb) #>> '{data,confirmation,physical_code_id}' = ? AS confirmation_matches_tag",
            [$owner->assigned_by, $owner->assigned_by, $owner->nfc_auth_session_id,
                $tag->nfc_warehouse_id, $tag->item_id, $tag->physical_code_id])
        ->orderBy('id')->limit(5)->get();
    $audits = $db->table('audit_log')->where('object_type', 'physical_code')->where('object_id', $tag->physical_code_id)
        ->whereIn('action', ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC'])->select(['id', 'action', 'created_at', 'request_id'])
        ->orderBy('created_at')->limit(10)->get();
    $probe = null;
    if (class_exists(Modules\Warehouse\Support\NfcTimestamp::class)) {
        $writerValue = Modules\Warehouse\Support\NfcTimestamp::write(now());
        $bound = $db->prepareBindings([$writerValue])[0];
        if ($bound !== $writerValue) {
            throw new RuntimeException('NFC binding discarded the offset.');
        }
        $probe = ['contract' => Modules\Warehouse\Support\NfcTimestamp::CONTRACT,
            'writer_value' => $writerValue, 'bound_value' => $bound,
            'assigned_at_serialized' => Modules\Warehouse\Support\NfcTimestamp::read($tag->assigned_at),
            'deactivated_at_serialized' => Modules\Warehouse\Support\NfcTimestamp::read($tag->deactivated_at),
            'historical_receipt_rewritten' => false];
    }
    echo json_encode(['event' => 'nfc_timestamp_source_inspection', 'data' => [
        'kind' => 'SELECT_ONLY_NOT_AUTHENTICATED_HTTP', 'runtime_sha' => config('health.build.sha'),
        'observed_at' => now()->format('Y-m-d\TH:i:s.uP'), 'php_epoch' => microtime(true),
        'app_timezone' => config('app.timezone'), 'php_timezone' => date_default_timezone_get(),
        'db' => $db->selectOne("SELECT current_setting('TimeZone') AS session_timezone, clock_timestamp()::text AS now, extract(epoch from clock_timestamp())::text AS epoch"),
        'selected_identity' => ['uid' => '53AC***0001', 'serial' => 'SN-PN-COV-***-007', 'sku' => 'CLMP-DON-S01'],
        'types' => $types, 'tag' => $tag, 'epochs' => $epochs, 'receipt_timestamp_projection' => $receipts,
        'receipt_bodies_fingerprint' => $receiptBefore, 'audit_timestamp_projection' => $audits,
        'binding_probe_no_write' => ['now_bound' => $db->prepareBindings([now()])[0],
            'aware_bound' => $db->prepareBindings([now()->format('Y-m-d H:i:s.uP')])[0]],
        'nfc_timestamp_probe' => $probe, 'mutations' => [],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    $db->rollBack();
}
