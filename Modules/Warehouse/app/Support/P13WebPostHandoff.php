<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Persisted submission intent + canonical current state, never a readiness alias. */
final class P13WebPostHandoff
{
    public const CONTRACT = 'p13-web-post-queue-v1';

    public const TABLE = 'mini_app_web_post_handoffs';

    public static function record(string $id, string $source, string $warehouse, int $version): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($source, ['INBOUND', 'OUTBOUND'], true)) {
            throw new LogicException('P13_HANDOFF_REQUIRES_OWNER_TRANSACTION');
        }
        self::schema();
        $direction = $source === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id';
        if (! DB::table('stock_documents')->where('id', $id)->where('doc_type', $source === 'INBOUND' ? 'receipt' : 'issue')
            ->where($direction, $warehouse)->where('version', $version)->whereNull('deleted_at')
            ->whereIn('status', ['DRAFT', 'SCANNING'])->exists()) {
            throw new LogicException('P13_HANDOFF_OWNER_MISMATCH');
        }
        DB::table(self::TABLE)->insert(['stock_document_id' => $id, 'source' => $source, 'warehouse_id' => $warehouse,
            'document_version_at_record' => $version, 'submitted_at' => now()->utc()->toISOString()]);
    }

    public static function waiting(Builder $query, string $source, string $warehouse): void
    {
        self::schema();
        if ($source === 'WARRANTY') {
            $query->whereRaw('1 = 0'); // Warranty is not a stock POST command.

            return;
        }
        $query->whereIn('d.status', ['DRAFT', 'SCANNING'])->whereExists(function (Builder $handoff) use ($source, $warehouse): void {
            $handoff->selectRaw('1')->from(self::TABLE.' as h')->whereColumn('h.stock_document_id', 'd.id')
                ->where('h.source', $source)->where('h.warehouse_id', $warehouse);
        });
    }

    /** Parent is already authorized. Re-read canonical state even if parent cache hit. */
    public static function project(array $identities, string $source): array
    {
        self::schema();
        $direction = $source === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id';
        $result = [];
        $rows = DB::table(self::TABLE.' as h')->join('stock_documents as d', 'd.id', '=', 'h.stock_document_id')
            ->where('h.source', $source)->where('d.doc_type', $source === 'INBOUND' ? 'receipt' : 'issue')->whereNull('d.deleted_at')
            ->whereColumn('h.warehouse_id', 'd.'.$direction)->whereIn('d.id', array_keys($identities))
            ->get(['h.*', 'd.status', 'd.posted_at']);
        foreach ($rows as $row) {
            if (($identities[$row->stock_document_id] ?? null) !== $row->warehouse_id) {
                continue;
            }
            $state = match ($row->status) {
                'DRAFT', 'SCANNING' => 'WAITING_WEB_POST', 'POSTED' => 'POSTED', 'CANCELLED' => 'CANCELLED', default => 'UNKNOWN',
            };
            $result[$row->stock_document_id] = [
                'contract' => self::CONTRACT, 'state' => $state,
                'current_waiting_for_web' => $state === 'UNKNOWN' ? null : $state === 'WAITING_WEB_POST',
                'source' => self::TABLE.'+stock_documents.status', 'warehouse_id' => $row->warehouse_id,
                'submitted_at' => DocumentTimestamp::utc($row->submitted_at, 'UTC'),
                'document_version_at_record' => (int) $row->document_version_at_record,
                'reviewed_at' => null, 'reviewed_status' => 'NOT_RECORDED',
                'posted_at' => DocumentTimestamp::utc($row->posted_at),
                'stock_effect' => 'READ_ONLY_PROJECTION',
            ];
        }

        return $result;
    }

    public static function schema(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            ApiError::abort(503, 'WEB_POST_HANDOFF_SOURCE_UNAVAILABLE', 'Chưa sẵn sàng nguồn bàn giao Web POST; không suy từ readiness hoặc receipt cũ.');
        }
    }
}
