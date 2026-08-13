<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Http\Requests\AssignUserRoleRequest;
use Modules\Auth\Http\Requests\StoreUserRequest;
use Modules\Auth\Http\Requests\UpdateUserRequest;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserRepositoryInterface $users) {}

    #[OA\Get(
        path: '/api/v1/users',
        operationId: 'user_list',
        summary: 'Danh sách người dùng (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách người dùng'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->users->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/users',
        operationId: 'user_create',
        summary: 'Tạo người dùng mới',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username', 'display_name', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', maxLength: 100),
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
                    new OA\Property(property: 'password', type: 'string', minLength: 8),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo người dùng thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: username đã tồn tại)'),
        ]
    )]
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);
        $data['status'] = $data['status'] ?? 'ACTIVE';
        $data['version'] = 1;

        $user = $this->users->create($data);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'user',
            objectId: $user->user_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $user->makeHidden('password_hash')->toArray(),
        );

        return $this->respondCreated($user, 'Tạo người dùng thành công');
    }

    #[OA\Get(
        path: '/api/v1/users/{id}',
        operationId: 'user_detail',
        summary: 'Chi tiết người dùng',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết người dùng'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
        ]
    )]
    public function show(string $id)
    {
        $user = $this->users->find($id);

        return $user ? $this->respondSuccess($user) : $this->respondNotFound('Không tìm thấy người dùng.');
    }

    #[OA\Patch(
        path: '/api/v1/users/{id}',
        operationId: 'user_update',
        summary: 'Sửa người dùng',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'username', type: 'string', maxLength: 100),
                new OA\Property(property: 'display_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
                new OA\Property(property: 'password', type: 'string', minLength: 8),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'], nullable: true),
                new OA\Property(property: 'version', type: 'integer', nullable: true, description: 'Optimistic locking: gửi kèm version hiện tại để tránh ghi đè thay đổi song song'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
            new OA\Response(response: 409, description: 'version không khớp — dữ liệu đã bị thay đổi bởi thao tác khác'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateUserRequest $request, string $id)
    {
        $before = $this->users->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        $beforeData = $before->makeHidden('password_hash')->toArray();

        $data = $request->validated();

        if (array_key_exists('version', $data)) {
            $expectedVersion = $data['version'];
            unset($data['version']);

            if ((int) $before->version !== (int) $expectedVersion) {
                return $this->respondConflict(
                    'Người dùng đã bị thay đổi bởi thao tác khác, vui lòng tải lại dữ liệu.',
                    [],
                    'STATE_CONFLICT'
                );
            }
        }

        if (array_key_exists('password', $data)) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $data['version'] = $before->version + 1;

        $user = $this->users->update($id, $data);

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $user->makeHidden('password_hash')->toArray(),
        );

        return $this->respondSuccess($user, 'Cập nhật người dùng thành công');
    }

    #[OA\Delete(
        path: '/api/v1/users/{id}',
        operationId: 'user_delete',
        summary: 'Xoá người dùng (soft delete, bị chặn nếu đang được tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Xoá thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
            new OA\Response(response: 409, description: 'Đang được tham chiếu, không thể xoá'),
        ]
    )]
    public function destroy(Request $request, string $id)
    {
        $user = $this->users->find($id);

        if (! $user) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        $beforeData = $user->makeHidden('password_hash')->toArray();

        try {
            $this->users->delete($id);
        } catch (QueryException) {
            return $this->respondConflict('Người dùng đang được tham chiếu, không thể xoá.', [], 'REFERENCE_EXISTS');
        }

        AuditLogger::record(
            action: 'DELETE',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
        );

        return $this->respondSuccess(null, 'Xoá người dùng thành công');
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/roles',
        operationId: 'user_assign_roles',
        summary: 'Gán danh sách role cho người dùng (thay thế toàn bộ role hiện có)',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role_ids'],
                properties: [
                    new OA\Property(property: 'role_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gán role thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
            new OA\Response(response: 409, description: 'Không được phép tự thay đổi vai trò của chính mình (SCR-IAM-01, no self-escalation)'),
            new OA\Response(response: 422, description: 'role_ids không hợp lệ'),
        ]
    )]
    public function assignRoles(AssignUserRoleRequest $request, string $id)
    {
        $user = $this->users->find($id);

        if (! $user) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        $beforeRoleIds = $user->roles()->pluck('role.role_id')->all();
        $beforeVersion = (int) $user->version;

        $pivotData = [];
        foreach ($request->validated()['role_ids'] as $roleId) {
            $pivotData[$roleId] = ['user_role_id' => (string) Str::uuid()];
        }
        $user->roles()->sync($pivotData);

        // Permission/scope caches are keyed by user version. Bump it whenever
        // role membership changes so the very next request resolves fresh RBAC.
        $user->version = $beforeVersion + 1;
        $user->save();

        AuditLogger::record(
            action: 'ASSIGN_ROLES',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: ['role_ids' => $beforeRoleIds, 'version' => $beforeVersion],
            afterData: ['role_ids' => array_keys($pivotData), 'version' => (int) $user->version],
        );

        return $this->respondSuccess($user->load('roles'), 'Gán role cho người dùng thành công');
    }
}
