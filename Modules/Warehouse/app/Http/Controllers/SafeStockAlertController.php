<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\SafeStockAlertHistory;
use OpenApi\Attributes as OA;

class SafeStockAlertController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/safe-stock-alerts',
        operationId: 'safe_stock_alert_list',
        summary: 'Lịch sử cảnh báo tồn kho an toàn (append-only, chỉ ghi khi trạng thái normal/low_stock/overflow thực sự đổi)',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lịch sử cảnh báo'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = SafeStockAlertHistory::query();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }

    #[OA\Get(
        path: '/api/v1/safe-stock-alerts/history',
        operationId: 'safe_stock_alert_history',
        summary: 'Alias lịch sử cảnh báo tồn kho an toàn (giống index, giữ tương thích đường dẫn cũ)',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lịch sử cảnh báo'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.view'),
        ]
    )]
    public function history(Request $request)
    {
        return $this->index($request);
    }
}
