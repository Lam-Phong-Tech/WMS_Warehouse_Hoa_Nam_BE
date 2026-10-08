<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\Models\AuditLog;

/** SQL projections only. No raw scan strings, audit JSON, notes, URLs or credentials. */
final class P08HistoryReadModel
{
    private array $columns = [];

    private function columns(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }

    private function supports(string $table, array $columns): bool
    {
        return array_diff($columns, $this->columns($table)) === [];
    }

    /** Restrict any explicit tenant/org discriminator in addition to warehouse ownership. */
    private function isolate(Builder $query, string $table, string $alias, array $scope): Builder
    {
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            if (in_array($field, $this->columns($table), true)) {
                if (empty($scope['dimensions'][$field])) {
                    ApiError::abort(503, 'HISTORY_TENANCY_UNRESOLVED', 'Chưa xác định được phạm vi tổ chức của nguồn lịch sử.');
                }
                $query->where($alias.'.'.$field, $scope['dimensions'][$field]);
            }
        }

        return $query;
    }

    /** Common column order and explicit text casts make PostgreSQL/SQLite unions compatible. */
    private function project(Builder $q, string $source, string $table, string $id, string $owner, string $time, string $status, string $action, string $group, string $label = 'NULL', string $code = 'NULL', int $priority = 0): Builder
    {
        return $q->selectRaw("'{$source}' AS source, '{$table}' AS source_table, '{$source}:' || CAST({$id} AS VARCHAR(150)) AS activity_id, '{$source}:' || CAST({$owner} AS VARCHAR(150)) AS record_id, CAST({$id} AS VARCHAR(150)) AS event_id, CAST({$owner} AS VARCHAR(150)) AS owner_id, {$time} AS event_time, COALESCE(CAST({$time} AS VARCHAR(60)), '') AS sort_time, CAST({$status} AS VARCHAR(100)) AS status, CAST({$action} AS VARCHAR(100)) AS action, {$group} AS business, CAST({$label} AS VARCHAR(250)) AS label, CAST({$code} AS VARCHAR(150)) AS code_id, {$priority} AS source_rank");
    }

    /** Scope and source permissions are applied BEFORE union/dedup/count/pagination. */
    public function build(array $scope, string $requested = 'ALL'): array
    {
        $queries = [];
        $sources = [];
        foreach (P08HistoryPolicy::SOURCES as $source) {
            if (! $scope['rights'][$source]) {
                $sources[$source] = ['status' => 'FORBIDDEN'];
                if ($requested === $source) {
                    ApiError::abort(403, 'HISTORY_SOURCE_FORBIDDEN', 'Không có quyền đọc nguồn lịch sử này.');
                }
                continue;
            }
            if ($requested !== 'ALL' && $requested !== $source) {
                $sources[$source] = ['status' => 'NOT_REQUESTED'];
                continue;
            }
            $parts = match ($source) {
                'DOCUMENT' => $this->documents($scope),
                'WARRANTY' => $this->warranty($scope),
                'NFC' => $this->nfc($scope),
                'SCAN' => $this->scans($scope),
            };
            $sources[$source] = ['status' => $parts === [] ? 'UNAVAILABLE' : 'INCLUDED'];
            if ($source === 'SCAN') {
                $sources[$source]['adapters'] = $this->scanAvailability();
                if ($parts !== [] && in_array('UNAVAILABLE', $sources[$source]['adapters'], true)) {
                    $sources[$source]['status'] = 'PARTIAL';
                }
            }
            if ($requested === $source && $parts === []) {
                ApiError::abort(503, 'HISTORY_SOURCE_UNAVAILABLE', 'Nguồn lịch sử chưa có schema/phạm vi được xác nhận.');
            }
            array_push($queries, ...$parts);
        }
        if ($queries === []) {
            ApiError::abort(in_array(true, $scope['rights'], true) ? 503 : 403, 'HISTORY_NO_READABLE_SOURCE', 'Không có nguồn lịch sử khả dụng trong phạm vi được cấp.');
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        // Mirror views may expose the same scan event twice. One immutable activityId
        // survives, with canonical scan_events taking precedence over the legacy store.
        $ranked = DB::query()->fromSub($union, 'p08_union')->selectRaw('p08_union.*, ROW_NUMBER() OVER (PARTITION BY activity_id ORDER BY source_rank, source_table) AS p08_rank');

        return [DB::query()->fromSub($ranked, 'h')->where('p08_rank', 1), $sources];
    }

    private function documents(array $s): array
    {
        if (! $this->supports('document_status_history', ['id', 'stock_document_id', 'created_at', 'to_status', 'action'])
            || ! $this->supports('stock_documents', ['id', 'warehouse_id', 'doc_type', 'doc_no'])) {
            return [];
        }
        $types = array_keys(array_filter($s['documentRights']));
        $q = DB::table('document_status_history as e')->join('stock_documents as d', 'd.id', '=', 'e.stock_document_id')
            ->where('d.warehouse_id', $s['warehouse_id'])->whereIn('d.doc_type', $types);
        $this->isolate($q, 'document_status_history', 'e', $s);
        $this->isolate($q, 'stock_documents', 'd', $s);

        return [$this->project($q, 'DOCUMENT', 'document_status_history', 'e.id', 'e.stock_document_id', 'e.created_at', 'e.to_status', 'e.action', "CASE WHEN d.doc_type = 'receipt' THEN 'INBOUND' WHEN d.doc_type = 'issue' THEN 'OUTBOUND' ELSE 'OTHER' END", 'd.doc_no')];
    }

    private function warranty(array $s): array
    {
        $owner = WarrantyWarehouseScope::TABLE;
        if (! $this->supports('warranty_event', ['warranty_event_id', 'warranty_case_id', 'event_at', 'event_type', 'to_status'])
            || ! $this->supports('warranty_case', ['warranty_case_id', 'warranty_case_code'])
            || ! $this->supports($owner, ['warranty_case_id', 'warehouse_id'])) {
            return [];
        }
        $q = DB::table('warranty_event as e')->join('warranty_case as c', 'c.warranty_case_id', '=', 'e.warranty_case_id')
            ->whereExists(function (Builder $o) use ($s, $owner): void {
                $o->selectRaw('1')->from($owner.' as o')->whereColumn('o.warranty_case_id', 'c.warranty_case_id')->where('o.warehouse_id', $s['warehouse_id']);
                $this->isolate($o, $owner, 'o', $s);
            });
        $this->isolate($q, 'warranty_event', 'e', $s);
        $this->isolate($q, 'warranty_case', 'c', $s);

        return [$this->project($q, 'WARRANTY', 'warranty_event', 'e.warranty_event_id', 'e.warranty_case_id', 'e.event_at', 'e.to_status', 'e.event_type', "'WARRANTY'", 'c.warranty_case_code')];
    }

    private function nfc(array $s): array
    {
        $audit = (new AuditLog)->getTable();
        if (! $this->supports($audit, ['id', 'object_id', 'object_type', 'action', 'result', 'created_at'])
            || ! $this->supports('physical_codes', ['physical_code_id', 'carrier_type', 'nfc_warehouse_id'])) {
            return [];
        }
        // No inferred NFC warehouse from current user, SKU or location. Unproven
        // legacy assignments are excluded and the coverage limitation is reported.
        $q = DB::table($audit.' as e')->join('physical_codes as pc', DB::raw('CAST(pc.physical_code_id AS VARCHAR(150))'), '=', DB::raw('CAST(e.object_id AS VARCHAR(150))'))
            ->where('e.object_type', 'physical_code')->whereIn('e.action', ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC'])
            ->where('pc.carrier_type', 'NFC')->where('pc.nfc_warehouse_id', $s['warehouse_id']);
        $this->isolate($q, $audit, 'e', $s);
        $this->isolate($q, 'physical_codes', 'pc', $s);

        return [$this->project($q, 'NFC', $audit, 'e.id', 'pc.physical_code_id', 'e.created_at', 'e.result', 'e.action', "'NFC'", 'NULL', 'pc.physical_code_id')];
    }

    private function scanAvailability(): array
    {
        $result = [];
        foreach ([['scan_events', 'scan_batch', 'id'], ['scan_record_events', 'scan_batches', 'scan_event_id']] as [$events, $batches, $id]) {
            $result[$events] = $this->supports($events, [$id, 'scan_batch_id', 'result_status', 'created_at', 'physical_code_id'])
                && $this->supports($batches, ['scan_batch_id', 'warehouse_id']) ? 'INCLUDED' : 'UNAVAILABLE';
        }

        return $result;
    }

    private function scans(array $s): array
    {
        $queries = [];
        $availability = $this->scanAvailability();
        foreach ([['scan_events', 'scan_batch', 'id', 0], ['scan_record_events', 'scan_batches', 'scan_event_id', 1]] as [$events, $batches, $id, $priority]) {
            if ($availability[$events] !== 'INCLUDED') {
                continue;
            }
            $q = DB::table($events.' as e')->join($batches.' as b', 'b.scan_batch_id', '=', 'e.scan_batch_id')->where('b.warehouse_id', $s['warehouse_id']);
            $this->isolate($q, $events, 'e', $s);
            $this->isolate($q, $batches, 'b', $s);
            $queries[] = $this->project($q, 'SCAN', $events, 'e.'.$id, 'e.scan_batch_id', 'e.created_at', 'e.result_status', "'SCAN'", "'SCAN'", 'NULL', 'e.physical_code_id', $priority);
        }

        return $queries;
    }

    /** Scan session identity is a persisted batch ID, NEVER the auth-session ID. */
    public function requireScanSession(array $scope, string $id): array
    {
        if (! $scope['rights']['SCAN']) {
            ApiError::abort(403, 'HISTORY_SOURCE_FORBIDDEN', 'Không có quyền đọc phiên quét.');
        }
        $found = [];
        foreach (['scan_batch', 'scan_batches'] as $table) {
            if (! $this->supports($table, ['scan_batch_id', 'warehouse_id'])) {
                continue;
            }
            $q = DB::table($table.' as b')->where('b.scan_batch_id', $id)->where('b.warehouse_id', $scope['warehouse_id']);
            $this->isolate($q, $table, 'b', $scope);
            if ($q->exists()) {
                $found[] = $table;
            }
        }
        if ($found === []) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiên quét trong phạm vi kho.');
        }

        return $found;
    }
}
