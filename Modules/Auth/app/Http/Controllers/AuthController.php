<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Cookie;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use ApiResponse;

    private const REFRESH_COOKIE = 'wms_refresh_token';

    private const REFRESH_COOKIE_PATH = '/api/v1/auth';

    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'auth_login',
        summary: 'Đăng nhập bằng username hoặc email; refresh token được đặt trong cookie HttpOnly',
        tags: ['01. Authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'admin'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@wms-hoanam.local'),
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
        if (! $this->trustedOrigin($request)) {
            return $this->respondError('Origin không được phép.', [], 403, 'ACCESS_DENIED');
        }

        $credentials = $request->only('username', 'email', 'password');

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
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($user),
        ], 'Đăng nhập thành công')->withCookie($this->refreshCookie($request, $refreshToken));
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'auth_refresh',
        summary: 'Làm mới access token bằng cookie HttpOnly (xoay vòng refresh token)',
        tags: ['01. Authentication'],
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Làm mới token thành công'),
            new OA\Response(response: 401, description: 'Refresh token không hợp lệ hoặc đã hết hạn'),
        ]
    )]
    public function refresh(Request $request)
    {
        if (! $this->trustedOrigin($request)) {
            return $this->respondError('Origin không được phép.', [], 403, 'ACCESS_DENIED');
        }

        $request->validate(['refresh_token' => ['prohibited']]);

        $raw = $request->cookie(self::REFRESH_COOKIE);
        if (! is_string($raw) || $raw === '') {
            return $this->respondError('Phiên đăng nhập không hợp lệ hoặc đã hết hạn.', [], 401, 'UNAUTHENTICATED')
                ->withCookie($this->expiredRefreshCookie($request));
        }

        $rotated = $this->refreshTokens->rotate($raw, $request);

        if ($rotated === null) {
            return $this->respondError('Phiên đăng nhập không hợp lệ hoặc đã hết hạn.', [], 401, 'UNAUTHENTICATED')
                ->withCookie($this->expiredRefreshCookie($request));
        }

        $accessToken = JWTAuth::fromUser($rotated['user']);

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($rotated['user']),
        ], 'Làm mới token thành công')->withCookie($this->refreshCookie($request, $rotated['raw']));
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'auth_logout',
        summary: 'Đăng xuất — thu hồi refresh token trong cookie và huỷ access token hiện tại',
        security: [['bearerAuth' => []]],
        tags: ['01. Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Đăng xuất thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function logout(Request $request)
    {
        if (! $this->trustedOrigin($request)) {
            return $this->respondError('Origin không được phép.', [], 403, 'ACCESS_DENIED');
        }

        $request->validate(['refresh_token' => ['prohibited']]);

        $raw = $request->cookie(self::REFRESH_COOKIE);
        if (is_string($raw) && $raw !== '') {
            $this->refreshTokens->revoke($raw);
        }

        auth('api')->logout();

        return $this->respondSuccess(null, 'Đăng xuất thành công')
            ->withCookie($this->expiredRefreshCookie($request));
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

        return $this->respondSuccess($this->userPayload($user));
    }

    private function userPayload(User $user): array
    {
        $roles = $user->roles()->where('role.status', 'ACTIVE')->get();

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'permissions' => $user->permissionCodes(),
            'roles' => $roles->map(fn ($role) => [
                'role_id' => $role->role_id,
                'role_code' => $role->role_code,
                'role_name' => $role->role_name,
                'scope_ref' => $role->pivot?->scope_ref,
            ])->values()->all(),
            'warehouse_scope_ids' => $roles->pluck('pivot.scope_ref')->filter()->unique()->values()->all(),
        ];
    }

    private function refreshCookie(Request $request, string $raw): Cookie
    {
        return cookie(
            self::REFRESH_COOKIE,
            $raw,
            (int) config('refresh_token.ttl_minutes'),
            self::REFRESH_COOKIE_PATH,
            null,
            $this->secureCookie($request),
            true,
            false,
            'lax'
        );
    }

    private function expiredRefreshCookie(Request $request): Cookie
    {
        return cookie(
            self::REFRESH_COOKIE,
            '',
            -1,
            self::REFRESH_COOKIE_PATH,
            null,
            $this->secureCookie($request),
            true,
            false,
            'lax'
        );
    }

    private function secureCookie(Request $request): bool
    {
        return $request->isSecure() || app()->environment('production');
    }

    private function trustedOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        if (! is_string($origin) || $origin === '') {
            return true;
        }

        return $origin === $request->getSchemeAndHttpHost()
            || in_array($origin, config('cors.allowed_origins', []), true);
    }
}
