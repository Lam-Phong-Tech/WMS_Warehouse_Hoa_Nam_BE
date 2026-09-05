<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RateLimitResponse;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/login', operationId: 'auth_login',
        summary: 'Đăng nhập bằng email và password',
        description: 'Credential admin canonical dùng cho DEV/UAT: admin@gmail.com / Cntt@1234.',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@gmail.com'),
                new OA\Property(
                    property: 'password',
                    type: 'string',
                    format: 'password',
                    example: 'Cntt@1234',
                    description: 'Mật khẩu canonical của tài khoản admin được AuthDatabaseSeeder tạo/cập nhật.'
                ),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đăng nhập thành công'),
            new OA\Response(response: 401, description: 'Sai định danh hoặc mật khẩu'),
            new OA\Response(response: 403, description: 'Tài khoản bị khoá/ngừng hoạt động'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
            new OA\Response(response: 429, description: 'Vượt ngưỡng đăng nhập sai liên tiếp'),
        ]
    )]
    public function login(LoginRequest $request)
    {
        if ($request->filled('username')) {
            $credentials = ['username' => (string) $request->input('username'), 'password' => (string) $request->input('password')];
        } else {
            $credentials = ['email' => (string) $request->input('email'), 'password' => (string) $request->input('password')];
        }

        // WWHN-289 (DEV289-P1-04): trước đây /auth/login không có bất kỳ giới hạn
        // nào — dò mật khẩu không giới hạn tốc độ. Chỉ đếm lần THẤT BẠI và khoá
        // theo (IP + định danh đăng nhập) nên đăng nhập đúng không bao giờ bị
        // chặn và một IP dùng chung (NAT trong kho) không tự khoá lẫn nhau.
        $throttleKey = $this->loginThrottleKey($request, $credentials);
        $maxAttempts = (int) config('rate_limit.auth_login_per_minute', 10);
        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);
            $this->auditLogger->log(
                'LOGIN_THROTTLED', 'app_user', null, null,
                $this->currentRequestId(), [], ['retry_after_seconds' => $retryAfter],
                'Too many failed login attempts',
                result: 'FAILED', errorCode: 'RATE_LIMIT_EXCEEDED',
            );

            event(new \Modules\Auth\Events\SecurityAlertRaised(
                'LOGIN_THROTTLED',
                null,
                'Phát hiện nhiều lần đăng nhập sai liên tiếp; một định danh đã bị tạm khoá thử đăng nhập.',
            ));

            return RateLimitResponse::make(
                $request,
                ['Retry-After' => (string) $retryAfter],
                'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau '.$retryAfter.' giây.',
            );
        }

        if (! $accessToken = auth('api')->attempt($credentials)) {
            if ($maxAttempts > 0) {
                RateLimiter::hit($throttleKey, 60);
            }
            $user = $request->filled('username')
                ? User::query()->where('username', $credentials['username'])->first()
                : User::query()->where('email', $credentials['email'] ?? null)->first();

            // Chỉ ghi diagnostic không nhạy cảm. Tuyệt đối không log password,
            // password hash/fingerprint hoặc token. `account_found=true` + ACTIVE
            // giúp phân biệt ngay sai credential/request với lỗi account lifecycle
            // khi đối chiếu HTTP UAT và Laravel CLI mà không mở đường bypass auth.
            $logData = $credentials;
            unset($logData['password']);
            $logData['identity_field'] = $request->filled('username') ? 'username' : 'email';
            $logData['account_found'] = $user !== null;
            $logData['account_status'] = $user?->status;
            $logData['content_type'] = $request->header('Content-Type');

            $this->auditLogger->log(
                'LOGIN_FAILED',
                'app_user',
                $user?->user_id,
                $user?->user_id,
                $this->currentRequestId(),
                [],
                $logData,
                'Invalid credentials',
                result: 'FAILED',
                errorCode: 'UNAUTHENTICATED',
            );

            return $this->respondError('Email, tài khoản hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = auth('api')->user();

        if (! $this->credentialsMatchExactly($user, $credentials)) {
            auth('api')->logout();
            if ($maxAttempts > 0) {
                RateLimiter::hit($throttleKey, 60);
            }

            $logData = $credentials;
            unset($logData['password']);

            $this->auditLogger->log(
                'LOGIN_FAILED',
                'app_user',
                $user->user_id,
                $user->user_id,
                $this->currentRequestId(),
                [],
                $logData,
                'Credential identifier case or value mismatch',
                result: 'FAILED',
                errorCode: 'UNAUTHENTICATED',
            );

            return $this->respondError('Email, tài khoản hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        if ($user->status !== 'ACTIVE') {
            auth('api')->logout();
            // Tài khoản INACTIVE/LOCKED vẫn tính là lần thử hỏng: nếu không đếm,
            // kẻ tấn công dò được mật khẩu đúng của tài khoản đã khoá vô hạn lần.
            if ($maxAttempts > 0) {
                RateLimiter::hit($throttleKey, 60);
            }
            $this->auditLogger->log(
                'LOGIN_DENIED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], ['status' => $user->status],
                'Inactive or locked account',
                // DENIED chứ không phải FAILED: đây là từ chối truy cập (403),
                // theo enum ERD §7 audit_logs.result CHECK SUCCESS/FAILED/DENIED.
                result: 'DENIED', errorCode: 'ACCESS_DENIED',
            );

            event(new \Modules\Auth\Events\SecurityAlertRaised(
                'LOGIN_DENIED',
                $user->user_id,
                "Tài khoản {$user->username} bị từ chối đăng nhập vì đang ở trạng thái {$user->status}.",
            ));

            // Cấp tài khoản nhân viên: phân biệt khoá do admin với tự khoá vì quá
            // 7 ngày chưa đăng nhập lần đầu (LockExpiredFirstLoginAccounts) để
            // người dùng biết cần liên hệ ai thay vì thấy message chung chung.
            $lockMessage = ($user->status === 'LOCKED' && $user->must_change_password && $user->last_login_at === null)
                ? 'Tài khoản đã bị khoá do không đăng nhập lần đầu trong vòng 7 ngày kể từ khi được cấp. Vui lòng liên hệ quản trị viên để được cấp lại mật khẩu.'
                : 'Tài khoản đang bị khoá hoặc ngừng hoạt động.';

            return $this->respondError($lockMessage, [], 403, 'ACCESS_DENIED');
        }

        // Đăng nhập thành công xoá bộ đếm để người dùng hợp lệ không bị phạt oan.
        RateLimiter::clear($throttleKey);
        $user->forceFill(['last_login_at' => now()])->save();

        $refreshToken = $this->refreshTokens->issue($user, $request);
        $this->auditLogger->log('LOGIN', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($user),
        ], 'Đăng nhập thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh', operationId: 'auth_refresh',
        summary: 'Xoay vòng refresh token từ JSON hoặc HttpOnly browser cookie',
        description: 'Browser có thể bỏ trống body sau F5; server dùng HttpOnly refresh cookie. Swagger/Postman/non-browser client vẫn có thể gửi refresh_token trong JSON body. Token cũ bị thu hồi và replay sẽ khóa toàn bộ phiên.',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(
                property: 'refresh_token',
                type: 'string',
                nullable: true,
                description: 'Tuỳ chọn với browser có HttpOnly refresh cookie; bắt buộc nếu client không dùng cookie.',
                example: '4f2b8a6d1c3e9f7a5b8d2e6c4a9f1b7d3e8c6a2f4b9d7e1c5a3f8b6d4e2c9a7f'
            )]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Làm mới token thành công'),
            new OA\Response(response: 401, description: 'Refresh token không hợp lệ, hết hạn hoặc bị replay'),
            new OA\Response(response: 422, description: 'Không có refresh token trong JSON body hoặc browser cookie'),
        ]
    )]
    public function refresh(Request $request)
    {
        $request->validate(['refresh_token' => ['required', 'string', 'max:255']]);
        $rawToken = (string) $request->input('refresh_token');
        $rotated = $this->refreshTokens->rotate($rawToken, $request);

        if ($rotated === null) {
            $this->auditLogger->log(
                'TOKEN_REFRESH_FAILED', 'refresh_token', null, null,
                $this->currentRequestId(), [], ['token_fingerprint' => substr(hash('sha256', $rawToken), 0, 12)],
                'Invalid, expired, revoked or replayed refresh token',
                result: 'FAILED', errorCode: 'UNAUTHENTICATED',
            );

            return $this->respondError('Refresh token không hợp lệ hoặc đã hết hạn.', [], 401, 'UNAUTHENTICATED');
        }

        $user = $rotated['user'];
        $accessToken = JWTAuth::fromUser($user);
        $this->auditLogger->log('TOKEN_REFRESH', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $rotated['raw'],
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($user),
        ], 'Làm mới token thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/logout', operationId: 'auth_logout',
        summary: 'Đăng xuất, thu hồi refresh token và huỷ access token hiện tại',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(
                property: 'refresh_token',
                type: 'string',
                nullable: true,
                description: 'Tuỳ chọn với browser có HttpOnly refresh cookie.',
                example: '4f2b8a6d1c3e9f7a5b8d2e6c4a9f1b7d3e8c6a2f4b9d7e1c5a3f8b6d4e2c9a7f'
            ),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Đăng xuất thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'refresh_token sai kiểu dữ liệu'),
        ]
    )]
    public function logout(Request $request)
    {
        $request->validate(['refresh_token' => ['nullable', 'string', 'max:255']]);
        /** @var User $user */
        $user = auth('api')->user();

        if ($request->filled('refresh_token')) {
            $this->refreshTokens->revoke((string) $request->input('refresh_token'));
        } else {
            $this->refreshTokens->revokeAllForUser($user->user_id);
        }

        auth('api')->logout();
        $this->auditLogger->log('LOGOUT', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess(null, 'Đăng xuất thành công');
    }

    #[OA\Get(
        path: '/api/v1/auth/me', operationId: 'auth_me',
        summary: 'Thông tin tài khoản, role, scope và permission hiện hành',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Thông tin tài khoản'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function me(Request $request)
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->respondSuccess([
            ...$this->userProfilePayload($user),
            ...$this->userAuthorizationPayload($user),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/change-password', operationId: 'auth_change_password',
        summary: 'Đổi mật khẩu tài khoản hiện tại (thu hồi toàn bộ refresh token)',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'new_password'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password', example: 'Cntt@1234'),
                new OA\Property(property: 'new_password', type: 'string', format: 'password', minLength: 8, example: 'NewCntt@2026'),
                new OA\Property(property: 'new_password_confirmation', type: 'string', format: 'password', example: 'NewCntt@2026'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đổi mật khẩu thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'Mật khẩu hiện tại sai hoặc dữ liệu không hợp lệ'),
        ]
    )]
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            return $this->respondError('Mật khẩu hiện tại không đúng.', [
                'current_password' => ['Mật khẩu hiện tại không đúng.'],
            ], 422, 'VALIDATION_ERROR');
        }

        $user->forceFill([
            'password_hash' => Hash::make($data['new_password']),
            'must_change_password' => false,
            'version' => ((int) $user->version) + 1,
        ])->save();
        $this->refreshTokens->revokeAllForUser($user->user_id);
        $this->auditLogger->log('CHANGE_PASSWORD', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess(null, 'Đổi mật khẩu thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/switch-context', operationId: 'auth_switch_context',
        summary: 'Chuyển ngữ cảnh (warehouse scope) và cấp lại access token mới',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'context_type', type: 'string', maxLength: 50, nullable: true, example: 'WAREHOUSE'),
                new OA\Property(property: 'context_id', type: 'string', maxLength: 100, nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'scope_ref', type: 'string', maxLength: 100, nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Chuyển ngữ cảnh thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Ngữ cảnh kho nằm ngoài phạm vi được gán'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function switchContext(Request $request)
    {
        $request->validate([
            'context_type' => ['nullable', 'string', 'max:50'],
            'context_id' => ['nullable', 'string', 'max:100'],
            'scope_ref' => ['nullable', 'string', 'max:100'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();
        $scopeRef = $request->input('scope_ref');

        if ($scopeRef !== null && ! $user->canAccessWarehouse((string) $scopeRef)) {
            return $this->respondError('Ngữ cảnh kho nằm ngoài phạm vi được gán.', [], 403, 'ACCESS_DENIED');
        }

        $accessToken = JWTAuth::fromUser($user);
        $this->auditLogger->log(
            'SWITCH_CONTEXT', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], $request->only(['context_type', 'context_id', 'scope_ref']),
            result: 'SUCCESS',
        );

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'active_context' => [
                'context_type' => $request->input('context_type', 'SYSTEM'),
                'context_id' => $request->input('context_id') ?? $scopeRef,
                'scope_ref' => $scopeRef,
            ],
            'user' => $this->userPayload($user),
        ], 'Chuyển ngữ cảnh thành công');
    }

    /**
     * WWHN-289: khoá brute-force theo (IP + định danh đăng nhập đã chuẩn hoá).
     * Không dùng riêng IP (một kho NAT chung sẽ tự khoá lẫn nhau) và không dùng
     * riêng username (kẻ tấn công đổi username là thoát khoá).
     *
     * @param  array<string, string>  $credentials
     */
    private function loginThrottleKey(Request $request, array $credentials): string
    {
        $identifier = Str::lower(trim((string) ($credentials['username'] ?? $credentials['email'] ?? '')));

        return 'auth-login:'.sha1($request->ip().'|'.$identifier);
    }

    /**
     * Guard against database collations that compare login identifiers
     * case-insensitively. Password verification remains Laravel Hash::check().
     *
     * @param  array<string, string>  $credentials
     */
    private function credentialsMatchExactly(User $user, array $credentials): bool
    {
        if (array_key_exists('email', $credentials)) {
            return hash_equals((string) $user->email, (string) $credentials['email']);
        }

        return hash_equals((string) $user->username, (string) ($credentials['username'] ?? ''));
    }

    private function userPayload(User $user): array
    {
        return [
            ...$this->userProfilePayload($user),
            ...$this->userAuthorizationPayload($user),
        ];
    }

    private function userProfilePayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'version' => (int) $user->version,
        ];
    }

    private function userAuthorizationPayload(User $user): array
    {
        return [
            'roles' => $user->activeRoles()->orderBy('role_code')
                ->get(['role.role_id', 'role.role_code', 'role.role_name'])
                ->map(fn ($role) => [
                    'role_id' => $role->role_id,
                    'role_code' => $role->role_code,
                    'role_name' => $role->role_name,
                    'scope_ref' => $role->pivot->scope_ref,
                ])->values()->all(),
            'permissions' => $user->permissionCodes(),
            'warehouse_scope_ids' => $user->warehouseScopeIds(),
        ];
    }
}
