<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Batch-driven query: zero-event batches survive. No current SKU/code lookups. */
final class P23ScanSessionReadModel
{
    private const ADAPTERS = [
        ['scan_batch', 'scan_events', 'id'],
        ['scan_batches', 'scan_record_events', 'scan_event_id'],
    ];

    private function columns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    public function sources(): array
    {
        $sources = [];
        foreach (self::ADAPTERS as [$batches, $events, $eventId]) {
            $columns = $this->columns($batches);
            $batchReady = array_diff(['scan_batch_id', 'warehouse_id'], $columns) === [];
            $eventReady = $batchReady && array_diff([$eventId, 'scan_batch_id', 'result_status', 'created_at', 'physical_code_id'], $this->columns($events)) === [];
            $sources[$batches] = ['status' => $batchReady ? 'INCLUDED' : 'UNAVAILABLE',
                'eventSource' => $events, 'eventStatus' => $eventReady ? 'INCLUDED' : 'UNAVAILABLE',
                'fields' => array_fill_keys(array_values(array_intersect($columns,
                    ['created_at', 'created_by', 'source_type', 'source_id', 'batch_no', 'started_at', 'ended_at', 'status'])), true)];
        }
        return $sources;
    }

    /** Scope BEFORE dedup; filters and pagination AFTER canonical precedence. */
    public function build(array $scope): array
    {
        if (! ($scope['rights']['SCAN'] ?? false)) {
            ApiError::abort(403, 'HISTORY_SOURCE_FORBIDDEN', 'Không có quyền đọc phiên quét.');
        }
        $sources = $this->sources();
        $parts = [];
        foreach (self::ADAPTERS as $rank => [$table]) {
            if ($sources[$table]['status'] !== 'INCLUDED') {
                continue;
            }
            $columns = $this->columns($table);
            $q = DB::table($table.' as b')->where('b.warehouse_id', $scope['warehouse_id']);
            P09WarrantyScope::isolate($q, $table, 'b', $scope);
            $optional = fn (string $field): string => in_array($field, $columns, true)
                ? 'CAST(b.'.$field.' AS VARCHAR(250))' : 'CAST(NULL AS VARCHAR(250))';
            [$sort, $bindings] = $this->sortExpression($table, $columns);
            $q->selectRaw("CAST(b.scan_batch_id AS VARCHAR(150)) AS session_id, CAST(b.warehouse_id AS VARCHAR(150)) AS warehouse_id, '{$table}' AS source_table, {$rank} AS source_rank, "
                .$optional('created_at').' AS created_at, '.$optional('created_by').' AS created_by, '
                .$optional('source_type').' AS source_type, '.$optional('source_id').' AS source_id, '
                .$optional('batch_no').' AS batch_no, '.$optional('started_at').' AS started_at, '
                .$optional('ended_at').' AS ended_at, '.$optional('status').' AS status, '.$sort.' AS sort_time', $bindings);
            $parts[] = $q;
        }
        if ($parts === []) {
            ApiError::abort(503, 'P23_BATCH_SOURCE_UNAVAILABLE', 'Nguồn phiên quét chưa có schema/phạm vi được xác nhận.');
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }
        $ranked = DB::query()->fromSub($union, 'p23_union')->selectRaw('p23_union.*, ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY source_rank, source_table) AS p23_rank');
        return [DB::query()->fromSub($ranked, 's')->where('p23_rank', 1), $sources];
    }

    private function sortExpression(string $table, array $columns): array
    {
        if (! in_array('created_at', $columns, true)) {
            return ['CAST(NULL AS VARCHAR(60))', []];
        }
        if (DB::getDriverName() === 'sqlite') {
            // Canonical SQLite writer uses local wall-clock strings. Normalize
            // fractions without losing microseconds or pretending to know UTC.
            return ["CASE WHEN b.created_at IS NULL THEN NULL WHEN INSTR(CAST(b.created_at AS TEXT), '.') = 0 THEN CAST(b.created_at AS TEXT) || '.000000' ELSE SUBSTR(CAST(b.created_at AS TEXT) || '000000', 1, 26) END", []];
        }
        $storage = P23WarrantyDateFilter::storageFor($table, 'created_at');
        if ($storage === 'TIMESTAMP_WITH_TIME_ZONE') {
            return ["TO_CHAR(b.created_at AT TIME ZONE ?, 'YYYY-MM-DD HH24:MI:SS.US')", ['UTC']];
        }
        return ["TO_CHAR((b.created_at AT TIME ZONE ?) AT TIME ZONE ?, 'YYYY-MM-DD HH24:MI:SS.US')", [config('app.timezone'), 'UTC']];
    }

    public static function dateKey(DateTimeImmutable $time): string
    {
        return (DB::getDriverName() === 'pgsql' ? $time->setTimezone(new DateTimeZone('UTC')) : $time)->format('Y-m-d H:i:s.u');
    }

    /** One bounded grouped query, not a request per batch/tag or a page count. */
    public function counts(array $scope, array $ids, string $asOf, array $sources): array
    {
        $availability = array_column($sources, 'eventStatus');
        if (! in_array('INCLUDED', $availability, true)) {
            return array_fill_keys($ids, P23ScanSessionPolicy::counts(null));
        }
        [$q] = app(P08HistoryReadModel::class)->build($scope, 'SCAN');
        $q->whereIn('owner_id', $ids)->where(fn (Builder $time): Builder => $time->whereNull('event_time')->orWhere('event_time', '<=', $asOf));
        $rows = $q->selectRaw("owner_id, COALESCE(status, 'UNKNOWN') AS state, COUNT(*) AS n")
            ->groupBy('owner_id')->groupByRaw("COALESCE(status, 'UNKNOWN')")->get();
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->owner_id][$row->state] = (int) $row->n;
        }
        $complete = ! in_array('UNAVAILABLE', $availability, true);
        $result = [];
        foreach ($ids as $id) {
            $available = $grouped[$id] ?? [];
            $result[$id] = P23ScanSessionPolicy::counts($complete ? $available : null);
            if (! $complete) {
                $result[$id]['countStatus'] = 'PARTIAL_SOURCE';
                $result[$id]['availableCounts'] = P23ScanSessionPolicy::counts($available);
            }
        }
        return $result;
    }

    public function serialize(object $row): array
    {
        return ['sessionId' => $row->session_id, 'sessionType' => 'SCAN_BATCH',
            'warehouseId' => $row->warehouse_id, 'sourceTable' => $row->source_table,
            'batchNo' => $row->batch_no, 'batchNoStatus' => $row->batch_no === null ? 'NOT_RECORDED' : 'SOURCE_VALUE',
            'sourceType' => $row->source_type, 'status' => $row->status,
            'statusStatus' => $row->status === null ? 'NOT_RECORDED' : 'SOURCE_VALUE',
            'createdAt' => $row->created_at, 'createdAtSource' => $row->source_table.'.created_at',
            'startedAt' => $row->started_at, 'endedAt' => $row->ended_at,
            'timePolicy' => 'CREATED_AT_IS_NOT_STARTED_AT_OR_ENDED_AT',
            'actor' => ['userId' => $row->created_by, 'displayName' => null,
                'source' => $row->source_table.'.created_by', 'role' => 'BATCH_CREATOR_NOT_EVENT_ACTOR',
                'displayNameStatus' => 'NOT_RECORDED_IN_BATCH'],
            // No source ID leakage or inferred target URL before target mapping/ACL.
            'businessLinks' => [], 'businessLinksStatus' => $row->source_id === null ? 'NOT_RECORDED' : 'TARGET_MAPPING_NOT_APPROVED',
            'isConfirmationReceipt' => false,
            'detail' => ['canOpen' => true, 'url' => '/api/v1/mini-app/history/scan-sessions/'.$row->session_id,
                'requiredQuery' => ['warehouse_id' => $row->warehouse_id], 'ownerPermissionRechecked' => true]];
    }

    /** Extension of the existing P08 detail, within the same read snapshot. */
    public function detail(array $scope, string $id, array $counts, array $eventSource): array
    {
        [$q, $sources] = $this->build($scope);
        $row = $q->where('session_id', $id)->first();
        $complete = ($eventSource['status'] ?? null) === 'INCLUDED';
        return ['contract' => P23ScanSessionPolicy::CONTRACT, 'batch' => $row === null ? null : $this->serialize($row),
            'batchSources' => $sources, 'counts' => P23ScanSessionPolicy::counts($complete ? $counts : null),
            'availableCounts' => P23ScanSessionPolicy::counts($counts),
            'eventProjectionStatus' => 'P08_SAFE_EVENTS_ONLY_RAW_REASON_ACTOR_SKU_QUANTITY_NOT_APPROVED',
            'retentionStatus' => 'NOT_PUBLISHED', 'versionStatus' => 'NO_PERSISTED_BATCH_VERSION'];
    }
}
