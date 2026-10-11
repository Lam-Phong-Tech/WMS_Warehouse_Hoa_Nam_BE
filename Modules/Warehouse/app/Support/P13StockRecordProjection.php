<?php

namespace Modules\Warehouse\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Warehouse\Services\MiniAppDraftService;

/** Scoped, batched read projection. Never emits notifications, receipts or stock writes. */
final class P13StockRecordProjection
{
    public const CONTRACT = 'p13-record-handoff-v1';

    public static function decorate(Request $request, array $body, string $source, string $mode): array
    {
        if (! in_array($source, ['INBOUND', 'OUTBOUND'], true) || ! in_array($mode, ['LIST', 'DETAIL'], true)) {
            return $body;
        }
        $rows = $mode === 'LIST' ? $body['data'] : [$body['data']];
        $warehouseField = $source === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id';
        $actor = $request->user();
        $session = $actor instanceof User && $actor->status === 'ACTIVE'
            ? app(StableAuthSession::class)->id($actor) : null;
        $identities = [];
        $allowedWarehouses = [];
        foreach ($rows as $row) {
            $id = self::uuid($row['id'] ?? null);
            $warehouse = self::uuid($row[$warehouseField] ?? null);
            if ($id !== null && $warehouse !== null && $actor instanceof User) {
                $allowedWarehouses[$warehouse] ??= $actor->canAccessWarehouse($warehouse);
                if ($allowedWarehouses[$warehouse]) {
                    $identities[$id] = $warehouse;
                }
            }
        }
        $status = $session === null ? 'NO_ACTIVE_AUTH_SESSION' : 'NOT_ESTABLISHED';
        $evidence = [];
        if ($session !== null && $identities !== []) {
            try {
                if (! Schema::hasTable(MiniAppDraftService::TABLE) || ! Schema::hasTable(MiniAppDraftService::RECEIPTS)) {
                    $status = 'SOURCE_UNAVAILABLE';
                } else {
                    $records = DB::table(MiniAppDraftService::TABLE.' as d')
                        ->join(MiniAppDraftService::RECEIPTS.' as r', function (JoinClause $join): void {
                            foreach (['document_id', 'actor_id', 'auth_session_id', 'warehouse_id', 'operation', 'scan_session_id'] as $key) {
                                $join->on('r.'.$key, '=', 'd.'.$key);
                            }
                        })
                        ->where('d.actor_id', $actor->user_id)->where('d.auth_session_id', $session)
                        ->where('d.operation', $source)->where('d.status', 'RECORDED')
                        ->whereIn('d.business_document_id', array_keys($identities))
                        ->whereIn('d.warehouse_id', array_values(array_unique($identities)))
                        ->where('r.command', 'RECORD')->whereBetween('r.response_status', [200, 299])
                        ->orderByDesc('r.completed_at')->orderByDesc('r.request_id')
                        ->get(['r.*', 'd.business_document_id']);
                    foreach ($records as $record) {
                        $id = (string) $record->business_document_id;
                        if (($identities[$id] ?? null) !== $record->warehouse_id || isset($evidence[$id])) {
                            continue;
                        }
                        $confirmed = self::verifyReceipt($record, $source);
                        if ($confirmed !== null) {
                            $evidence[$id] = $confirmed;
                        }
                    }
                }
            } catch (QueryException) {
                // The authorized parent remains readable; unavailable evidence is not empty or failed RECORD.
                $status = 'SOURCE_UNAVAILABLE';
                $evidence = [];
            }
        }
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            $id = self::uuid($row['id'] ?? null);
            $sent = $id === null ? null : ($evidence[$id] ?? null);
            $row['p13'] = [
                'contract' => self::CONTRACT,
                'evidence_status' => $sent === null ? $status : 'CONFIRMED_RECORD_RECEIPT',
                'record_handoff' => $sent,
                'owner_snapshot_status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'owner_snapshot_posted_at' => $row['posted_at'] ?? null,
                'current_waiting_for_web' => null,
                'current_waiting_semantics' => 'NOT_INFERRED_FROM_HISTORICAL_RECEIPT_OR_READINESS',
                'recovery_owner' => $source === 'INBOUND' ? 'P04_P03' : 'P05_P03',
                'write_operations' => [],
            ];
        }
        unset($row);
        $body['data'] = $mode === 'LIST' ? $rows : $rows[0];
        $body['meta']['p13'] = [
            'contract' => self::CONTRACT,
            'receipt_scope' => 'CURRENT_ACTOR_AUTH_SESSION_AND_PERSISTED_DOCUMENT_WAREHOUSE',
            'receipt_selection' => 'LATEST_VERIFIED_RECORD_BY_COMPLETED_AT_THEN_REQUEST_ID',
            'count_unit' => 'STOCK_DOCUMENT',
            'all_sources_aggregate' => false,
            'quantity_summary_source' => 'OWNER_SCAN_ENTRIES_META_SUMMARY_NOT_PAGE_ITEMS',
        ];

        return $body;
    }

    private static function verifyReceipt(object $record, string $source): ?array
    {
        try {
            $body = json_decode($record->response_body, true, 64, JSON_THROW_ON_ERROR);
            $receipt = data_get($body, 'meta.p03_draft.receipt');
            $draft = data_get($body, 'meta.p03_draft.document');
            if (! is_array($receipt) || ! is_array($draft) || ($body['success'] ?? null) !== true
                || ($receipt['outcome'] ?? null) !== 'SUCCEEDED' || ($receipt['command'] ?? null) !== 'RECORD'
                || ($draft['status'] ?? null) !== 'RECORDED' || ($draft['auth_session_id'] ?? null) !== $record->auth_session_id
                || ($draft['business_document_id'] ?? null) !== $record->business_document_id
                || data_get($body, 'data.document_id') !== $record->business_document_id
                || data_get($body, 'data.posted') !== false || data_get($body, 'data.stock_effect') !== 'NONE') {
                return null;
            }
            foreach (['request_id', 'document_id', 'scan_session_id', 'warehouse_id', 'operation'] as $key) {
                if (($receipt[$key] ?? null) !== $record->{$key}) {
                    return null;
                }
            }
            foreach (['document_id', 'scan_session_id', 'warehouse_id', 'operation'] as $key) {
                if (($draft[$key] ?? null) !== $record->{$key}) {
                    return null;
                }
            }
            if (! is_int($receipt['version'] ?? null) || $receipt['version'] !== (int) $record->version
                || ! is_string($receipt['committed_at'] ?? null) || trim((string) $record->completed_at) === '') {
                return null;
            }
            $at = CarbonImmutable::parse($record->completed_at, 'UTC')->utc();
            if (! $at->equalTo(CarbonImmutable::parse($receipt['committed_at'], 'UTC')->utc())) {
                return null;
            }

            return [
                'state_at_record' => 'SENT_WAITING_WEB',
                'semantics' => 'HISTORICAL_RECORD_RECEIPT_NOT_CURRENT_DOCUMENT_STATE',
                'source' => 'mini_app_draft_receipts',
                'request_id' => $record->request_id,
                'draft_id' => $record->document_id,
                'scan_session_id' => $record->scan_session_id,
                'business_document_id' => $record->business_document_id,
                'business_type' => $source,
                'warehouse_id' => $record->warehouse_id,
                'draft_version_at_record' => (int) $record->version,
                'recorded_at' => $at->format('Y-m-d\TH:i:s.u\Z'),
                'timestamp_source' => 'mini_app_draft_receipts.completed_at',
                'timestamp_semantics' => 'TRANSACTIONAL_SERVER_TIMESTAMP_NOT_WAL_COMMIT_INSTANT',
                'stock_effect_at_record' => 'NONE',
                'posted_at_record' => false,
            ];
        } catch (\JsonException|\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
