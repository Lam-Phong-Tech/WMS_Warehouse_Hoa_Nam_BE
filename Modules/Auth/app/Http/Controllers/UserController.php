<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Database\SchemaCache;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Events\UserAccountChanged;
use Modules\Auth\Http\Requests\AssignUserRoleRequest;
use Modules\Auth\Http\Requests\GrantUserPermissionRequest;
use Modules\Auth\Http\Requests\RevokeUserPermissionRequest;
use Modules\Auth\Http\Requests\StoreUserRequest;
use Modules\Auth\Http\Requests\UpdateUserRequest;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use Modules\Auth\Support\AuthCache;
use Modules\Auth\Support\PermissionDelegation;
use Modules\Auth\Support\ProfileAvatar;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RoleEffectiveWindow;
use Modules\Auth\Support\UserBusinessProfile;
use OpenApi\Attributes as OA;

/**
 * Không có thao tác xoá người dùng: tài khoản là chủ thể của audit trail
 * (BR-IAM-01/06, retention >=5 năm) và Annex C không công bố deleteUser.
 * Vô hiệu hoá bằng PATCH status=INACTIVE|LOCKED.
 */
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

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
        $page = $this->users->paginate($request->all(), (int) $request->input('per_page', 10));
        // Load only metadata for this page, never the image bytes or one query per user.
        $avatars = DB::table('user_profile_avatars')->whereIn('user_id', $page->getCollection()->pluck('user_id'))
            ->get(['user_id', 'mime_type', 'size', 'updated_at'])->keyBy('user_id');
        foreach ($page->getCollection() as $user) {
            $user->setAttribute('avatar', ProfileAvatar::describe($avatars->get($user->user_id), '/api/v1/users/'.$user->user_id.'/avatar'));
        }

        return $this->respondPaginated($page);
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
                required: ['display_name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', maxLength: 100, description: 'Khóa IAM nội bộ tùy chọn; nếu bỏ qua hệ thống tự cấp.', example: 'nguyenvana'),
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Nguyễn Văn A'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 200, example: 'nguyenvana@gmail.com'),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, description: 'Mật khẩu ban đầu do quản trị viên đặt; tài liệu API không công bố giá trị mẫu.'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOCKED'], nullable: true, example: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo người dùng thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (ví dụ: thiếu email hoặc email đã tồn tại)'),
        ]
    )]
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        // Username remains an internal IAM key; new clients identify accounts by email.
        $data['username'] ??= 'u-'.Str::uuid()->toString();
        $hasTitle = array_key_exists('job_title', $data);
        $jobTitle = $data['job_title'] ?? null;
        unset($data['job_title']);
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);
        $data['status'] = $data['status'] ?? 'ACTIVE';
        $data['version'] = 1;

        // Cấp tài khoản nhân viên: admin đặt mật khẩu ban đầu -> bắt buộc đổi
        // ngay lần đăng nhập đầu, và tự khoá nếu quá 7 ngày chưa đăng nhập lần
        // nào (xem EnforcePasswordChangeRequired + LockExpiredFirstLoginAccounts).
        $data['must_change_password'] = true;
        $data['first_login_deadline_at'] = now()->addDays(7);

        $user = DB::transaction(function () use ($data, $hasTitle, $jobTitle, $request) {
            $user = $this->users->create($data);
            if ($hasTitle) {
                UserBusinessProfile::put($user->user_id, $jobTitle, $request->user()?->user_id);
            }

            return $user;
        });

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'user',
            objectId: $user->user_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $user->makeHidden('password_hash')->toArray(),
        );

        event(new UserAccountChanged($user->user_id, 'CREATE', $request->user()?->user_id));

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

        return $user ? $this->respondSuccess([...$user->toArray(),
            'business_profile' => UserBusinessProfile::read($id),
            'avatar' => ProfileAvatar::metadata($id, '/api/v1/users/'.$id.'/avatar')])
            : $this->respondNotFound('Không tìm thấy người dùng.');
    }

    #[OA\Get(
        path: '/api/v1/users/{id}/avatar',
        operationId: 'user_avatar',
        summary: 'Ảnh đại diện người dùng (quyền iam.user.view)',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Ảnh đại diện'), new OA\Response(response: 403, description: 'Không có quyền'), new OA\Response(response: 404, description: 'Không có ảnh')]
    )]
    public function avatar(string $id)
    {
        if ($this->users->find($id) === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy người dùng.');
        }

        return ProfileAvatar::response($id);
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
                new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 200, example: 'nguyenvana@gmail.com'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0912345678'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, description: 'Mật khẩu ban đầu do quản trị viên đặt; tài liệu API không công bố giá trị mẫu.'),
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
        $hasTitle = array_key_exists('job_title', $data);
        $jobTitle = $data['job_title'] ?? null;
        unset($data['job_title']);
        unset($data['version']);
        $this->assertVersionMatches($request, $before);

        $passwordWasReset = array_key_exists('password', $data);

        if ($passwordWasReset) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $data['version'] = $before->version + 1;

        $user = DB::transaction(function () use ($id, $data, $hasTitle, $jobTitle, $request) {
            $user = $this->users->update($id, $data);
            if ($user !== null && $hasTitle) {
                UserBusinessProfile::put($id, $jobTitle, $request->user()?->user_id);
            }

            return $user;
        });

        // WWHN-SEC-02 (SCR-IAM-03): admin đặt lại mật khẩu hộ là đường xử lý sự
        // cố chiếm tài khoản. Trước đây thao tác này KHÔNG thu hồi gì cả — cả
        // refresh token lẫn access token của phiên đang bị chiếm đều sống tiếp,
        // nên "đặt lại mật khẩu cho nhân viên bị lộ tài khoản" không thực sự
        // đuổi được kẻ tấn công ra.
        //
        // markPasswordChangedNow() (không đi qua $data của repository) là CÓ CHỦ
        // Ý: password_changed_at nằm ngoài $fillable để không bao giờ nhận giá
        // trị từ payload client, nên $record->update() sẽ âm thầm bỏ qua nó.
        if ($passwordWasReset && $user !== null) {
            $user->markPasswordChangedNow();
            $this->refreshTokens->revokeAllForUser($id);
            app(\Modules\Auth\Support\TrustedDeviceService::class)->revokeAllForUser($id);
        }

        // WWHN-SEC-09: vô hiệu hoá/khoá tài khoản phải giết luôn các phiên đang
        // sống, không chỉ chặn ở middleware. EnforceActiveAccountStatus đã chặn
        // access token theo từng request và rotate() từ chối user non-ACTIVE,
        // nhưng các hàng refresh token vẫn "sống" trong DB cho tới khi bị đem ra
        // dùng — thu hồi ngay tại đây để trạng thái DB phản ánh đúng quyết định
        // của admin và không phụ thuộc vào việc kẻ giữ token có quay lại hay không.
        $statusInPayload = $data['status'] ?? null;
        if ($statusInPayload !== null && $statusInPayload !== 'ACTIVE' && $before->status === 'ACTIVE') {
            $this->refreshTokens->revokeAllForUser($id);
            app(\Modules\Auth\Support\TrustedDeviceService::class)->revokeAllForUser($id);
        }

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $user->makeHidden('password_hash')->toArray(),
        );

        event(new UserAccountChanged($id, 'UPDATE', $request->user()?->user_id));

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
                required: ['role_ids', 'reason'],
                properties: [
                    new OA\Property(property: 'role_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['3fa85f64-5717-4562-b3fc-2c963f66afa6', '9b2e8f14-6c3a-4b7d-8e1f-2a5c9d3b7e4f']),
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000, example: 'Điều chuyển nhân sự sang phòng ban khác'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gán role thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
            new OA\Response(response: 409, description: 'Không được phép tự thay đổi vai trò của chính mình (SCR-IAM-01, no self-escalation)'),
            new OA\Response(response: 422, description: 'role_ids không hợp lệ hoặc thiếu reason'),
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
                if (array_key_exists($column, $assignment) && SchemaCache::hasRelationColumn('user_role', $column)) {
                    // Ngày lịch YYYY-MM-DD: "từ" = đầu ngày, "đến" = cuối ngày theo
                    // múi giờ ứng dụng (xem RoleEffectiveWindow) — không để
                    // PostgreSQL tự hiểu thành 00:00 UTC.
                    $pivot[$column] = match ($column) {
                        'effective_from' => RoleEffectiveWindow::normalizeFrom($assignment[$column]),
                        'effective_to' => RoleEffectiveWindow::normalizeTo($assignment[$column]),
                        default => $assignment[$column],
                    };
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
            reason: $validated['reason'],
        );

        return $this->respondSuccess($user->load('roles'), 'Gán role cho người dùng thành công');
    }

    #[OA\Get(
        path: '/api/v1/users/{id}/permissions',
        operationId: 'user_list_permission_overrides',
        summary: 'Danh sách quyền cấp riêng (ngoài role) cho người dùng',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách quyền cấp riêng'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.view'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng'),
        ]
    )]
    public function listPermissionOverrides(string $id)
    {
        $user = $this->users->find($id);

        if (! $user) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        return $this->respondSuccess($user->grantedPermissions()->get());
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/permissions',
        operationId: 'user_grant_permission',
        summary: 'Cấp thêm một quyền riêng cho người dùng, ngoài quyền theo role (chỉ cấp thêm, không thu hồi quyền của role)',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permission_id', 'reason'],
                properties: [
                    new OA\Property(property: 'permission_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000, example: 'Hỗ trợ tạm thời cho dự án X'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cấp quyền thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng hoặc permission'),
            new OA\Response(response: 409, description: 'Không được phép tự cấp quyền cho chính mình (SCR-IAM-01, no self-escalation)'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc quyền đã được cấp trước đó'),
        ]
    )]
    public function grantPermission(GrantUserPermissionRequest $request, string $id)
    {
        $user = $this->users->find($id);

        if (! $user) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        $validated = $request->validated();

        if ($user->grantedPermissions()->where('permission.permission_id', $validated['permission_id'])->exists()) {
            ApiError::abort(422, 'PERMISSION_ALREADY_GRANTED', 'Quyền này đã được cấp riêng cho người dùng.');
        }

        // SCR-IAM-02: quyền cấp riêng là đường cấp phát thứ hai, phải chịu đúng
        // ràng buộc "không cấp được thứ mình không có" như ma trận role.
        PermissionDelegation::assertMayDelegatePermissionIds(
            $request->user(),
            [(string) $validated['permission_id']],
        );

        $userPermissionId = (string) Str::uuid();
        $user->grantedPermissions()->attach($validated['permission_id'], [
            'user_permission_id' => $userPermissionId,
            'granted_by' => $request->user()?->user_id,
            'granted_at' => now(),
        ]);

        AuthCache::bumpVersionForUser($user);

        AuditLogger::record(
            action: 'GRANT_USER_PERMISSION',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: ['permission_id' => $validated['permission_id']],
            reason: $validated['reason'],
        );

        return $this->respondCreated($user->grantedPermissions()->get(), 'Cấp quyền riêng cho người dùng thành công');
    }

    #[OA\Delete(
        path: '/api/v1/users/{id}/permissions/{permissionId}',
        operationId: 'user_revoke_permission',
        summary: 'Thu hồi một quyền đã cấp riêng cho người dùng',
        security: [['bearerAuth' => []]],
        tags: ['02. Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'permissionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000, example: 'Kết thúc dự án X, thu hồi quyền tạm thời'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Thu hồi quyền thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy người dùng hoặc quyền cấp riêng này chưa được cấp'),
            new OA\Response(response: 409, description: 'Không được phép tự thu hồi quyền của chính mình (SCR-IAM-01, no self-escalation)'),
            new OA\Response(response: 422, description: 'Thiếu reason'),
        ]
    )]
    public function revokePermission(RevokeUserPermissionRequest $request, string $id, string $permissionId)
    {
        $user = $this->users->find($id);

        if (! $user) {
            return $this->respondNotFound('Không tìm thấy người dùng.');
        }

        if (! $user->grantedPermissions()->where('permission.permission_id', $permissionId)->exists()) {
            return $this->respondNotFound('Quyền cấp riêng này chưa được cấp cho người dùng.');
        }

        // SCR-IAM-02: thu hồi cũng nằm trong phạm vi uỷ quyền — không cho người
        // có quyền thấp hơn gỡ mất quyền cao hơn mình khỏi tài khoản khác.
        PermissionDelegation::assertMayDelegatePermissionIds($request->user(), [$permissionId]);

        $user->grantedPermissions()->detach($permissionId);

        AuthCache::bumpVersionForUser($user);

        AuditLogger::record(
            action: 'REVOKE_USER_PERMISSION',
            objectType: 'user',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: ['permission_id' => $permissionId],
            reason: $request->validated()['reason'],
        );

        return $this->respondSuccess($user->grantedPermissions()->get(), 'Thu hồi quyền cấp riêng thành công');
    }
}
