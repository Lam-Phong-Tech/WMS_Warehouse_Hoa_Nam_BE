<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\InventoryReservation;
use OpenApi\Attributes as OA;

/**
 * Đặt giữ tồn kho (inventory reservations) — chức năng non-MVP, đã sẵn sàng.
 * Hiện cung cấp endpoint read-only; chưa wire vào luồng xuất kho.
 */
class InventoryReservationController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/inventory-reservations',
        operationId: 'inventory_reservation_list',
        summary: 'Danh sách đặt giữ tồn kho (non-MVP, chưa wire vào engine xuất kho)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory / Stock'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'completed', 'cancelled', 'released'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách đặt giữ tồn kho'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = InventoryReservation::with(['warehouse', 'product']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated(
            $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15))
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory-reservations/{id}',
        operationId: 'inventory_reservation_show',
        summary: 'Chi tiết một đặt giữ tồn kho',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory / Stock'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết đặt giữ'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy bản ghi đặt giữ'),
        ]
    )]
    public function show(string $id)
    {
        $reservation = InventoryReservation::with(['warehouse', 'product'])->find($id);

        if (! $reservation) {
            return $this->respondNotFound('Không tìm thấy bản ghi đặt giữ.');
        }

        return $this->respondSuccess($reservation);
    }
}
