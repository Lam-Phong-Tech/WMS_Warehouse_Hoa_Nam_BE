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

class WarehouseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WarehouseRepositoryInterface $warehouses) {}

    #[OA\Get(
        path: '/api/v1/warehouses', operationId: 'warehouse_list',
        summary: 'Danh sách kho theo data scope của user', security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách kho trong phạm vi được gán'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.view'),
        ]
    )]
    public function index()
    {
        $scopes = $this->user()->warehouseScopeIds();
        $warehouses = $this->warehouses->all();

        if ($scopes !== null) {
            $warehouses = $warehouses->filter(
                fn ($warehouse) => in_array((string) $warehouse->getKey(), $scopes, true)
            )->values();
        }

        return $this->respondSuccess($warehouses);
    }

    #[OA\Get(
        path: '/api/v1/warehouses/{id}', operationId: 'warehouse_detail',
        summary: 'Chi tiết kho trong data scope', security: [['bearerAuth' => []]], tags: ['06. Warehouse'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết kho'),
            new OA\Response(response: 403, description: 'Kho ngoài data scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy kho'),
        ]
    )]
    public function show(string $id)
    {
        $this->assertWarehouseScope($id);
        $warehouse = $this->warehouses->find($id);

        if (! $warehouse) {
            return $this->respondNotFound('Không tìm thấy kho.');
        }

        return $this->respondSuccess($warehouse);
    }

    #[OA\Patch(
        path: '/api/v1/warehouses/{id}', operationId: 'warehouse_update',
        summary: 'Cập nhật kho trong data scope', security: [['bearerAuth' => []]], tags: ['06. Warehouse'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 200),
            new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'contact_person', type: 'string', maxLength: 200, nullable: true),
            new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 403, description: 'Thiếu quyền hoặc kho ngoài data scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy kho'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateWarehouseRequest $request, string $id)
    {
        $this->assertWarehouseScope($id);
        $warehouse = $this->warehouses->find($id);

        if (! $warehouse) {
            return $this->respondNotFound('Không tìm thấy kho.');
        }

        $before = $warehouse->only(['id', 'code', 'name', 'address', 'contact_person', 'phone', 'status']);
        $warehouse = $this->warehouses->update($id, $request->validated());

        AuditLogger::record(
            'UPDATE', 'warehouse', $warehouse->getKey(), auth('api')->id(),
            $request->attributes->get('request_id'), beforeData: $before,
            afterData: $warehouse->only(['id', 'code', 'name', 'address', 'contact_person', 'phone', 'status'])
        );

        return $this->respondSuccess($warehouse, 'Cập nhật kho thành công');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('api')->user();
        return $user;
    }

    private function assertWarehouseScope(string $warehouseId): void
    {
        if (! $this->user()->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Bạn không có quyền truy cập kho ngoài phạm vi được gán.');
        }
    }
}
