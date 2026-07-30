<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'auth_login',
        summary: 'Đăng nhập bằng username/password, trả về JWT access token + refresh token',
        tags: ['01. Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'admin'),
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
        $credentials = $request->only('username', 'password');

        if (! $accessToken = auth('api')->attempt($credentials)) {
            return $this->respondError('Tên đăng nhập hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = auth('api')->user();

        if ($user->status !== 'ACTIVE') {
            auth('api')->logout();

            return $this->respondError('Tài khoản đang bị khoá hoặc ngừng hoạt động.', [], 403, 'ACCESS_DENIED');
        }

        $refreshToken = $this->refreshTokens->issue($user, $request);

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'display_name' => $user->display_name,
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

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $rotated['raw'],
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
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

        if ($request->filled('refresh_token')) {
            $this->refreshTokens->revoke($request->input('refresh_token'));
        }

        auth('api')->logout();

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
            'permissions' => $user->permissionCodes(),
        ]);
    }
}
