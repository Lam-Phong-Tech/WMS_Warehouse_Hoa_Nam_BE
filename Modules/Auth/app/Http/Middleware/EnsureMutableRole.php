<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * SUPER_ADMIN is a system invariant, not a normal configurable role.
 *
 * The FE also renders it read-only, but this middleware is the authoritative
 * server-side guard against direct API calls that could remove the system
 * administrator capability or rename the role used by runtime super-admin
 * detection.
 */
class EnsureMutableRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $roleId = (string) $request->route('id');
        $role = Role::query()->find($roleId);

        if ($role?->role_code === 'SUPER_ADMIN') {
            ApiError::abort(
                409,
                'SYSTEM_ROLE_IMMUTABLE',
                'SUPER_ADMIN là vai trò hệ thống, không cho phép sửa, xoá hoặc thay đổi ma trận quyền.'
            );
        }

        return $next($request);
    }
}
