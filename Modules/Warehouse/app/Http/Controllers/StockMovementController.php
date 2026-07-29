<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\StockMovement;
use OpenApi\Attributes as OA;

/** Lịch sử biến động tồn (inventory_movement) — append-only, không cho sửa/xoá. */
class StockMovementController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/stock-movements',
        operationId: 'stock_movement_list',
        summary: 'Lịch sử biến động tồn (phân trang, filter theo warehouse_id/product_id/movement_type)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách movement'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = StockMovement::query()->with(['product', 'warehouse'])->orderByDesc('occurred_at');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->input('movement_type'));
        }

        return $this->respondPaginated($query->paginate(min((int) $request->input('per_page', 15), 100)));
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/{id}',
        operationId: 'stock_movement_detail',
        summary: 'Chi tiết 1 movement',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết movement'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy movement'),
        ]
    )]
    public function show(string $id)
    {
        $movement = StockMovement::with(['product', 'warehouse', 'document'])->find($id);

        return $movement ? $this->respondSuccess($movement) : $this->respondNotFound('Không tìm thấy movement.');
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/{id}/trace-source',
        operationId: 'stock_movement_trace_source',
        summary: 'Truy vết chứng từ gốc, lịch sử duyệt/hạch toán, và reversal/correction liên quan tới movement này',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chứng từ gốc + lịch sử trạng thái + movement gốc (nếu đây là reversal) + reversal (nếu movement này đã bị đảo ngược)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy movement'),
        ]
    )]
    public function traceSource(string $id)
    {
        $movement = StockMovement::with(['product', 'warehouse', 'document.statusHistory', 'document.lines'])->find($id);

        if (! $movement) {
            return $this->respondNotFound('Không tìm thấy movement.');
        }

        $original = $movement->original_movement_id ? StockMovement::find($movement->original_movement_id) : null;
        $reversal = StockMovement::where('original_movement_id', $movement->id)->first();

        return $this->respondSuccess([
            'movement' => $movement,
            'document' => $movement->document,
            'status_history' => $movement->document?->statusHistory,
            'original_movement' => $original,
            'reversal_movement' => $reversal,
        ]);
    }
}
