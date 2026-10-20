<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Notification\Models\NotificationRecipient;

// Operator-requested, selected IDs only; never serialize notification content or actor/customer PII.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'wms_hoanam_green'
    || config('health.build.sha') !== '22272d9ea65bf4e52d39d632310eb820a6df65f0') {
    throw new RuntimeException('Timestamp inspection target mismatch.');
}
$db->beginTransaction();
$db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
try {
    $recipientId = '01a116dd-de82-71b3-9095-345ea852dc55';
    $batchId = '22cbebc2-7d31-4642-b7ec-e74195e0eff6';
    $eventId = '5818ba7d-ea57-4431-8f29-aa40ff5bfecc';
    $clocks = $db->selectOne("SELECT current_setting('TimeZone') AS session_timezone, clock_timestamp()::text AS db_now, extract(epoch from clock_timestamp())::text AS db_epoch");
    $recipient = NotificationRecipient::query()->find($recipientId);
    $result = ['runtime_sha' => config('health.build.sha'), 'app_timezone' => config('app.timezone'),
        'php_timezone' => date_default_timezone_get(), 'php_now' => now()->format('Y-m-d\TH:i:s.uP'),
        'php_epoch' => microtime(true), 'db_clock' => $clocks,
        'recipient' => $recipient === null ? null : [
            'id' => $recipientId, 'raw_read_at' => $recipient->getRawOriginal('read_at'),
            'raw_created_at' => $recipient->getRawOriginal('created_at'),
            'cast_read_at' => $recipient->read_at?->format('Y-m-d\TH:i:s.uP'),
            'owner_read_at' => $recipient->read_at?->toIso8601String(),
            'read_at_epoch' => $db->selectOne('SELECT extract(epoch from read_at)::text AS value FROM notification_recipients WHERE id = ?', [$recipientId])->value,
        ], 'sources' => []];
    foreach ([['notification_recipients', 'id', $recipientId], ['notifications', 'id', $recipient?->notification_id],
        ['scan_events', 'id', $eventId], ['scan_record_events', 'scan_event_id', $eventId],
        ['scan_batch', 'scan_batch_id', $batchId], ['scan_batches', 'scan_batch_id', $batchId]] as [$table, $key, $id]) {
        $columns = Schema::getColumnListing($table);
        if (! in_array($key, $columns, true)) {
            $result['sources'][$table] = ['status' => 'UNAVAILABLE'];

            continue;
        }
        $fields = array_values(array_intersect($columns, ['created_at', 'updated_at', 'occurred_at', 'posted_at', 'started_at', 'ended_at', 'read_at']));
        $types = [];
        foreach ($fields as $field) {
            $types[$field] = Schema::getColumnType($table, $field);
        }
        $select = array_merge([$key], array_values(array_intersect($columns, ['scan_batch_id', 'warehouse_id'])), $fields);
        $row = $id === null ? null : $db->table($table)->where($key, $id)->first(array_unique($select));
        $result['sources'][$table] = ['types' => $types, 'row' => $row];
    }
    $result['binding_probe_no_write'] = ['format' => $db->getQueryGrammar()->getDateFormat(),
        'now_bound' => $db->prepareBindings([now()])[0],
        'aware_now_bound' => $db->prepareBindings([now()->format('Y-m-d H:i:s.uP')])[0]];
    echo json_encode(['event' => 'timestamp_source_inspection', 'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    $db->rollBack();
}
