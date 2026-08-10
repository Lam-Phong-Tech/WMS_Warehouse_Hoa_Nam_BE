<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\AuditLog\Services\AuditLogger;
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
        path: '/api/v1/auth/login',
        operationId: 'auth_login',
        summary: 'Đăng nhập bằng email/password, trả về JWT access token + refresh token',
        tags: ['01. Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@gmail.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Cntt@1234'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đăng nhập thành công'),
            new OA\Response(response: 401, description: 'Sai tên đăng nhập hoặc mật khẩu'),
            new OA\Response(response: 403, description: 'Tài khoản bị khoá/ngừng hoạt động'),
        ]
    )]
    public function login(LoginRequest $request)
    {
        if ($request->filled('username')) {
            $credentials = ['username' => $request->input('username'), 'password' => $request->input('password')];
        } else {
            $credentials = ['email' => $request->input('email'), 'password' => $request->input('password')];
        }

        if (! $accessToken = auth('api')->attempt($credentials)) {
            $user = $request->filled('username')
                ? User::where('username', $credentials['username'])->first()
                : User::where('email', $credentials['email'] ?? null)->first();
            
            $logData = $credentials;
            unset($logData['password']);

            $this->auditLogger->log(
                'LOGIN_FAILED',
                'app_user',
                $user?->user_id,
                $user?->user_id,
                $this->currentRequestId(),
                [],
                $logData,
                'Invalid credentials'
            );

            return $this->respondError('Email, tài khoản hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = auth('api')->user();

        if ($user->status !== 'ACTIVE') {
            auth('api')->logout();
            $this->auditLogger->log(
                'LOGIN_DENIED',
                'app_user',
                $user->user_id,
                $user->user_id,
                $this->currentRequestId(),
                [],
                ['status' => $user->status],
                'Inactive or locked account'
            );

            return $this->respondError('Tài khoản đang bị khoá hoặc ngừng hoạt động.', [], 403, 'ACCESS_DENIED');
        }

        $refreshToken = $this->refreshTokens->issue($user, $request);
        $this->auditLogger->log('LOGIN', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId());

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'roles' => $user->roles()
                    ->where('role.status', 'ACTIVE')
                    ->orderBy('role_code')
                    ->get(['role.role_id', 'role.role_code', 'role.role_name'])
                    ->map(fn ($role) => [
                        'role_id' => $role->role_id,
                        'role_code' => $role->role_code,
                        'role_name' => $role->role_name,
                    ])
                    ->values()
                    ->all(),
                'permissions' => $user->permissionCodes(),
            ],
        ], 'Đăng nhập thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'auth_refresh',
        summary: 'Làm mới access token bằng refresh token (xoay vòng — refresh token cũ bị thu hồi)',
        tags: ['01. Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [new OA\Property(property: 'refresh_token', type: 'string')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Làm mới token thành công'),
            new OA\Response(response: 401, description: 'Refresh token không hợp lệ hoặc đã hết hạn'),
        ]
    )]
    public function refresh(Request $request)
    {
        $request->validate(['refresh_token' => ['required', 'string']]);

        $rotated = $this->refreshTokens->rotate($request->input('refresh_token'), $request);

        if ($rotated === null) {
            return $this->respondError('Refresh token không hợp lệ hoặc đã hết hạn.', [], 401, 'UNAUTHENTICATED');
        }

        $accessToken = JWTAuth::fromUser($rotated['user']);
        $user = $rotated['user'];
        $this->auditLogger->log('TOKEN_REFRESH', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId());

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $rotated['raw'],
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($user),
        ], 'Làm mới token thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'auth_logout',
        summary: 'Đăng xuất — thu hồi refresh token (nếu có) và huỷ access token hiện tại',
        security: [['bearerAuth' => []]],
        tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'refresh_token', type: 'string', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đăng xuất thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function logout(Request $request)
    {
        $request->validate(['refresh_token' => ['nullable', 'string']]);
        /** @var User $user */
        $user = auth('api')->user();

        if ($request->filled('refresh_token')) {
            $this->refreshTokens->revoke($request->input('refresh_token'));
        }

        auth('api')->logout();
        $this->auditLogger->log('LOGOUT', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId());

        return $this->respondSuccess(null, 'Đăng xuất thành công');
    }

    #[OA\Get(
        path: '/api/v1/auth/me',
        operationId: 'auth_me',
        summary: 'Thông tin tài khoản đang đăng nhập + danh sách permission_key',
        security: [['bearerAuth' => []]],
        tags: ['01. Authentication'],
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
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'status' => $user->status,
            ...$this->userAuthorizationPayload($user),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/change-password',
        operationId: 'auth_change_password',
        summary: 'Change password for current authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'new_password', 'new_password_confirmation'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                    new OA\Property(property: 'new_password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'new_password_confirmation', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password changed successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid current password or validation error'),
        ]
    )]
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
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
            'version' => ((int) $user->version) + 1,
        ])->save();

        $this->auditLogger->log('CHANGE_PASSWORD', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId());

        return $this->respondSuccess(null, 'Đổi mật khẩu thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/switch-context',
        operationId: 'auth_switch_context',
        summary: 'Switch active working context and return a fresh access token',
        security: [['bearerAuth' => []]],
        tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'context_type', type: 'string', maxLength: 50, nullable: true, example: 'SYSTEM'),
                new OA\Property(property: 'context_id', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'scope_ref', type: 'string', maxLength: 100, nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Context switched successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
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
        $accessToken = JWTAuth::fromUser($user);

        $this->auditLogger->log('SWITCH_CONTEXT', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), [], $request->only(['context_type', 'context_id', 'scope_ref']));

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'active_context' => [
                'context_type' => $request->input('context_type', 'SYSTEM'),
                'context_id' => $request->input('context_id') ?? $request->input('scope_ref'),
                'scope_ref' => $request->input('scope_ref'),
            ],
            'user' => $this->userPayload($user),
        ], 'Chuyển ngữ cảnh thành công');
    }

    private function userPayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            ...$this->userAuthorizationPayload($user),
        ];
    }

    private function userAuthorizationPayload(User $user): array
    {
        return [
            'roles' => $user->roles()
                ->where('role.status', 'ACTIVE')
                ->orderBy('role_code')
                ->get(['role.role_id', 'role.role_code', 'role.role_name'])
                ->map(fn ($role) => [
                    'role_id' => $role->role_id,
                    'role_code' => $role->role_code,
                    'role_name' => $role->role_name,
                ])
                ->values()
                ->all(),
            'permissions' => $user->permissionCodes(),
        ];
    }
}
