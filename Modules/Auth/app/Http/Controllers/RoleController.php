<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Http\Requests\AssignRolePermissionRequest;
use Modules\Auth\Http\Requests\StoreRoleRequest;
use Modules\Auth\Http\Requests\UpdateRoleRequest;
use Modules\Auth\Repositories\Contracts\RoleRepositoryInterface;
use Modules\Auth\Support\AuthCache;
use OpenApi\Attributes as OA;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    #[OA\Get(
        path: '/api/v1/roles',
        operationId: 'role_list',
        summary: 'Danh sách role (phân trang, filter theo keyword/status/scope_type)',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách role'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->roles->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/roles',
        operationId: 'role_create',
        summary: 'Tạo role mới',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role_code', 'role_name'],
                properties: [
                    new OA\Property(property: 'role_code', type: 'string', maxLength: 100, example: 'WAREHOUSE_MANAGER'),
                    new OA\Property(property: 'role_name', type: 'string', maxLength: 255, example: 'Quản lý kho'),
                    new OA\Property(property: 'scope_type', type: 'string', enum: ['SYSTEM', 'WAREHOUSE', 'FUNCTION'], nullable: true, example: 'SYSTEM'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo role thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: role_code đã tồn tại)'),
        ]
    )]
    public function store(StoreRoleRequest $request)
    {
        $role = $this->roles->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'role',
            objectId: $role->role_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $role->toArray(),
        );

        return $this->respondCreated($role, 'Tạo role thành công');
    }

    #[OA\Get(
        path: '/api/v1/roles/{id}',
        operationId: 'role_detail',
        summary: 'Chi tiết role',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết role'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy role'),
        ]
    )]
    public function show(string $id)
    {
        $role = $this->roles->find($id);

        return $role ? $this->respondSuccess($role) : $this->respondNotFound('Không tìm thấy role.');
    }

    #[OA\Patch(
        path: '/api/v1/roles/{id}',
        operationId: 'role_update',
        summary: 'Sửa role',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'role_code', type: 'string', maxLength: 100, example: 'WAREHOUSE_MANAGER'),
                new OA\Property(property: 'role_name', type: 'string', maxLength: 255, example: 'Quản lý kho'),
                new OA\Property(property: 'scope_type', type: 'string', enum: ['SYSTEM', 'WAREHOUSE', 'FUNCTION'], nullable: true, example: 'SYSTEM'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy role'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateRoleRequest $request, string $id)
    {
        $before = $this->roles->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy role.');
        }

        $beforeData = $before->toArray();
        $validated = $request->validated();
        $role = $this->roles->update($id, $validated);

        // RBAC cache (permissionCodes/warehouseScopeIds) key theo user.version và chỉ
        // đọc role qua activeRoleModels() lọc `role.status = 'ACTIVE'`. Đổi status role
        // (vd: vô hiệu hoá) không tự động bump cache của user đang giữ role đó — nếu
        // không bump ở đây, user vẫn giữ quyền cũ tới 300s sau khi role bị disable,
        // vi phạm yêu cầu "revoke có hiệu lực ngay" (mục 11). Chỉ cần bump khi field
        // ảnh hưởng đến resolution RBAC (status) thực sự đổi.
        if (array_key_exists('status', $validated) && $validated['status'] !== $beforeData['status']) {
            AuthCache::bumpVersionForUsersWithRole($id);
        }

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'role',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $role->toArray(),
        );

        return $this->respondSuccess($role, 'Cập nhật role thành công');
    }

    #[OA\Delete(
        path: '/api/v1/roles/{id}',
        operationId: 'role_delete',
        summary: 'Xoá role (bị chặn nếu đang gán cho user)',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Xoá thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy role'),
            new OA\Response(response: 409, description: 'Role đang được gán cho người dùng, không thể xoá'),
        ]
    )]
    public function destroy(Request $request, string $id)
    {
        $role = $this->roles->find($id);

        if (! $role) {
            return $this->respondNotFound('Không tìm thấy role.');
        }

        $beforeData = $role->toArray();

        try {
            $this->roles->delete($id);
        } catch (QueryException) {
            return $this->respondConflict('Role đang được gán cho người dùng, không thể xoá.', [], 'REFERENCE_EXISTS');
        }

        AuditLogger::record(
            action: 'DELETE',
            objectType: 'role',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
        );

        return $this->respondSuccess(null, 'Xoá role thành công');
    }

    #[OA\Post(
        path: '/api/v1/roles/{id}/permissions',
        operationId: 'role_assign_permissions',
        summary: 'Gán danh sách permission cho role (thay thế toàn bộ permission hiện có)',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permission_ids'],
                properties: [
                    new OA\Property(property: 'permission_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['3fa85f64-5717-4562-b3fc-2c963f66afa6', '7c9e6679-7425-40de-944b-e07fc1f90ae7']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gán permission thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy role'),
            new OA\Response(response: 422, description: 'permission_ids không hợp lệ'),
        ]
    )]
    #[OA\Put(
        path: '/api/v1/roles/{id}/permissions',
        operationId: 'role_replace_permissions',
        summary: 'Thay thế toàn bộ tập permission của role (Annex C replaceRolePermissions)',
        security: [['bearerAuth' => []]],
        tags: ['03. Roles'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permission_ids'],
                properties: [
                    new OA\Property(property: 'permission_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['3fa85f64-5717-4562-b3fc-2c963f66afa6', '7c9e6679-7425-40de-944b-e07fc1f90ae7']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gán permission thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền role.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy role'),
            new OA\Response(response: 422, description: 'permission_ids không hợp lệ'),
        ]
    )]
    public function assignPermissions(AssignRolePermissionRequest $request, string $id)
    {
        $role = $this->roles->find($id);

        if (! $role) {
            return $this->respondNotFound('Không tìm thấy role.');
        }

        $beforePermissionIds = $role->permissions()->pluck('permission.permission_id')->all();

        $pivotData = [];
        foreach ($request->validated()['permission_ids'] as $permissionId) {
            $pivotData[$permissionId] = ['role_permission_id' => (string) Str::uuid()];
        }
        $role->permissions()->sync($pivotData);

        // Permission caches are keyed by user version (User::permissionCodes, TTL
        // 300s). Bump version của mọi user đang giữ role để thu hồi/gán quyền có hiệu
        // lực ngay request kế tiếp (BR-IAM: backend enforcement, least privilege).
        AuthCache::bumpVersionForUsersWithRole($id);

        AuditLogger::record(
            action: 'ASSIGN_PERMISSIONS',
            objectType: 'role',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: ['permission_ids' => $beforePermissionIds],
            afterData: ['permission_ids' => array_keys($pivotData)],
        );

        return $this->respondSuccess($role->load('permissions'), 'Gán permission cho role thành công');
    }
}
