<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\SafeStockConfig;
use Modules\Warehouse\Services\SafeStockAlertService;
use OpenApi\Attributes as OA;

/** Tồn kho hiện tại theo SKU/trạng thái (so với định mức tồn kho an toàn) tại kho tổng. */
class InventoryBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SafeStockAlertService $safeStockAlerts) {}

    #[OA\Get(
        path: '/api/v1/inventory/balances',
        operationId: 'inventory_balance_list',
        summary: 'Tồn kho hiện tại theo SKU/trạng thái (normal/low_stock/overflow) tại kho tổng, kèm đối soát ledger authoritative',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['normal', 'low_stock', 'overflow'])),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách tồn kho (on_hand_qty/available_qty/reserved_qty + reconciliation_status + subledger counts)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = InventoryBalance::query()->with(['product', 'warehouse']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $balances = $query->orderBy('product_id')->get();

        $balanceKeys = $balances->map(fn (InventoryBalance $b) => [
            'warehouse_id' => $b->warehouse_id,
            'product_id' => $b->product_id,
            'stock_status' => $b->stock_status ?? 'AVAILABLE',
        ])->all();

        $ledgerTotals = $this->buildLedgerTotals($balanceKeys);
        $subledgerCounts = $this->buildSubledgerCounts($balances->pluck('product_id')->unique()->all(), $balances->pluck('warehouse_id')->unique()->all());
        $incidentMap = $this->buildIncidentMap($balances->pluck('warehouse_id')->unique()->all(), $balances->pluck('product_id')->unique()->all());
        $dataAsOf = now()->toIso8601String();

        $rows = $balances->map(function (InventoryBalance $balance) use ($ledgerTotals, $subledgerCounts, $incidentMap, $dataAsOf) {
            $config = SafeStockConfig::query()
                ->where('product_id', $balance->product_id)
                ->where(function ($q) use ($balance) {
                    $q->where('warehouse_id', $balance->warehouse_id)->orWhereNull('warehouse_id');
                })
                ->orderByRaw('warehouse_id IS NULL')
                ->first();

            $status = $config ? $this->safeStockAlerts->statusFor((float) $balance->available_qty, $config) : 'normal';

            $dimKey = $this->dimensionKey($balance->warehouse_id, $balance->product_id, $balance->stock_status ?? 'AVAILABLE');
            $ledgerTotal = $ledgerTotals[$dimKey] ?? 0.0;
            $onHand = (float) $balance->on_hand_qty;
            $variance = bcsub((string) $onHand, (string) $ledgerTotal, 3);
            $reconciliationStatus = bccomp($variance, '0', 3) === 0 ? 'MATCH' : 'MISMATCH';
            $mismatchReason = $reconciliationStatus === 'MATCH' ? null : 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM';

            $subKey = $balance->warehouse_id . '|' . $balance->product_id;
            $sub = $subledgerCounts[$subKey] ?? ['item_count' => 0, 'container_count' => 0];
            $incKey = $balance->warehouse_id . '|' . $balance->product_id;
            $incident = $incidentMap[$incKey] ?? null;

            return [
                'id' => $balance->id,
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse?->name,
                'warehouse_code' => $balance->warehouse?->code,
                'product_id' => $balance->product_id,
                'product_code' => $balance->product?->code,
                'product_name' => $balance->product?->name,
                'unit' => $balance->product?->unit,
                'on_hand_qty' => $onHand,
                'available_qty' => (float) $balance->available_qty,
                'reserved_qty' => (float) $balance->reserved_qty,
                'stock_status' => $balance->stock_status ?? 'AVAILABLE',
                'status' => $status,
                'ledger_total' => (float) $ledgerTotal,
                'reconciliation_status' => $reconciliationStatus,
                'mismatch_reason' => $mismatchReason,
                'variance' => (float) $variance,
                'item_subledger_count' => (int) $sub['item_count'],
                'container_subledger_count' => (int) $sub['container_count'],
                'incident_id' => $incident?->incident_id ?? null,
                'incident_status' => $incident?->status ?? null,
                'data_as_of' => $dataAsOf,
                'updated_at' => $balance->updated_at,
            ];
        });

        if ($request->filled('status')) {
            $rows = $rows->where('status', $request->input('status'))->values();
        }
        if ($request->filled('keyword')) {
            $keyword = mb_strtolower(trim((string) $request->input('keyword')));
            $rows = $rows->filter(function (array $row) use ($keyword): bool {
                return str_contains(mb_strtolower((string) ($row['product_code'] ?? '')), $keyword)
                    || str_contains(mb_strtolower((string) ($row['product_name'] ?? '')), $keyword);
            })->values();
        }

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->respondPaginated($paginator);
    }

    private function dimensionKey(?string $warehouseId, ?string $productId, string $stockStatus): string
    {
        return ($warehouseId ?? '') . '|' . ($productId ?? '') . '|' . $stockStatus;
    }

    /** @param list<array{warehouse_id: ?string, product_id: ?string, stock_status: string}> $keys */
    private function buildLedgerTotals(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $rows = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_total')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $key = $this->dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status ?? 'AVAILABLE');
            $map[$key] = (float) $row->ledger_total;
        }

        return $map;
    }

    /** @param list<string> $productIds @param list<string> $warehouseIds */
    private function buildSubledgerCounts(array $productIds, array $warehouseIds): array
    {
        $map = [];
        if (empty($productIds)) {
            return $map;
        }

        $items = DB::table('item_instances')
            ->whereIn('sku_id', $productIds)
            ->whereIn('status', ['IN_STOCK', 'NEW'])
            ->groupBy('sku_id')
            ->select('sku_id', DB::raw('COUNT(*) AS cnt'))
            ->get();

        foreach ($items as $item) {
            foreach ($warehouseIds as $whId) {
                $key = $whId . '|' . $item->sku_id;
                $map[$key] = $map[$key] ?? ['item_count' => 0, 'container_count' => 0];
                $map[$key]['item_count'] = (int) $item->cnt;
            }
        }

        $containers = DB::table('container_items')
            ->whereIn('sku_id', $productIds)
            ->where('is_active', true)
            ->groupBy('sku_id')
            ->select('sku_id', DB::raw('COUNT(DISTINCT container_id) AS cnt'))
            ->get();

        foreach ($containers as $container) {
            foreach ($warehouseIds as $whId) {
                $key = $whId . '|' . $container->sku_id;
                $map[$key] = $map[$key] ?? ['item_count' => 0, 'container_count' => 0];
                $map[$key]['container_count'] = (int) $container->cnt;
            }
        }

        return $map;
    }

    /** @param list<string> $warehouseIds @param list<string> $productIds */
    private function buildIncidentMap(array $warehouseIds, array $productIds): array
    {
        if (empty($warehouseIds) || empty($productIds)) {
            return [];
        }

        $incidents = DB::table('reconciliation_incidents')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('sku_id', $productIds)
            ->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->orderByDesc('created_at')
            ->get(['incident_id', 'warehouse_id', 'sku_id', 'status', 'divergence_type']);

        $map = [];
        foreach ($incidents as $inc) {
            $key = $inc->warehouse_id . '|' . $inc->sku_id;
            if (! isset($map[$key])) {
                $map[$key] = $inc;
            }
        }

        return $map;
    }
}
