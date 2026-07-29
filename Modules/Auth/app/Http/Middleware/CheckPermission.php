<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Middleware alias `permission:<CODE>` — vd `->middleware('permission:inbound.post')`.
 * SUPER_ADMIN bypass mọi permission check. Backend luôn tự enforce, không tin FE ẩn nút.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        /** @var User|null $user */
        $user = null;

        if ($request->bearerToken()) {
            try {
                $user = JWTAuth::setToken($request->bearerToken())->authenticate();
            } catch (\Throwable) {
                $user = null;
            }
        }

        $user ??= auth('api')->user();

        if (! $user) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Chưa xác thực');
        }

        if (! $user->hasPermission($permission)) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Bạn không có quyền thực hiện thao tác này.');
        }

        return $next($request);
    }
}
