<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Requests\StoreWarehouseRequest;
use Modules\Warehouse\Http\Requests\UpdateWarehouseRequest;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Kho Hoà Nam chỉ vận hành 01 kho tổng ACTIVE.
 *
 * AC-SRS-001 + §8.1 Warehouse: "cho phép tạo/sửa thông tin, địa chỉ và trạng thái
 * của đúng 01 Warehouse; tạo Warehouse thứ hai bị chặn". POST tồn tại theo Annex C
 * createWarehouse nhưng kho ACTIVE thứ hai bị WarehouseRepository trả 409
 * STATE_CONFLICT. Không có xoá kho.
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
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách kho trong data scope'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.view'),
        ]
    )]
    public function index(Request $request)
    {
        $user = $this->authenticatedUser();
        $query = $this->warehouses->query();
        $scopes = $user->warehouseScopeIds();

        if ($scopes !== null) {
            $query->whereIn('id', $scopes);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $this->applyKeyword($query, $request, ['code', 'name']);

        return $this->respondPaginated(
            $query->orderBy('code')->paginate($this->perPage($request))
        );
    }

    #[OA\Post(
        path: '/api/v1/warehouses',
        operationId: 'warehouse_create',
        summary: 'Tạo kho tổng (kho ACTIVE thứ hai bị chặn 409)',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, example: 'KHO-TONG-HN'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Kho tổng Hoà Nam'),
                    new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true, example: '123 Đường Láng, Hà Nội'),
                    new OA\Property(property: 'contact_person', type: 'string', maxLength: 200, nullable: true, example: 'Trần Thị B'),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo kho thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.manage'),
            new OA\Response(response: 409, description: 'Đã tồn tại kho ACTIVE khác (REQ-SRS-001 single warehouse)'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function store(StoreWarehouseRequest $request)
    {
        $warehouse = $this->warehouses->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'warehouse',
            objectId: (string) $warehouse->id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $warehouse->toArray(),
        );

        return $this->respondCreated($warehouse, 'Tạo kho thành công');
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
                new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Kho tổng Hoà Nam'),
                new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true, example: '123 Đường Láng, Hà Nội'),
                new OA\Property(property: 'contact_person', type: 'string', maxLength: 200, nullable: true, example: 'Trần Thị B'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
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

        $validated = $request->validated();
        // REQ-SRS-001 + §8.1: chỉ tồn tại một kho ACTIVE. Trả 409 STATE_CONFLICT
        // thay vì để unique index ném 500.
        if (($validated['status'] ?? null) === 'ACTIVE' && (string) $before->status !== 'ACTIVE') {
            $otherActive = DB::table('warehouses')->where('status', 'ACTIVE')->where('id', '!=', $id)->exists();
            if ($otherActive) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Hệ thống chỉ cho phép một kho ACTIVE; hãy ngừng kích hoạt kho hiện tại trước.');
            }
        }

        $beforeData = $before->toArray();
        $warehouse = $this->warehouses->update($id, $validated);

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
