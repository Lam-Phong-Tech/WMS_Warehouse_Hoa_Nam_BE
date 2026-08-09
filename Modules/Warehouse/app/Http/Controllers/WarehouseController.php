<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Requests\UpdateWarehouseRequest;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Kho Hoà Nam chỉ vận hành 01 kho tổng — không có tạo mới/xoá kho qua API, chỉ tra cứu
 * và cập nhật thông tin (tên/địa chỉ/liên hệ) của kho tổng đã seed sẵn.
 */
class WarehouseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WarehouseRepositoryInterface $warehouses) {}

    #[OA\Get(
        path: '/api/v1/warehouses',
        operationId: 'warehouse_list',
        summary: 'Danh sách kho theo data scope hiện hành',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách kho trong data scope'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.view'),
        ]
    )]
    public function index()
    {
        $user = $this->authenticatedUser();
        $query = $this->warehouses->query();
        $scopes = $user->warehouseScopeIds();

        if ($scopes !== null) {
            $query->whereIn('id', $scopes);
        }

        return $this->respondSuccess($query->get());
    }

    #[OA\Get(
        path: '/api/v1/warehouses/{id}',
        operationId: 'warehouse_detail',
        summary: 'Chi tiết kho trong data scope',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết kho'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Kho ngoài data scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy kho'),
        ]
    )]
    public function show(string $id)
    {
        $this->assertWarehouseAccess($id);
        $warehouse = $this->warehouses->find($id);

        if (! $warehouse) {
            return $this->respondNotFound('Không tìm thấy kho.');
        }

        return $this->respondSuccess($warehouse);
    }

    #[OA\Patch(
        path: '/api/v1/warehouses/{id}',
        operationId: 'warehouse_update',
        summary: 'Sửa tên/địa chỉ/thông tin liên hệ kho trong data scope',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 200),
                new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'contact_person', type: 'string', maxLength: 200, nullable: true),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền hoặc kho ngoài data scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy kho'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateWarehouseRequest $request, string $id)
    {
        $this->assertWarehouseAccess($id);
        $before = $this->warehouses->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy kho.');
        }

        $beforeData = $before->toArray();
        $warehouse = $this->warehouses->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'warehouse',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $warehouse->toArray(),
        );

        return $this->respondSuccess($warehouse, 'Cập nhật kho thành công');
    }

    private function authenticatedUser(): User
    {
        $user = auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Người dùng chưa được xác thực.');
        }

        return $user;
    }

    private function assertWarehouseAccess(string $warehouseId): void
    {
        if (! $this->authenticatedUser()->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Kho yêu cầu nằm ngoài data scope.');
        }
    }
}
