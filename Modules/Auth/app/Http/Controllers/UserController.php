<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Http\Requests\AssignUserRoleRequest;
use Modules\Auth\Http\Requests\StoreUserRequest;
use Modules\Auth\Http\Requests\UpdateUserRequest;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Không có thao tác xoá người dùng: tài khoản là chủ thể của audit trail
 * (BR-IAM-01/06, retention >=5 năm) và Annex C không công bố deleteUser.
 * Vô hiệu hoá bằng PATCH status=INACTIVE|LOCKED.
 */
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
            $this->users->paginate($request->all(), (int) $request->input('per_page', 10))
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
                    new OA\Property(property: 'username', type: 'string', maxLength: 100, example: 'nguyenvana'),
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Nguyễn Văn A'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'nguyenvana@gmail.com'),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'Cntt@1234'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'], nullable: true, example: 'ACTIVE'),
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
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: false, description: 'Version hiện tại của user (§8.1 IAM "If-Match on update")', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'username', type: 'string', maxLength: 100, example: 'nguyenvana'),
                new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Nguyễn Văn A'),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'nguyenvana@gmail.com'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
                new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'Cntt@1234'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'], nullable: true, example: 'ACTIVE'),
                new OA\Property(property: 'version', type: 'integer', nullable: true, description: 'Optimistic locking legacy; ưu tiên header If-Match theo §8.1 IAM', example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
            new OA\Response(response: 412, description: 'If-Match/version không khớp — dữ liệu đã bị thay đổi bởi thao tác khác'),
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
        unset($data['version']);
        $this->assertVersionMatches($request, $before);

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

    /**
     * §8.1 IAM: "If-Match on update"; Annex C updateUser công bố 412.
     *
     * Trước đây optimistic lock của IAM đọc `version` trong body và trả 409 —
     * lệch với toàn bộ phần còn lại của hệ thống (header If-Match + 412
     * STALE_VERSION). Body `version` vẫn được chấp nhận để client cũ không vỡ,
     * nhưng cả hai đường nay cùng trả 412.
     */
    private function assertVersionMatches(Request $request, object $current): void
    {
        $expected = trim((string) $request->header('If-Match'));
        if ($expected !== '') {
            $expected = trim(preg_replace('/^W\//', '', $expected) ?? $expected, '" ');
        } elseif ($request->has('version')) {
            $expected = trim((string) $request->input('version'));
        }

        if ($expected === '') {
            return;
        }
        if (! ctype_digit($expected)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên.');
        }
        if ((int) $expected !== (int) ($current->version ?? 0)) {
            ApiError::abort(412, 'STALE_VERSION', 'Người dùng đã bị thay đổi bởi thao tác khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $expected,
                'current_version' => (int) ($current->version ?? 0),
            ]);
        }
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
                    new OA\Property(property: 'role_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['3fa85f64-5717-4562-b3fc-2c963f66afa6', '9b2e8f14-6c3a-4b7d-8e1f-2a5c9d3b7e4f']),
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

        // BR-IAM-08 "scope assignment explicit": dạng `roles[]` cho phép gán kèm
        // scope_ref và cửa sổ hiệu lực; dạng `role_ids[]` giữ nguyên cho client cũ.
        $validated = $request->validated();
        $assignments = $validated['roles'] ?? array_map(
            fn (string $roleId): array => ['role_id' => $roleId],
            $validated['role_ids'] ?? []
        );

        $pivotData = [];
        foreach ($assignments as $assignment) {
            $roleId = (string) $assignment['role_id'];
            $pivot = ['user_role_id' => (string) Str::uuid()];
            foreach (['scope_ref', 'effective_from', 'effective_to'] as $column) {
                if (array_key_exists($column, $assignment) && Schema::hasColumn('user_role', $column)) {
                    $pivot[$column] = $assignment[$column];
                }
            }
            $pivotData[$roleId] = $pivot;
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
