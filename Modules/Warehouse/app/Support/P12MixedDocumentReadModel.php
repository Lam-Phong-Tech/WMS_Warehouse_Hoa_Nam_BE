<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** SQL UNION ALL over canonical owners; never merge paginated owner responses. */
final class P12MixedDocumentReadModel
{
    public const CONTRACT = 'p12-mixed-documents-v2';

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
            $time = $source === 'WARRANTY' ? 'c.created_at' : 'd.created_at';
            $status = $source === 'WARRANTY' ? 'c.status' : 'd.status';
            $query->where(fn (Builder $bound) => $bound->where($time, '<=', $upperBound)->orWhereNull($time));
            if ($filters['date_from'] !== null) {
                $start = CarbonImmutable::parse($filters['date_from'], $filters['timezone'])->startOfDay();
                $end = CarbonImmutable::parse($filters['date_to'], $filters['timezone'])->startOfDay()->addDay();
                $storage = config('app.timezone', 'UTC');
                $query->where($time, '>=', $start->setTimezone($storage)->format('Y-m-d H:i:s'))
                    ->where($time, '<', $end->setTimezone($storage)->format('Y-m-d H:i:s'));
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
        $query = DB::table('stock_documents as d')->where('d.doc_type', $type)
            ->where('d.'.$warehouse, $scope['warehouse_id'])->whereNull('d.deleted_at')
            ->selectRaw("CAST(d.id AS VARCHAR(36)) AS id, '{$source}' AS source, {$rank} AS source_rank,
                d.doc_no AS document_no, CAST(d.{$warehouse} AS VARCHAR(36)) AS warehouse_id,
                d.created_at, CASE WHEN d.created_at IS NULL THEN 1 ELSE 0 END AS time_missing,
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
        $owner = DB::table(WarrantyWarehouseScope::TABLE.' as w')
            ->whereColumn('w.warranty_case_id', 'c.warranty_case_id')->where('w.warehouse_id', $scope['warehouse_id']);
        P09WarrantyScope::isolate($owner, WarrantyWarehouseScope::TABLE, 'w', $scope['warranty']);
        $query = P09WarrantyScope::cases($scope['warranty'])
            ->selectRaw("CAST(c.warranty_case_id AS VARCHAR(36)) AS id, 'WARRANTY' AS source, 3 AS source_rank,
                c.warranty_case_code AS document_no")
            ->selectSub($owner->selectRaw('CAST(w.warehouse_id AS VARCHAR(36))')->limit(1), 'warehouse_id')
            ->selectRaw('c.created_at, CASE WHEN c.created_at IS NULL THEN 1 ELSE 0 END AS time_missing,
                c.status, CAST(NULL AS VARCHAR(255)) AS partner_name');
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

    public static function record(object $row): array
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
        $created = null;
        if ($row->created_at !== null) {
            try {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $row->created_at)) {
                    throw new \InvalidArgumentException('Missing absolute source timestamp.');
                }
                $created = CarbonImmutable::parse($row->created_at, config('app.timezone', 'UTC'))->utc()->toISOString();
            } catch (\InvalidArgumentException) {
                ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Thời gian nguồn không hợp lệ; không suy diễn mốc thay thế.');
            }
        }
        $p12 = P12DocumentPresentation::record([
            $source === 'WARRANTY' ? 'warranty_case_id' : 'id' => $row->id,
            $warehouseField => $row->warehouse_id, 'status' => $row->status,
        ], $source, false);
        $statusKey = $row->status === null ? null : $source.':'.$row->status;

        return [
            'id' => $row->id, 'row_key' => $source.':'.$row->id, 'source' => $source,
            'resource_type' => $p12['identity']['resource_type'],
            'document_no' => $row->document_no, 'warehouse_id' => $row->warehouse_id,
            'created_at' => $created,
            'timestamp_source' => ($source === 'WARRANTY' ? 'warranty_case' : 'stock_documents').'.created_at',
            'time_status' => $created === null ? 'NOT_RECORDED' : 'RECORDED',
            'status' => $row->status, 'source_status' => $row->status, 'status_key' => $statusKey,
            'mini_app_status' => null, 'readiness' => null,
            'display_status' => ['value' => null, 'label' => null, 'availability' => 'PENDING_BA_MAPPING',
                'source' => ($source === 'WARRANTY' ? 'warranty_case' : 'stock_documents').'.status'],
            'partner' => ['display_name' => $row->partner_name,
                'availability' => $source === 'WARRANTY' ? 'WITHHELD_IN_AGGREGATE' : ($row->partner_name === null ? 'NOT_RECORDED' : 'RECORDED'),
                'redacted' => $source === 'WARRANTY'],
            'p12' => $p12, 'read_target' => $p12['read_target'],
        ];
    }

    private static function requireColumns(string $table, array $required): void
    {
        if (array_diff($required, Schema::getColumnListing($table)) !== []) {
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn được phép đọc chưa sẵn sàng; không trả danh sách hoặc tổng một phần.');
        }
    }
}
