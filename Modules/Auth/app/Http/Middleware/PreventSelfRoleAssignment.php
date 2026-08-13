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
        $targetId = (string) $request->route('id');
        $actorId = $request->user()?->user_id;

        if ($actorId !== null && $targetId === (string) $actorId) {
            ApiError::abort(
                409,
                'SELF_ESCALATION_DENIED',
                'Không được phép tự thay đổi vai trò của chính mình. Vui lòng nhờ một quản trị viên khác thực hiện.'
            );
        }

        return $next($request);
    }
}
