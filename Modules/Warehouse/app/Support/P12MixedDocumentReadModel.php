<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** SQL UNION ALL over canonical owners; never merge paginated owner responses. */
final class P12MixedDocumentReadModel
{
    public const CONTRACT = 'p12-mixed-documents-v3';

    public const SOURCES = ['INBOUND', 'OUTBOUND', 'WARRANTY'];

    public const STOCK_STATUSES = ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'];

    public static function statusKeys(): array
    {
        $keys = [];
        foreach (self::SOURCES as $source) {
            foreach ($source === 'WARRANTY' ? P09WarrantyPolicy::STATUSES : self::STOCK_STATUSES as $status) {
                $keys[] = $source.':'.$status;
            }
        }

        return $keys;
    }

    public static function input(Request $request): array
    {
        return P12MixedDocumentQuery::input($request);
    }

    public static function union(array $scope, array $filters, string $upperBound): Builder
    {
        if ($filters['keyword'] !== '') {
            P12MixedDocumentSearch::prepare();
        }
        $union = null;
        foreach (self::SOURCES as $source) {
            // Do not inspect schema, search or count a denied owner.
            if (! $scope['allowed'][$source]) {
                continue;
            }
            $query = $source === 'WARRANTY' ? self::warranty($scope, $filters) : self::stock($scope, $filters, $source);
            if (($filters['web_queue'] ?? 'ALL') === 'WAITING_WEB_POST') {
                P13WebPostHandoff::waiting($query, $source, $scope['warehouse_id']);
            }
            $time = DocumentTimestampSql::createdAt($source);
            $status = $source === 'WARRANTY' ? 'c.status' : 'd.status';
            $query->where(fn (Builder $bound) => $bound->whereRaw($time.' <= ?', [$upperBound])->orWhereRaw($time.' IS NULL'));
            if ($filters['date_from'] !== null) {
                $start = CarbonImmutable::parse($filters['date_from'], $filters['timezone'])->startOfDay();
                $end = CarbonImmutable::parse($filters['date_to'], $filters['timezone'])->startOfDay()->addDay();
                $query->whereRaw($time.' >= ?', [DocumentTimestamp::key($start)])
                    ->whereRaw($time.' < ?', [DocumentTimestamp::key($end)]);
            }
            if ($filters['status'] !== 'ALL') {
                [$statusSource, $canonical] = explode(':', $filters['status'], 2);
                $statusSource === $source ? $query->where($status, $canonical) : $query->whereRaw('1 = 0');
            }
            $union = $union === null ? $query : $union->unionAll($query);
        }
        if ($union === null) {
            ApiError::abort(403, 'DOCUMENTS_PERMISSION_DENIED', 'Không có nguồn chứng từ được phép đọc.');
        }

        return DB::query()->fromSub($union, 'p12_mixed');
    }

    private static function stock(array $scope, array $filters, string $source): Builder
    {
        $warehouse = $source === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id';
        $partner = $source === 'INBOUND' ? 'source_name' : 'recipient_name';
        $type = $source === 'INBOUND' ? 'receipt' : 'issue';
        $rank = $source === 'INBOUND' ? 1 : 2;
        self::requireColumns('stock_documents', ['id', 'doc_type', 'doc_no', 'created_at', 'status', 'deleted_at', $warehouse, $partner]);
        self::requireColumns('stock_document_lines', ['stock_document_id', 'product_id', 'qty_planned', 'scanned_qty']);
        if ($source === 'INBOUND') {
            self::requireColumns('sku', ['sku_id', 'sku_code', 'sku_name', 'unit']);
        }
        $time = DocumentTimestampSql::createdAt($source);
        $query = DB::table('stock_documents as d')->where('d.doc_type', $type)
            ->where('d.'.$warehouse, $scope['warehouse_id'])->whereNull('d.deleted_at')
            ->selectRaw("CAST(d.id AS VARCHAR(36)) AS id, '{$source}' AS source, {$rank} AS source_rank,
                d.doc_no AS document_no, CAST(d.{$warehouse} AS VARCHAR(36)) AS warehouse_id,
                {$time} AS created_at, CASE WHEN d.created_at IS NULL THEN 1 ELSE 0 END AS time_missing,
                d.status, d.{$partner} AS partner_name");
        P09WarrantyScope::isolate($query, 'stock_documents', 'd', $scope);
        if ($filters['keyword'] !== '' && ! P12MixedDocumentSearch::typeMatches($source, $filters['keyword'])) {
            $pattern = P09WarrantyPolicy::pattern($filters['keyword']);
            $query->where(fn (Builder $search) => $search
                ->whereRaw(P12MixedDocumentSearch::contains('d.doc_no'), [$pattern])
                ->orWhereRaw(P12MixedDocumentSearch::contains('d.'.$partner), [$pattern]));
        }

        return $query;
    }

    private static function warranty(array $scope, array $filters): Builder
    {
        self::requireColumns('warranty_case', ['warranty_case_id', 'warranty_case_code', 'created_at', 'status', 'temporary_item_code', 'item_id']);
        self::requireColumns(WarrantyWarehouseScope::TABLE, ['warranty_case_id', 'warehouse_id']);
        $time = DocumentTimestampSql::createdAt('WARRANTY');
        $owner = DB::table(WarrantyWarehouseScope::TABLE.' as w')
            ->whereColumn('w.warranty_case_id', 'c.warranty_case_id')->where('w.warehouse_id', $scope['warehouse_id']);
        P09WarrantyScope::isolate($owner, WarrantyWarehouseScope::TABLE, 'w', $scope['warranty']);
        $query = P09WarrantyScope::cases($scope['warranty'])
            ->selectRaw("CAST(c.warranty_case_id AS VARCHAR(36)) AS id, 'WARRANTY' AS source, 3 AS source_rank,
                c.warranty_case_code AS document_no")
            ->selectSub($owner->selectRaw('CAST(w.warehouse_id AS VARCHAR(36))')->limit(1), 'warehouse_id')
            ->selectRaw("{$time} AS created_at, CASE WHEN c.created_at IS NULL THEN 1 ELSE 0 END AS time_missing,
                c.status, CAST(NULL AS VARCHAR(255)) AS partner_name");
        if ($filters['keyword'] !== '' && ! P12MixedDocumentSearch::typeMatches('WARRANTY', $filters['keyword'])) {
            self::requireColumns('item_instances', ['item_id', 'serial_number']);
            $pattern = P09WarrantyPolicy::pattern($filters['keyword']);
            $query->where(function (Builder $search) use ($pattern, $scope): void {
                $search->whereRaw(P12MixedDocumentSearch::contains('c.warranty_case_code'), [$pattern])
                    ->orWhereRaw(P12MixedDocumentSearch::contains('c.temporary_item_code'), [$pattern])
                    ->orWhereExists(function (Builder $item) use ($pattern, $scope): void {
                        $item->selectRaw('1')->from('item_instances as i')->whereColumn('i.item_id', 'c.item_id')
                            ->whereRaw(P12MixedDocumentSearch::contains('i.serial_number'), [$pattern]);
                        P09WarrantyScope::isolate($item, 'item_instances', 'i', $scope['warranty']);
                    });
            });
        }

        return $query;
    }

    public static function facets(Builder $union, array $scope): array
    {
        $counts = (clone $union)->select('source')->selectRaw('COUNT(*) AS total')->groupBy('source')->pluck('total', 'source');
        $result = [];
        $total = 0;
        foreach (self::SOURCES as $source) {
            $count = $scope['allowed'][$source] ? (int) ($counts[$source] ?? 0) : null;
            $result[$source] = ['availability' => $scope['allowed'][$source] ? 'READY' : 'DENIED', 'total' => $count];
            if ($count !== null) {
                $total += $count;
            }
        }

        return ['ALL' => ['availability' => 'READY', 'total' => $total]] + $result;
    }

    /** Hydrate only the selected page. Facet/total queries never calculate readiness. */
    public static function readiness(Collection $rows): array
    {
        $stock = $rows->filter(fn (object $row): bool => in_array($row->source, ['INBOUND', 'OUTBOUND'], true));
        $ids = $stock->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        self::requireColumns('stock_document_lines', ['stock_document_id', 'product_id', 'qty_planned', 'scanned_qty']);
        $lines = DB::table('stock_document_lines')->whereIn('stock_document_id', $ids)
            ->get(['stock_document_id', 'product_id', 'qty_planned', 'scanned_qty'])->groupBy('stock_document_id');
        $inboundIds = $stock->where('source', 'INBOUND')->pluck('id')->all();
        $products = collect();
        if ($inboundIds !== []) {
            self::requireColumns('sku', ['sku_id', 'sku_code', 'sku_name', 'unit']);
            $products = DB::table('stock_document_lines as p12_info')
                ->join('sku as p12_sku', 'p12_sku.sku_id', '=', 'p12_info.product_id')
                ->whereIn('p12_info.stock_document_id', $inboundIds)
                ->get(['p12_info.stock_document_id', 'p12_sku.sku_id', 'p12_sku.sku_code', 'p12_sku.sku_name', 'p12_sku.unit'])
                ->groupBy('stock_document_id');
        }

        $result = [];
        foreach ($stock as $row) {
            $documentLines = $lines->get($row->id, collect());
            $scanComplete = $documentLines->isNotEmpty() && $documentLines->every(
                fn (object $line): bool => (float) $line->qty_planned > 0
                    && (float) $line->scanned_qty === (float) $line->qty_planned
            );
            $productInfoComplete = $row->source !== 'INBOUND'
                || $products->get($row->id, collect())->every(fn (object $sku): bool => SkuInboundInfoRule::isComplete(
                    $sku->sku_code, $sku->sku_name, $sku->unit
                ));
            $result[$row->source.':'.$row->id] = [
                'scan_complete' => $scanComplete,
                'product_info_complete' => $productInfoComplete,
            ];
        }

        return $result;
    }

    /** Rows have already been normalized to UTC by the SQL projection. */
    public static function record(object $row, array $readiness = [], ?array $handoff = null, string $handoffStatus = 'NOT_ESTABLISHED'): array
    {
        if (! is_string($row->id ?? null) || ! Str::isUuid($row->id)
            || ! is_string($row->warehouse_id ?? null) || ! Str::isUuid($row->warehouse_id)
            || ! in_array($row->source ?? null, self::SOURCES, true)) {
            ApiError::abort(503, 'DOCUMENTS_IDENTITY_UNAVAILABLE', 'Nguồn chứng từ chưa có định danh hợp lệ.');
        }
        foreach (['document_no', 'status', 'partner_name', 'created_at'] as $field) {
            if (! property_exists($row, $field) || ($row->{$field} !== null
                && (! is_string($row->{$field}) || ! mb_check_encoding($row->{$field}, 'UTF-8')))) {
                ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn chứng từ có dữ liệu không hợp lệ; không trả kết quả một phần.');
            }
        }
        $source = $row->source;
        $warehouseField = match ($source) {
            'INBOUND' => 'dst_warehouse_id', 'OUTBOUND' => 'src_warehouse_id', default => 'warehouse_id',
        };
        try {
            $created = DocumentTimestamp::utc($row->created_at, 'UTC');
        } catch (\InvalidArgumentException) {
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Thời gian nguồn không hợp lệ; không suy diễn mốc thay thế.');
        }
        $scanComplete = ($readiness['scan_complete'] ?? false) === true;
        $productInfoComplete = ($readiness['product_info_complete'] ?? false) === true;
        $mapping = P12MixedDocumentStatus::make($source, $row->status, $scanComplete, $productInfoComplete);
        $p12 = P12DocumentPresentation::record([
            $source === 'WARRANTY' ? 'warranty_case_id' : 'id' => $row->id,
            $warehouseField => $row->warehouse_id, 'status' => $row->status,
            'mini_app_status' => $mapping['mini_app_status'],
            'ready_for_post' => $mapping['readiness']['ready_for_post'],
            'ready_for_issue' => $mapping['readiness']['ready_for_issue'],
            'can_post' => $mapping['readiness']['can_post'],
        ], $source, false);
        $p12['status']['readiness'] = $mapping['readiness'];
        $statusKey = $row->status === null ? null : $source.':'.$row->status;

        return [
            'id' => $row->id, 'row_key' => $source.':'.$row->id, 'source' => $source,
            'resource_type' => $p12['identity']['resource_type'],
            'document_no' => $row->document_no, 'warehouse_id' => $row->warehouse_id,
            'created_at' => $created,
            'timestamp_source' => ($source === 'WARRANTY' ? 'warranty_case' : 'stock_documents').'.created_at',
            'time_status' => $created === null ? 'NOT_RECORDED' : 'RECORDED',
            'status' => $row->status, 'canonical_status' => $mapping['canonical_status'],
            'source_status' => $row->status, 'status_key' => $statusKey,
            'mini_app_status' => $mapping['mini_app_status'], 'readiness' => $mapping['readiness'],
            'display_status' => $mapping['display_status'],
            'partner' => ['display_name' => $row->partner_name,
                'availability' => $source === 'WARRANTY' ? 'WITHHELD_IN_AGGREGATE' : ($row->partner_name === null ? 'NOT_RECORDED' : 'RECORDED'),
                'redacted' => $source === 'WARRANTY'],
            'p12' => $p12, 'read_target' => $p12['read_target'], 'web_post_handoff' => $handoff,
            'web_post_handoff_availability' => $row->source === 'WARRANTY' ? 'NOT_APPLICABLE' : ($handoff === null ? $handoffStatus : 'RECORDED'),
        ];
    }

    private static function requireColumns(string $table, array $required): void
    {
        if (array_diff($required, Schema::getColumnListing($table)) !== []) {
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn được phép đọc chưa sẵn sàng; không trả danh sách hoặc tổng một phần.');
        }
    }
}
