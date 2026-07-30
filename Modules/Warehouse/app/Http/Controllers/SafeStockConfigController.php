<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\StoreSafeStockConfigRequest;
use Modules\Warehouse\Http\Requests\UpdateSafeStockConfigRequest;
use Modules\Warehouse\Repositories\Contracts\SafeStockConfigRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Chỉ CRUD định mức — KHÔNG tự đánh giá cảnh báo tại đây. Đánh giá cảnh báo chỉ chạy khi tồn
 * kho thực sự đổi (StockLedger sau mỗi movement), để lịch sử cảnh báo phản ánh đúng sự kiện
 * đổi tồn, không phải sự kiện cấu hình định mức.
 */
class SafeStockConfigController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SafeStockConfigRepositoryInterface $configs) {}

    #[OA\Get(
        path: '/api/v1/safe-stock-configs',
        operationId: 'safe_stock_config_list',
        summary: 'Danh sách định mức tồn kho an toàn (min/max theo sản phẩm, tuỳ chọn theo kho)',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách định mức'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated($this->configs->paginate($request->all(), (int) $request->input('per_page', 15)));
    }

    #[OA\Post(
        path: '/api/v1/safe-stock-configs',
        operationId: 'safe_stock_config_create',
        summary: 'Tạo định mức tồn kho an toàn',
        description: 'warehouse_id để trống nghĩa là định mức áp dụng toàn hệ thống (không giới hạn theo kho).',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'effective_from'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'min_qty', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'max_qty', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
                    new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo định mức tồn kho an toàn thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function store(StoreSafeStockConfigRequest $request)
    {
        $config = $this->configs->create($request->validated());

        return $this->respondCreated($config, 'Tạo định mức tồn kho an toàn thành công');
    }

    #[OA\Get(
        path: '/api/v1/safe-stock-configs/{id}',
        operationId: 'safe_stock_config_detail',
        summary: 'Chi tiết định mức tồn kho an toàn',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết định mức'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy định mức'),
        ]
    )]
    public function show(string $id)
    {
        $config = $this->configs->find($id);

        return $config ? $this->respondSuccess($config) : $this->respondNotFound('Không tìm thấy định mức.');
    }

    #[OA\Patch(
        path: '/api/v1/safe-stock-configs/{id}',
        operationId: 'safe_stock_config_update',
        summary: 'Sửa định mức tồn kho an toàn',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'min_qty', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'max_qty', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
                new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy định mức'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateSafeStockConfigRequest $request, string $id)
    {
        if (! $this->configs->find($id)) {
            return $this->respondNotFound('Không tìm thấy định mức.');
        }

        $config = $this->configs->update($id, $request->validated());

        return $this->respondSuccess($config, 'Cập nhật định mức tồn kho an toàn thành công');
    }

    #[OA\Delete(
        path: '/api/v1/safe-stock-configs/{id}',
        operationId: 'safe_stock_config_delete',
        summary: 'Xoá định mức tồn kho an toàn',
        security: [['bearerAuth' => []]],
        tags: ['08. Safe Stock'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Xoá thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền safe_stock.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy định mức'),
        ]
    )]
    public function destroy(string $id)
    {
        if (! $this->configs->find($id)) {
            return $this->respondNotFound('Không tìm thấy định mức.');
        }

        $this->configs->delete($id);

        return $this->respondSuccess(null, 'Xoá định mức tồn kho an toàn thành công');
    }
}
