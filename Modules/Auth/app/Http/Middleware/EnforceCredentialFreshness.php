<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Support\ApiAuthContext;
use Modules\Auth\Support\P10SessionFamily;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global credential and sid-family enforcement, except canonical public routes.
 * A rotated sid remains valid only while its own family has an active token.
 * Legacy tokens cannot prove ordering within the password-change second, so
 * equality fails closed. A fresh, active sid family may authenticate that second.
 * No warehouse/work-shift or client-supplied identity is inferred here.
 */
class EnforceCredentialFreshness
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiAuthContext::isPublicEndpoint($request)) {
            return $next($request);
        }
        $user = ApiAuthContext::resolveUser();
        if ($user === null) {
            return $next($request);
        }
        $family = P10SessionFamily::current($user);
        $changedAt = $user->passwordChangedAtEpoch();
        if ($changedAt === null) {
            return $next($request);
        }
        $issuedAt = $this->tokenIssuedAt();
        if ($issuedAt !== null && ($issuedAt < $changedAt || ($family === null && $issuedAt === $changedAt))) {
            ApiError::abort(401, 'CREDENTIALS_CHANGED', 'Mật khẩu của tài khoản đã được thay đổi. Vui lòng đăng nhập lại.');
        }

        return $next($request);
    }

    private function tokenIssuedAt(): ?int
    {
        try {
            $payload = auth('api')->payload();
        } catch (Throwable) {
            return null;
        }
        $issuedAt = $payload->get('iat');

        return is_numeric($issuedAt) ? (int) $issuedAt : null;
    }
}
