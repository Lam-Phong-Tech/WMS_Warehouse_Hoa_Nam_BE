<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCR-IAM-04 — chỉ SUPER_ADMIN mới được CẤP role SUPER_ADMIN.
 *
 * `EnsureMutableRole` bảo vệ role SUPER_ADMIN khỏi bị sửa, `PreventSelfRoleAssignment`
 * chặn tự đổi role của chính mình. Không cái nào chặn đường vòng kinh điển:
 * người có `iam.user.manage` tạo một tài khoản mới (tự đặt mật khẩu qua
 * `POST /users`), gán role SUPER_ADMIN cho tài khoản đó rồi đăng nhập bằng nó.
 * Vì tài khoản đích khác actor nên SCR-IAM-01 không kích hoạt.
 *
 * Middleware đọc cả hai dạng payload mà `AssignUserRoleRequest` chấp nhận:
 * `role_ids[]` (client cũ) và `roles[].role_id` (kèm scope/hiệu lực).
 */
class PreventSuperAdminRoleGrant
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if ($actor instanceof User && $actor->isSuperAdmin()) {
            return $next($request);
        }

        $roleIds = array_values(array_unique(array_filter(array_merge(
            array_map('strval', (array) $request->input('role_ids', [])),
            array_map(
                static fn ($assignment): string => is_array($assignment)
                    ? (string) ($assignment['role_id'] ?? '')
                    : '',
                (array) $request->input('roles', [])
            ),
        ))));

        if ($roleIds === []) {
            return $next($request);
        }

        $grantsSuperAdmin = Role::query()
            ->whereIn('role_id', $roleIds)
            ->where('role_code', 'SUPER_ADMIN')
            ->exists();

        if ($grantsSuperAdmin) {
            ApiError::abort(
                403,
                'SUPER_ADMIN_GRANT_DENIED',
                'Chỉ quản trị hệ thống mới được phép cấp vai trò quản trị hệ thống cho tài khoản khác.'
            );
        }

        return $next($request);
    }
}
