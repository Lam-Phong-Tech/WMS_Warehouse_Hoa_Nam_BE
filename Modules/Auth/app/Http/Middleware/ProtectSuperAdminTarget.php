<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCR-IAM-03 — tài khoản SUPER_ADMIN chỉ SUPER_ADMIN mới được chạm.
 *
 * `EnsureMutableRole` khoá bản thân ROLE SUPER_ADMIN khỏi bị sửa/xoá, nhưng
 * không nói gì về các TÀI KHOẢN đang giữ role đó. Trước đây bất kỳ ai có
 * `iam.user.manage` (vd WAREHOUSE_MANAGER) đều gọi được
 * `PATCH /users/{id}` để đặt lại mật khẩu của chính tài khoản super admin rồi
 * đăng nhập bằng tài khoản đó — leo thang đặc quyền chỉ bằng một request, không
 * cần biết mật khẩu cũ. Cùng lỗ hổng cho phép khoá (status=LOCKED) toàn bộ super
 * admin và làm hệ thống mất người quản trị.
 *
 * Middleware này chặn mọi thao tác ghi lên tài khoản đang giữ role SUPER_ADMIN
 * còn hiệu lực, trừ khi chính người thao tác cũng là SUPER_ADMIN.
 */
class ProtectSuperAdminTarget
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if ($actor instanceof User && $actor->isSuperAdmin()) {
            return $next($request);
        }

        $targetId = (string) $request->route('id');
        if ($targetId === '') {
            return $next($request);
        }

        $target = User::query()->find($targetId);

        if ($target instanceof User && $target->isSuperAdmin()) {
            ApiError::abort(
                403,
                'SUPER_ADMIN_TARGET_PROTECTED',
                'Tài khoản quản trị hệ thống chỉ có thể được chỉnh sửa bởi một quản trị hệ thống khác.'
            );
        }

        return $next($request);
    }
}
