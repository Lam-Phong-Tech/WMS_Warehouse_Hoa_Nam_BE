<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\DenyAuditRecorder;
use Modules\Auth\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Middleware alias `permission:<CODE>` — vd `->middleware('permission:inbound.post')`.
 * SUPER_ADMIN bypass mọi permission check. Backend luôn tự enforce, không tin FE ẩn nút.
 *
 * Hỗ trợ dạng OR `permission:A|B` để một route chấp nhận cả permission key chuẩn
 * Annex C lẫn key legacy đang được cấp cho role — cần thiết khi đồng bộ tên
 * permission mà không thu hồi quyền của người dùng hiện hữu.
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

        $accepted = array_values(array_filter(array_map('trim', explode('|', $permission))));
        $granted = false;
        foreach ($accepted as $candidate) {
            if ($user->hasPermission($candidate)) {
                $granted = true;
                break;
            }
        }

        if (! $granted) {
            // WWHN-165 / QA1-AUD-002: denied action phải có audit result='DENIED'.
            // Best-effort theo ADR-AUDIT-002 — lỗi ghi audit không đổi response 403.
            DenyAuditRecorder::permissionDenied($request, $permission, (string) $user->user_id);
            ApiError::abort(403, 'ACCESS_DENIED', 'Bạn không có quyền thực hiện thao tác này.');
        }

        return $next($request);
    }
}
