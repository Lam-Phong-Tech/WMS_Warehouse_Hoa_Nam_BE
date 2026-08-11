<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-session bridge for JWT authentication.
 *
 * Access tokens intentionally remain JavaScript memory-only. The refresh token is
 * mirrored into an HttpOnly cookie so a full page reload can obtain a new access
 * token without exposing a long-lived credential to JavaScript. JSON body tokens
 * remain supported for Swagger/Postman and non-browser clients.
 */
class PersistRefreshTokenCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getActionMethod();
        $cookieToken = $request->cookie($this->cookieName());
        $usedCookieFallback = false;

        if (in_array($action, ['refresh', 'logout'], true)
            && ! $request->filled('refresh_token')
            && is_string($cookieToken)
            && $cookieToken !== '') {
            $request->merge(['refresh_token' => $cookieToken]);
            $usedCookieFallback = true;
        }

        /** @var Response $response */
        $response = $next($request);

        if (in_array($action, ['login', 'refresh'], true) && $response->isSuccessful()) {
            $refreshToken = $this->extractRefreshToken($response);
            if ($refreshToken !== null) {
                $response->headers->setCookie($this->makeCookie($refreshToken));
            }
        }

        if (($action === 'refresh' && $usedCookieFallback && ! $response->isSuccessful())
            || (in_array($action, ['logout', 'changePassword'], true) && $response->isSuccessful())) {
            $response->headers->setCookie($this->expiredCookie());
        }

        return $response;
    }

    private function extractRefreshToken(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        $payload = $response->getData(true);
        $token = $payload['data']['refresh_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function makeCookie(string $token): Cookie
    {
        return Cookie::create($this->cookieName())
            ->withValue($token)
            ->withExpires(now()->addMinutes((int) config('refresh_token.ttl_minutes', 20160)))
            ->withPath((string) config('refresh_token.cookie.path', '/api/v1/auth'))
            ->withDomain(config('refresh_token.cookie.domain'))
            ->withSecure((bool) config('refresh_token.cookie.secure', false))
            ->withHttpOnly(true)
            ->withSameSite($this->sameSite());
    }

    private function expiredCookie(): Cookie
    {
        return Cookie::create($this->cookieName())
            ->withValue('')
            ->withExpires(1)
            ->withPath((string) config('refresh_token.cookie.path', '/api/v1/auth'))
            ->withDomain(config('refresh_token.cookie.domain'))
            ->withSecure((bool) config('refresh_token.cookie.secure', false))
            ->withHttpOnly(true)
            ->withSameSite($this->sameSite());
    }

    private function cookieName(): string
    {
        return (string) config('refresh_token.cookie.name', 'wms_refresh_token');
    }

    private function sameSite(): string
    {
        $sameSite = strtolower((string) config('refresh_token.cookie.same_site', 'lax'));

        return in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax';
    }
}
