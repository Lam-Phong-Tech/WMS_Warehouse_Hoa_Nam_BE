<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
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
        summary: 'Login with username/password and return JWT access token plus refresh token',
        tags: ['Auth'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'admin'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login success'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 403, description: 'Inactive or locked account'),
        ]
    )]
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('username', 'password');

        if (! $accessToken = auth('api')->attempt($credentials)) {
            AuditLogger::record(
                'LOGIN_FAILED',
                'auth_session',
                null,
                null,
                $this->currentRequestId(),
                [],
                ['username' => $request->input('username')],
                'Invalid credentials'
            );

            return $this->respondError('Username or password is incorrect.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = auth('api')->user();

        if ($user->status !== 'ACTIVE') {
            auth('api')->logout();

            AuditLogger::record(
                'LOGIN_DENIED',
                'app_user',
                $user->user_id,
                $user->user_id,
                $this->currentRequestId(),
                [],
                ['status' => $user->status],
                'Inactive or locked account'
            );

            return $this->respondError('Account is inactive or locked.', [], 403, 'ACCESS_DENIED');
        }

        $refreshToken = $this->refreshTokens->issue($user, $request);

        AuditLogger::record(
            'LOGIN',
            'app_user',
            $user->user_id,
            $user->user_id,
            $this->currentRequestId(),
            [],
            ['username' => $user->username],
        );

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userContract($user),
        ], 'Login success');
    }

    public function refresh(Request $request)
    {
        $request->validate(['refresh_token' => ['required', 'string']]);

        $rotated = $this->refreshTokens->rotate($request->input('refresh_token'), $request);

        if ($rotated === null) {
            AuditLogger::record(
                'TOKEN_REFRESH_FAILED',
                'auth_session',
                null,
                null,
                $this->currentRequestId(),
                [],
                [],
                'Invalid or expired refresh token'
            );

            return $this->respondError('Refresh token is invalid or expired.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = $rotated['user'];
        $accessToken = JWTAuth::fromUser($user);

        AuditLogger::record(
            'TOKEN_REFRESH',
            'app_user',
            $user->user_id,
            $user->user_id,
            $this->currentRequestId(),
            [],
            ['username' => $user->username],
        );

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'refresh_token' => $rotated['raw'],
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userContract($user),
        ], 'Token refreshed');
    }

    public function logout(Request $request)
    {
        $request->validate(['refresh_token' => ['nullable', 'string']]);

        if ($request->filled('refresh_token')) {
            $this->refreshTokens->revoke($request->input('refresh_token'));
        }

        /** @var User|null $user */
        $user = auth('api')->user();

        auth('api')->logout();

        AuditLogger::record(
            'LOGOUT',
            'app_user',
            $user?->user_id,
            $user?->user_id,
            $this->currentRequestId(),
            [],
            ['username' => $user?->username],
        );

        return $this->respondSuccess(null, 'Logout success');
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->respondSuccess($this->userContract($user, includeEmail: true));
    }

    private function userContract(User $user, bool $includeEmail = false): array
    {
        $payload = [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'roles' => $user->roles()
                ->where('role.status', 'ACTIVE')
                ->get(['role.role_id', 'role.role_code', 'role.role_name', 'role.scope_type'])
                ->map(fn ($role) => [
                    'role_id' => $role->role_id,
                    'role_code' => $role->role_code,
                    'role_name' => $role->role_name,
                    'scope_type' => $role->scope_type,
                    'scope_ref' => $role->pivot->scope_ref,
                ])
                ->values()
                ->all(),
            'permissions' => $user->permissionCodes(),
        ];

        if ($includeEmail) {
            $payload['email'] = $user->email;
        }

        return $payload;
    }
}
