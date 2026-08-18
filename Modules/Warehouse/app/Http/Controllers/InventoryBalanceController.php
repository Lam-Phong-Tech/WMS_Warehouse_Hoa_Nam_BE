<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
use OpenApi\Attributes as OA;

/**
 * SCR-INV-01 (Annex C queryInventory) — tồn kho theo warehouse + SKU + stock_status.
 *
 * §7.1 DEC-INV-001: inventory_movements là ledger authoritative, inventory_balances chỉ
 * là projection, item/container là subledger. Màn này đọc projection và trả kèm kết quả
 * đối soát với ledger để người dùng thấy ngay khi ba lớp lệch (§7.5).
 *
 * REQ-SRS-037: không có trường/So sánh định mức kiểm kê hay hành động sửa tồn trực tiếp.
 */
class InventoryBalanceController extends Controller
{
    use ApiResponse;

    /** ERD bảng 29: stock_status CHECK AVAILABLE/NON_SELLABLE/DEFECTIVE/QUARANTINE. */
    private const STOCK_STATUSES = ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'];

    #[OA\Get(
        path: '/api/v1/inventory',
        operationId: 'inventory_balance_list',
        summary: 'Annex C queryInventory — tồn kho theo SKU/stock_status kèm đối soát ledger authoritative',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', description: 'Alias của sku_id', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PRODUCT', 'COMPONENT'])),
            new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: self::STOCK_STATUSES)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'InventorySummaryPage: on_hand/available + reconciliation_status + subledger counts + container hint'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view hoặc ngoài warehouse data scope'),
        ]
    )]
    public function index(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'sku_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'sku_type' => ['nullable', 'in:PRODUCT,COMPONENT'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);

        $skuId = $request->input('sku_id') ?? $request->input('product_id');
        $keyword = trim((string) $request->input('keyword', ''));
        $perPage = $this->perPage($request);
        $currentPage = (int) $request->integer('page', 1);

        $filters = [
            'warehouse_id' => $request->input('warehouse_id'),
            'sku_id' => $skuId,
            'sku_type' => $request->input('sku_type'),
            'stock_status' => $request->input('stock_status'),
            'keyword' => $keyword,
        ];

        $key = CacheKey::of('warehouse:inventory-balance:list', [
            'f' => CacheKey::hashFilters($filters),
            'per_page' => $perPage,
            'page' => $currentPage,
        ], $this->cacheService()->currentVersion('stock:version'));

        // Tồn kho correctness-sensitive (mục 8) — strategy B: short TTL + invalidation,
        // KHÔNG coi cache là nguồn sự thật (PostgreSQL vẫn là source of truth). Version
        // namespace `stock:version` được CurrentBaselineStockService::mutateBalance()
        // (điểm chốt duy nhất ghi inventory_balances) bump ngay sau khi transaction
        // commit — cache cũ "mồ côi" ngay, TTL ngắn chỉ là lưới an toàn bổ sung cho các
        // đường ghi lỡ chưa được rà tới.
        $cached = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('stock_short'),
            fn (): array => $this->buildInventoryBalancePage($request, $skuId, $keyword, $perPage, $currentPage)
        );

        $balances = new \Illuminate\Pagination\LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->respondPaginated($balances);
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
    private function buildInventoryBalancePage(Request $request, ?string $skuId, string $keyword, int $perPage, int $currentPage): array
    {
        // Lọc + phân trang ở tầng DB. Bản cũ nạp toàn bộ balance rồi cắt trang trong
        // bộ nhớ nên chi phí tăng tuyến tính theo số dòng tồn của cả kho.
        $query = InventoryBalance::query()->with(['product', 'warehouse']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        if ($skuId !== null) {
            $query->where('product_id', $skuId);
        }
        if ($request->filled('stock_status')) {
            $query->where('stock_status', $request->input('stock_status'));
        }
        if ($request->filled('sku_type')) {
            $skuType = strtoupper((string) $request->input('sku_type'));
            $query->whereHas('product', fn ($q) => $q->whereRaw('UPPER(sku.sku_type) = ?', [$skuType]));
        }
        if ($keyword !== '') {
            $like = '%'.mb_strtolower($keyword).'%';
            $query->whereHas('product', function ($q) use ($like): void {
                $q->whereRaw('LOWER(CAST(sku.sku_code AS TEXT)) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(CAST(sku.sku_name AS TEXT)) LIKE ?', [$like]);
            });
        }

        /** @var LengthAwarePaginator $balances */
        $balances = $query
            ->orderBy('product_id')
            ->orderBy('stock_status')
            ->paginate($perPage, ['*'], 'page', $currentPage);

        $page = collect($balances->items());
        $warehouseIds = $page->pluck('warehouse_id')->filter()->unique()->values()->all();
        $skuIds = $page->pluck('product_id')->filter()->unique()->values()->all();

        $ledgerTotals = $this->buildLedgerTotals($warehouseIds, $skuIds);
        $subledgerCounts = $this->buildSubledgerCounts($skuIds);
        $containerHints = $this->buildContainerHints($skuIds);
        $incidentMap = $this->buildIncidentMap($warehouseIds, $skuIds);
        $dataAsOf = now()->toIso8601String();

        $items = $page->map(function (InventoryBalance $balance) use (
            $ledgerTotals,
            $subledgerCounts,
            $containerHints,
            $incidentMap,
            $dataAsOf
        ): array {
            $stockStatus = $balance->stock_status ?? 'AVAILABLE';
            $dimKey = $this->dimensionKey($balance->warehouse_id, $balance->product_id, $stockStatus);
            $ledgerTotal = $ledgerTotals[$dimKey] ?? 0.0;
            $onHand = (float) $balance->on_hand_qty;
            $variance = bcsub((string) $onHand, (string) $ledgerTotal, 3);
            $reconciliationStatus = bccomp($variance, '0', 3) === 0 ? 'MATCH' : 'MISMATCH';

            $skuKey = (string) $balance->product_id;
            $sub = $subledgerCounts[$skuKey] ?? ['item_count' => 0, 'container_count' => 0];
            $hint = $containerHints[$skuKey] ?? null;
            $incident = $incidentMap[$balance->warehouse_id.'|'.$balance->product_id] ?? null;

            return [
                'id' => $balance->id,
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse?->name,
                'warehouse_code' => $balance->warehouse?->code,
                'sku_id' => $balance->product_id,
                'product_id' => $balance->product_id,
                'sku_code' => $balance->product?->sku_code,
                'product_code' => $balance->product?->sku_code,
                'product_name' => $balance->product?->sku_name,
                'sku_type' => $balance->product?->sku_type,
                'unit' => $balance->product?->unit,
                'stock_status' => $stockStatus,
                'on_hand_qty' => $onHand,
                'available_qty' => (float) $balance->available_qty,
                // Giữ cho báo cáo luồng 06 đang đọc trường này; SCR-INV-01 không hiển thị.
                'reserved_qty' => (float) $balance->reserved_qty,
                'ledger_total' => (float) $ledgerTotal,
                'reconciliation_status' => $reconciliationStatus,
                'mismatch_reason' => $reconciliationStatus === 'MATCH' ? null : 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM',
                'variance' => (float) $variance,
                'item_subledger_count' => (int) $sub['item_count'],
                'container_subledger_count' => (int) $sub['container_count'],
                // DR-LOC-001: gợi ý vị trí là thông tin tương đối, không tạo bucket tồn.
                'container_code' => $hint['container_code'] ?? null,
                'location_hint' => $hint['location_hint'] ?? null,
                'incident_id' => $incident?->id ?? null,
                'incident_status' => $incident?->status ?? null,
                'data_as_of' => $dataAsOf,
                'updated_at' => $balance->updated_at,
            ];
        })->all();

        return ['items' => $items, 'total' => $balances->total()];
    }

    private function cacheService(): CacheService
    {
        return app(CacheService::class);
    }

    #[OA\Get(
        path: '/api/v1/inventory/balances/{id}/subledger',
        operationId: 'inventory_balance_subledger',
        summary: 'Subledger drill-down: item_instances + container_items cho một balance',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item/Container subledger lists'),
            new OA\Response(response: 404, description: 'Balance not found'),
        ]
    )]
    public function subledger(string $id)
    {
        $balance = InventoryBalance::find($id);
        if (! $balance) {
            return $this->respondNotFound('Không tìm thấy balance.');
        }

        // Cùng một predicate với buildSubledgerCounts, nếu không thì con số đối soát
        // và danh sách drill-down mâu thuẫn nhau ngay trên cùng một màn hình.
        $items = $this->eligibleItemQuery([$balance->product_id])
            ->leftJoin('sku', 'item_instances.sku_id', '=', 'sku.sku_id')
            ->leftJoin('containers', 'containers.container_id', '=', 'item_instances.container_id')
            ->leftJoin('physical_codes', function ($join) {
                $join->on('physical_codes.assigned_object_id', '=', 'item_instances.item_id')
                    ->where('physical_codes.assigned_object_type', '=', 'ITEM');
            })
            ->orderByDesc('item_instances.created_at')
            ->limit(200)
            ->select([
                'item_instances.item_id as id',
                'item_instances.item_code',
                'item_instances.serial_number',
                'item_instances.item_status as status',
                'item_instances.item_condition as condition',
                'item_instances.container_id as current_container_id',
                'item_instances.created_at',
                'sku.sku_code',
                'sku.sku_name',
                'containers.container_code',
                'containers.location_hint',
                'physical_codes.code_value as physical_code',
                'physical_codes.carrier_type as code_carrier_type',
            ])
            ->get();

        $containers = $this->activeContainerItemQuery([$balance->product_id])
            ->join('containers', 'container_items.container_id', '=', 'containers.container_id')
            ->orderByDesc('container_items.created_at')
            ->limit(200)
            ->select([
                'container_items.container_item_id as id',
                'container_items.container_id',
                'container_items.item_id',
                'container_items.quantity',
                'container_items.status',
                'container_items.created_at',
                'containers.container_code',
                'containers.container_type',
                'containers.zone_code',
                'containers.location_hint',
                'containers.status as container_status',
            ])
            ->get();

        return $this->respondSuccess([
            'balance_id' => $id,
            'warehouse_id' => $balance->warehouse_id,
            'sku_id' => $balance->product_id,
            'product_id' => $balance->product_id,
            'stock_status' => $balance->stock_status ?? 'AVAILABLE',
            'items' => $items,
            'containers' => $containers,
            'item_count' => $items->count(),
            'container_count' => $containers->pluck('container_id')->unique()->count(),
        ]);
    }

    /**
     * §7.5 "Balance available = count eligible Item_Instance status IN_STOCK and sellable
     * condition". NEW là item vừa tạo chưa nhập kho nên không tính vào tồn khả dụng.
     *
     * @param  list<string>  $skuIds
     */
    private function eligibleItemQuery(array $skuIds): Builder
    {
        return DB::table('item_instances')
            ->whereIn('item_instances.sku_id', $skuIds)
            ->where('item_instances.item_status', 'IN_STOCK')
            ->where(function ($query): void {
                $query->whereNull('item_instances.item_condition')
                    ->orWhere('item_instances.item_condition', 'NORMAL');
            });
    }

    /**
     * `container_items` mang cả `status` và `is_active`. `is_active` là cột có unique
     * partial index (uk_container_items_one_active_item) nên đây là predicate canonical;
     * đọc bằng `status` sẽ lệch với chỉ mục và với số đếm subledger.
     *
     * @param  list<string>  $skuIds
     */
    private function activeContainerItemQuery(array $skuIds): Builder
    {
        return DB::table('container_items')
            ->whereIn('container_items.sku_id', $skuIds)
            ->where('container_items.is_active', true);
    }

    private function dimensionKey(?string $warehouseId, ?string $productId, string $stockStatus): string
    {
        return ($warehouseId ?? '').'|'.($productId ?? '').'|'.$stockStatus;
    }

    /**
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $skuIds
     * @return array<string, float>
     */
    private function buildLedgerTotals(array $warehouseIds, array $skuIds): array
    {
        if ($warehouseIds === [] || $skuIds === []) {
            return [];
        }

        $rows = DB::table('stock_movements')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $skuIds)
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_total')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$this->dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status ?? 'AVAILABLE')] = (float) $row->ledger_total;
        }

        return $map;
    }

    /**
     * @param  list<string>  $skuIds
     * @return array<string, array{item_count: int, container_count: int}>
     */
    private function buildSubledgerCounts(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $map = [];
        // ERD bảng 20: item_instances không mang warehouse_id vì REQ-SRS-001 chỉ có một
        // kho ACTIVE — số đếm là toàn hệ thống và áp cho kho đang xét.
        foreach ($this->eligibleItemQuery($skuIds)->groupBy('item_instances.sku_id')
            ->select('item_instances.sku_id', DB::raw('COUNT(*) AS cnt'))->get() as $item) {
            $map[(string) $item->sku_id] = ['item_count' => (int) $item->cnt, 'container_count' => 0];
        }

        foreach ($this->activeContainerItemQuery($skuIds)->groupBy('container_items.sku_id')
            ->select('container_items.sku_id', DB::raw('COUNT(DISTINCT container_items.container_id) AS cnt'))->get() as $container) {
            $key = (string) $container->sku_id;
            $map[$key] = $map[$key] ?? ['item_count' => 0, 'container_count' => 0];
            $map[$key]['container_count'] = (int) $container->cnt;
        }

        return $map;
    }

    /**
     * Annex D SCR-INV-01 "container hint". DR-LOC-001: chỉ hiển thị, không tham gia
     * available_qty và có thể null tới khi khảo sát vị trí.
     *
     * @param  list<string>  $skuIds
     * @return array<string, array{container_code: ?string, location_hint: ?string}>
     */
    private function buildContainerHints(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $rows = $this->activeContainerItemQuery($skuIds)
            ->join('containers', 'container_items.container_id', '=', 'containers.container_id')
            ->orderByDesc('container_items.created_at')
            ->select([
                'container_items.sku_id',
                'containers.container_code',
                'containers.location_hint',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row->sku_id;
            if (! isset($map[$key])) {
                $map[$key] = [
                    'container_code' => $row->container_code,
                    'location_hint' => $row->location_hint,
                ];
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $skuIds
     */
    private function buildIncidentMap(array $warehouseIds, array $skuIds): array
    {
        if ($warehouseIds === [] || $skuIds === []) {
            return [];
        }

        $incidents = DB::table('reconciliation_incidents')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('sku_id', $skuIds)
            ->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->orderByDesc('created_at')
            ->get(['id', 'warehouse_id', 'sku_id', 'status', 'divergence_type']);

        $map = [];
        foreach ($incidents as $inc) {
            $key = $inc->warehouse_id.'|'.$inc->sku_id;
            if (! isset($map[$key])) {
                $map[$key] = $inc;
            }
        }

        return $map;
    }
}
