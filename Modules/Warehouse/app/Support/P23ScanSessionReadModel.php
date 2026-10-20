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
                ? (in_array($field, ['created_at', 'started_at', 'ended_at'], true)
                    ? HistoryTimestamp::expression($table, 'b.'.$field) : 'CAST(b.'.$field.' AS VARCHAR(250))')
                : (in_array($field, ['created_at', 'started_at', 'ended_at'], true) ? 'NULL' : 'CAST(NULL AS VARCHAR(250))');
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
        $expression = HistoryTimestamp::expression($table, 'b.created_at');

        return DB::getDriverName() === 'sqlite' ? [$expression, []]
            : ["TO_CHAR($expression, 'YYYY-MM-DD HH24:MI:SS.US')", []];
    }

    public static function dateKey(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** One bounded grouped query, not a request per batch/tag or a page count. */
    public function counts(array $scope, array $ids, string $asOf, array $sources): array
    {
        $availability = array_column($sources, 'eventStatus');
        if (! in_array('INCLUDED', $availability, true)) {
            return array_fill_keys($ids, P23ScanSessionPolicy::counts(null));
        }
        [$q] = app(P08HistoryReadModel::class)->build($scope, 'SCAN');
        $q->whereIn('owner_id', $ids)->where(fn (Builder $time): Builder => $time->whereNull('event_time')->orWhere('event_time', '<=', HistoryTimestamp::key($asOf)));
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
        return $this->serializeWithMetadata($row);
    }

    public function serializeWithMetadata(object $row, ?array $metadata = null): array
    {
        $metadata ??= [];
        foreach (['created_at', 'started_at', 'ended_at'] as $field) {
            $row->{$field} = HistoryTimestamp::utc($row->{$field});
        }
        $latest = $metadata['latestEvent'] ?? null;
        $sourceLink = $row->source_id === null ? [] : [[
            'source_type' => $row->source_type,
            'source_id' => $row->source_id,
            'mapping_status' => 'PERSISTED_BATCH_SOURCE_REFERENCE',
            'url' => null,
        ]];

        return ['sessionId' => $row->session_id, 'sessionType' => 'SCAN_BATCH',
            'warehouseId' => $row->warehouse_id, 'sourceTable' => $row->source_table,
            'batchNo' => $row->batch_no, 'batchNoStatus' => $row->batch_no === null ? 'NOT_RECORDED' : 'SOURCE_VALUE',
            'sourceType' => $row->source_type, 'status' => $row->status,
            'statusStatus' => $row->status === null ? 'NOT_RECORDED' : 'SOURCE_VALUE',
            'createdAt' => $row->created_at, 'createdAtSource' => $row->source_table.'.created_at',
            'startedAt' => $row->started_at, 'endedAt' => $row->ended_at,
            'timePolicy' => 'CREATED_AT_IS_NOT_STARTED_AT_OR_ENDED_AT',
            'session' => ['start' => ['value' => $row->started_at, 'status' => $row->started_at === null ? 'NOT_RECORDED' : 'SOURCE_VALUE'],
                'end' => ['value' => $row->ended_at, 'status' => $row->ended_at === null ? 'NOT_RECORDED' : 'SOURCE_VALUE'],
                'status' => ['value' => $row->status, 'status' => $row->status === null ? 'NOT_RECORDED' : 'SOURCE_VALUE'],
                'source' => $row->source_table],
            'actor' => ['userId' => $row->created_by, 'displayName' => null,
                'source' => $row->source_table.'.created_by', 'role' => 'BATCH_CREATOR_NOT_EVENT_ACTOR',
                'displayNameStatus' => 'NOT_PERSISTED_AS_HISTORY_SNAPSHOT'],
            'latestEvent' => $latest,
            'latestEventStatus' => $metadata['sourceStatus'] ?? 'SOURCE_UNAVAILABLE',
            'businessLinks' => $sourceLink,
            'businessLinksStatus' => $row->source_id === null ? 'NOT_RECORDED' : 'PERSISTED_SOURCE_REFERENCE_ONLY',
            'issuedQuantity' => null,
            'issuedQuantityStatus' => 'NO_AUTHORITATIVE_BATCH_POSTING_QUANTITY',
            'isConfirmationReceipt' => false,
            'detail' => ['canOpen' => true, 'url' => '/api/v1/mini-app/history/scan-sessions/'.$row->session_id,
                'requiredQuery' => ['warehouse_id' => $row->warehouse_id], 'ownerPermissionRechecked' => true]];
    }

    /**
     * Read the complete persisted event source for the selected session IDs.
     * This is deliberately separate from page rows: latestEvent never means the
     * last event visible on the current page.
     *
     * @return array<string, array{latestEvent: ?array, sourceStatus: string}>
     */
    public function latestEvents(array $scope, array $ids, string $asOf, array $sources): array
    {
        $result = array_fill_keys($ids, ['latestEvent' => null, 'sourceStatus' => 'SOURCE_UNAVAILABLE']);
        $candidates = [];
        foreach (self::ADAPTERS as $rank => [$batches, $events, $eventId]) {
            if (($sources[$batches]['eventStatus'] ?? 'UNAVAILABLE') !== 'INCLUDED') {
                continue;
            }
            $eventColumns = $this->columns($events);
            $optional = static function (string $alias, string $field, array $columns, string $cast = 'VARCHAR(250)'): string {
                return in_array($field, $columns, true) ? "CAST({$alias}.{$field} AS {$cast})" : "CAST(NULL AS {$cast})";
            };
            $raw = $optional('e', 'raw_code', $eventColumns);
            $reasonField = in_array('reason', $eventColumns, true) ? 'reason' : (in_array('message', $eventColumns, true) ? 'message' : null);
            $reason = $reasonField === null ? 'CAST(NULL AS VARCHAR(1000))' : "CAST(e.{$reasonField} AS VARCHAR(1000))";
            $actor = $optional('e', 'actor_id', $eventColumns);
            $serial = $optional('e', 'serial_number', $eventColumns);
            $sku = $optional('e', 'sku_code', $eventColumns);
            $quantity = in_array('issued_quantity', $eventColumns, true) ? 'CAST(e.issued_quantity AS DECIMAL(18,3))' : 'CAST(NULL AS DECIMAL(18,3))';
            $eventType = $optional('e', 'event_type', $eventColumns);
            $eventTime = HistoryTimestamp::expression($events, 'e.created_at');
            $q = DB::table($events.' as e')->join($batches.' as b', 'b.scan_batch_id', '=', 'e.scan_batch_id')
                ->where('b.warehouse_id', $scope['warehouse_id'])->whereIn('e.scan_batch_id', $ids)
                ->where(fn (Builder $time): Builder => $time->whereNull('e.created_at')->orWhereRaw($eventTime.' <= ?', [HistoryTimestamp::key($asOf)]));
            P09WarrantyScope::isolate($q, $events, 'e', $scope);
            P09WarrantyScope::isolate($q, $batches, 'b', $scope);
            $rows = $q->get([
                DB::raw('CAST(e.'.$eventId.' AS VARCHAR(150)) AS event_id'), 'e.scan_batch_id',
                DB::raw($raw.' AS raw_code'), DB::raw($reason.' AS reason'),
                DB::raw($actor.' AS actor_id'), DB::raw($serial.' AS serial_number'),
                DB::raw($sku.' AS sku_code'), DB::raw($quantity.' AS issued_quantity'),
                DB::raw($eventType.' AS event_type'), 'e.result_status', DB::raw($eventTime.' AS created_at'),
                DB::raw("'{$events}' AS source_table"), DB::raw((string) $rank.' AS source_rank'),
                DB::raw('CAST(b.source_type AS VARCHAR(100)) AS source_type'),
                DB::raw('CAST(b.source_id AS VARCHAR(150)) AS source_id'),
            ]);
            foreach ($rows as $row) {
                $candidates[(string) $row->scan_batch_id][] = $row;
            }
        }

        $hasIncluded = false;
        $partial = false;
        foreach ($sources as $source) {
            if (($source['eventStatus'] ?? 'UNAVAILABLE') === 'UNAVAILABLE') {
                $partial = true;
            }
            if (($source['eventStatus'] ?? 'UNAVAILABLE') === 'INCLUDED') {
                $hasIncluded = true;
            }
        }
        foreach ($ids as $id) {
            if (! $hasIncluded) {
                continue;
            }
            $dedup = [];
            foreach ($candidates[$id] ?? [] as $row) {
                $key = (string) $row->event_id;
                if (! isset($dedup[$key]) || (int) $row->source_rank < (int) $dedup[$key]->source_rank) {
                    $dedup[$key] = $row;
                }
            }
            $events = array_values($dedup);
            usort($events, static function (object $a, object $b): int {
                if ($a->created_at === null && $b->created_at !== null) {
                    return 1;
                }
                if ($a->created_at !== null && $b->created_at === null) {
                    return -1;
                }
                $time = strcmp((string) $b->created_at, (string) $a->created_at);

                return $time !== 0 ? $time : strcmp((string) $b->event_id, (string) $a->event_id);
            });
            $latest = $events[0] ?? null;
            $result[$id] = ['sourceStatus' => $partial ? 'PARTIAL_SOURCE' : 'AVAILABLE_RETAINED_EVENTS', 'latestEvent' => $latest === null ? null : [
                'eventId' => (string) $latest->event_id,
                'eventType' => $latest->event_type,
                'eventTypeStatus' => $latest->event_type === null ? 'NOT_RECORDED' : 'SOURCE_VALUE',
                'status' => $latest->result_status,
                'rawCode' => $latest->raw_code,
                'reason' => $latest->reason,
                'serial' => $latest->serial_number,
                'sku' => $latest->sku_code,
                'issuedQuantity' => $latest->issued_quantity,
                'actorId' => $latest->actor_id,
                'actorIdStatus' => $latest->actor_id === null ? 'NOT_RECORDED' : 'PERSISTED_EVENT_ACTOR',
                'displayName' => null,
                'displayNameStatus' => 'NOT_PERSISTED_AS_HISTORY_SNAPSHOT',
                'occurredAt' => HistoryTimestamp::utc($latest->created_at),
                'sourceTable' => $latest->source_table,
                'businessLink' => $latest->source_id === null ? null : ['sourceType' => $latest->source_type,
                    'sourceId' => $latest->source_id, 'mappingStatus' => 'PERSISTED_BATCH_SOURCE_REFERENCE', 'url' => null],
            ]];
        }

        return $result;
    }

    /** Extension of the existing P08 detail, within the same read snapshot. */
    public function detail(array $scope, string $id, array $counts, array $eventSource): array
    {
        [$q, $sources] = $this->build($scope);
        $row = $q->where('session_id', $id)->first();
        $complete = ($eventSource['status'] ?? null) === 'INCLUDED';
        $latest = $this->latestEvents($scope, [$id], HistoryTimestamp::utc(now()), $sources);
        // P08 retains its redacted projection even when P23 metadata is embedded.
        if (isset($latest[$id]['latestEvent'])) {
            $latest[$id]['latestEvent']['rawCode'] = null;
            $latest[$id]['latestEvent']['reason'] = null;
            $latest[$id]['latestEvent']['rawCodePolicy'] = 'OMITTED_MAY_CONTAIN_PII_OR_SECRETS';
        }

        return ['contract' => P23ScanSessionPolicy::CONTRACT, 'batch' => $row === null ? null : $this->serializeWithMetadata($row, $latest[$id] ?? []),
            'batchSources' => $sources, 'counts' => P23ScanSessionPolicy::counts($complete ? $counts : null),
            'availableCounts' => P23ScanSessionPolicy::counts($counts),
            'eventProjectionStatus' => 'P23_PERSISTED_METADATA_V3_NO_CURRENT_LOOKUP_FALLBACK',
            'retentionStatus' => 'NOT_PUBLISHED', 'versionStatus' => 'NO_PERSISTED_BATCH_VERSION'];
    }
}
