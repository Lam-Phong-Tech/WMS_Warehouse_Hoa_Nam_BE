<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        summary: 'Tồn kho hiện tại theo SKU/trạng thái (normal/low_stock/overflow) tại kho tổng',
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
            new OA\Response(response: 200, description: 'Danh sách tồn kho (on_hand_qty/available_qty/reserved_qty + status)'),
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

        $rows = $balances->map(function (InventoryBalance $balance) {
            $config = SafeStockConfig::query()
                ->where('product_id', $balance->product_id)
                ->where(function ($q) use ($balance) {
                    $q->where('warehouse_id', $balance->warehouse_id)->orWhereNull('warehouse_id');
                })
                ->orderByRaw('warehouse_id IS NULL')
                ->first();

            $status = $config ? $this->safeStockAlerts->statusFor((float) $balance->available_qty, $config) : 'normal';

            return [
                'warehouse_id' => $balance->warehouse_id,
                'product_id' => $balance->product_id,
                'product_code' => $balance->product?->code,
                'product_name' => $balance->product?->name,
                'on_hand_qty' => $balance->on_hand_qty,
                'available_qty' => $balance->available_qty,
                'reserved_qty' => $balance->reserved_qty,
                'status' => $status,
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
}
