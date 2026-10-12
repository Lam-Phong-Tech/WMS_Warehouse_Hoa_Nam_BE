<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** SQL UNION ALL over canonical owners; never merge paginated owner responses. */
final class P12MixedDocumentReadModel
{
    public const CONTRACT = 'p12-mixed-documents-v1';

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
        $known = ['warehouse_id', 'source', 'keyword', 'status', 'date_from', 'date_to', 'timezone', 'sort', 'per_page', 'cursor'];
        if (array_diff(array_keys($request->query()), $known) !== []) {
            ApiError::abort(422, 'DOCUMENTS_QUERY_UNSUPPORTED', 'Tham số không thuộc contract danh sách trộn.');
        }
        $input = Validator::make($request->query(), [
            'warehouse_id' => ['required', 'uuid'],
            'source' => ['sometimes', Rule::in(['ALL', ...self::SOURCES])],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['ALL', ...self::statusKeys()])],
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'sort' => ['sometimes', Rule::in(['created_at_desc', 'created_at_asc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:4096'],
        ])->validate();
        $filters = [
            'warehouse_id' => strtolower($input['warehouse_id']),
            'source' => $input['source'] ?? 'ALL',
            'keyword' => trim($input['keyword'] ?? ''),
            'status' => $input['status'] ?? 'ALL',
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'timezone' => $input['timezone'] ?? 'Asia/Ho_Chi_Minh',
            'sort' => $input['sort'] ?? 'created_at_desc',
            'per_page' => (int) ($input['per_page'] ?? 20),
        ];
        if ($filters['status'] !== 'ALL' && $filters['source'] !== 'ALL'
            && ! str_starts_with($filters['status'], $filters['source'].':')) {
            ApiError::abort(422, 'DOCUMENTS_STATUS_SOURCE_MISMATCH', 'Trạng thái không thuộc nguồn đã chọn.');
        }

        return [$filters, $input['cursor'] ?? 'start'];
    }

    public static function union(array $scope, array $filters, string $upperBound): Builder
    {
        $union = null;
        foreach (self::SOURCES as $source) {
            // Do not even inspect schema, search or count a denied owner.
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
        if ($filters['keyword'] !== '') {
            $pattern = P09WarrantyPolicy::pattern($filters['keyword']);
            $query->where(fn (Builder $search) => $search
                ->whereRaw("LOWER(d.doc_no) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(d.{$partner}) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
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
        // Filtering must stay on the owner query, before wrapping/UNION/count.
        if ($filters['keyword'] !== '') {
            self::requireColumns('item_instances', ['item_id', 'serial_number']);
            $pattern = P09WarrantyPolicy::pattern($filters['keyword']);
            $query->where(function (Builder $search) use ($pattern, $scope): void {
                $search->whereRaw("LOWER(c.warranty_case_code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(c.temporary_item_code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereExists(function (Builder $item) use ($pattern, $scope): void {
                        $item->selectRaw('1')->from('item_instances as i')->whereColumn('i.item_id', 'c.item_id')
                            ->whereRaw("LOWER(i.serial_number) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
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
        foreach (self::SOURCES as $source) {
            $result[$source] = [
                'availability' => $scope['allowed'][$source] ? 'READY' : 'DENIED',
                'total' => $scope['allowed'][$source] ? (int) ($counts[$source] ?? 0) : null,
            ];
        }

        return $result;
    }

    public static function record(object $row): array
    {
        if (! Str::isUuid($row->id) || ! Str::isUuid($row->warehouse_id)) {
            ApiError::abort(503, 'DOCUMENTS_IDENTITY_UNAVAILABLE', 'Nguồn chứng từ chưa có định danh hợp lệ.');
        }
        $source = $row->source;
        $warehouseField = match ($source) {
            'INBOUND' => 'dst_warehouse_id',
            'OUTBOUND' => 'src_warehouse_id',
            default => 'warehouse_id',
        };
        $created = $row->created_at === null ? null : CarbonImmutable::parse($row->created_at, config('app.timezone', 'UTC'))->utc()->toISOString();
        $p12 = P12DocumentPresentation::record([
            $source === 'WARRANTY' ? 'warranty_case_id' : 'id' => $row->id,
            $warehouseField => $row->warehouse_id, 'status' => $row->status,
        ], $source, false);

        return [
            'id' => $row->id, 'row_key' => $source.':'.$row->id, 'source' => $source,
            'document_no' => $row->document_no, 'warehouse_id' => $row->warehouse_id,
            'created_at' => $created,
            'timestamp_source' => ($source === 'WARRANTY' ? 'warranty_case' : 'stock_documents').'.created_at',
            'time_status' => $created === null ? 'NOT_RECORDED' : 'RECORDED',
            'status' => $row->status, 'status_key' => $source.':'.$row->status,
            'partner' => ['display_name' => $row->partner_name,
                'availability' => $source === 'WARRANTY' ? 'WITHHELD_IN_AGGREGATE' : ($row->partner_name === null ? 'NOT_RECORDED' : 'RECORDED')],
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
