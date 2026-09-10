<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCR-IAM-01: No self-escalation.
 *
 * A user holding `iam.user.manage` must not be able to change their own role
 * assignments — that is exactly the path by which a permission-holder could
 * grant themself SUPER_ADMIN or any other privilege. A second actor must
 * always perform role changes on this user's own account.
 */
class PreventSelfRoleAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $actorId = $request->user()?->user_id;

        // So khớp KHÔNG phân biệt hoa/thường và khoảng trắng: `app_user.user_id` là uuid, và
        // PostgreSQL tìm uuid không phân biệt chữ hoa — so chuỗi thô thì
        // `/users/<ID-CỦA-CHÍNH-MÌNH-VIẾT-HOA>/...` lọt qua chốt này mà vẫn trỏ đúng tài khoản
        // của người gọi (rà soát AppSec 10/09/2026, docs/security/ROUTE_CONTRACT_DELTA_20260910.md E6).
        $targetId = strtolower(trim((string) $request->route('id')));

        if ($actorId !== null && $targetId === strtolower((string) $actorId)) {
            ApiError::abort(
                409,
                'SELF_ESCALATION_DENIED',
                'Không được phép tự thay đổi vai trò của chính mình. Vui lòng nhờ một quản trị viên khác thực hiện.'
            );
        }

        return $next($request);
    }
}
