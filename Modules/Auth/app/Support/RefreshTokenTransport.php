<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * P1 bảo mật 10/09/2026 — refresh token KHÔNG BAO GIỜ tới được JavaScript của trình duyệt.
 *
 * TRƯỚC: /auth/login, /auth/refresh và /auth/mfa/verify trả refresh token dài hạn (14 ngày)
 * TRONG JSON, đồng thời PersistRefreshTokenCookie chép nó sang cookie HttpOnly. FE giữ bản
 * JSON trong bộ nhớ JS và gửi lại qua body `{refresh_token}` — nên một lỗi XSS bất kỳ đọc
 * được credential dài hạn đó và mang đi dùng từ máy khác; cookie HttpOnly không che được gì.
 *
 * SAU: hai kênh TÁCH BẠCH, mỗi request đúng một kênh, không bao giờ cả hai:
 *
 *  - cookie (mặc định, cho trình duyệt): refresh token chỉ nằm trong Set-Cookie HttpOnly;
 *    JSON không có khoá `refresh_token`. /auth/refresh chỉ đọc cookie, từ chối body
 *    `refresh_token`, và bắt buộc Origin/Referer thuộc allowlist CORS (chống CSRF cưỡng ép
 *    xoay vòng/replay để đá phiên). Request đăng nhập mang Origin lạ bị chặn trước khi chạm
 *    mật khẩu (chống login-CSRF); request không có Origin/Referer nào (công cụ dòng lệnh)
 *    vẫn đăng nhập được nhưng KHÔNG nhận refresh token.
 *  - body (cho client không phải trình duyệt): chỉ khi máy chủ bật
 *    REFRESH_TOKEN_BODY_TRANSPORT_ENABLED, client gửi `X-WMS-Token-Transport: body`, và
 *    request KHÔNG mang Origin (hoặc Origin nằm trong allowlist riêng của kênh này). Kênh
 *    body không bao giờ đọc hay đặt cookie — nên nó không thể biến cookie HttpOnly của
 *    trình duyệt thành một chuỗi mà JS đọc được.
 */
final class RefreshTokenTransport
{
    public const HEADER = 'X-WMS-Token-Transport';

    public const COOKIE = 'cookie';

    public const BODY = 'body';

    private const ATTRIBUTE = 'wms.refresh_token_transport';

    /**
     * Kênh của request. Header sai giá trị là 422, xin kênh body khi không được phép là 403
     * — không bao giờ lặng lẽ rơi về kênh khác.
     */
    public function mode(Request $request): string
    {
        $resolved = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($resolved)) {
            return $resolved;
        }

        $requested = strtolower(trim((string) $request->headers->get(self::HEADER, '')));

        $mode = match ($requested) {
            '', self::COOKIE => self::COOKIE,
            self::BODY => self::BODY,
            default => null,
        };

        if ($mode === null) {
            ApiError::abort(422, 'TOKEN_TRANSPORT_INVALID', 'Header X-WMS-Token-Transport chỉ nhận "cookie" hoặc "body".');
        }

        if ($mode === self::BODY && ! $this->bodyTransportAllowed($request)) {
            ApiError::abort(403, 'TOKEN_TRANSPORT_NOT_ALLOWED', 'Kênh trả refresh token qua body không được bật cho client này. Trình duyệt dùng cookie HttpOnly.');
        }

        $request->attributes->set(self::ATTRIBUTE, $mode);

        return $mode;
    }

    /**
     * Origin của request: header Origin, hoặc scheme://host[:port] của Referer khi trình duyệt
     * không gửi Origin. null CHỈ khi cả hai đều vắng — tức client không phải trình duyệt.
     */
    public function requestOrigin(Request $request): ?string
    {
        $origin = trim((string) $request->headers->get('Origin', ''));

        if ($origin !== '') {
            return strtolower(rtrim($origin, '/'));
        }

        $referer = trim((string) $request->headers->get('Referer', ''));

        if ($referer === '') {
            return null;
        }

        return $this->originOf($referer) ?? 'invalid-referer';
    }

    /**
     * Origin của FE được CORS cho phép, origin khớp allowed_origins_patterns, hoặc
     * chính origin hiệu lực của request sau reverse proxy. Nhánh cuối giúp mô hình
     * same-origin proxy (/api -> BE) không phụ thuộc APP_URL/CORS env bị cũ.
     */
    public function isTrustedBrowserOrigin(?string $origin, ?Request $request = null): bool
    {
        if ($origin === null) {
            return false;
        }

        $normalized = strtolower(rtrim(trim($origin), '/'));

        if (in_array($normalized, $this->trustedBrowserOrigins(), true)) {
            return true;
        }

        /*
         * Browser hiện đại tự gắn Sec-Fetch-Site và JavaScript không thể tự đặt
         * header này. Khi FE gọi /api qua reverse proxy CÙNG origin, Chrome/Edge
         * gửi "same-origin" dù hop nội bộ FE-nginx -> BE có thể làm Laravel thấy
         * Host/X-Forwarded-Host khác origin public. Tin tín hiệu browser này giúp
         * auth cookie không phụ thuộc vào topology proxy, nhưng vẫn chặn login-CSRF:
         * request từ site khác sẽ là "cross-site", còn request không phải browser
         * không có header này và phải qua allow-list phía dưới.
         */
        if ($request !== null
            && strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', ''))) === 'same-origin') {
            return true;
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && @preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        if ($request !== null) {
            $effectiveRequestOrigin = $this->originOf($request->getSchemeAndHttpHost());
            if ($effectiveRequestOrigin !== null && hash_equals($effectiveRequestOrigin, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /** Lần đăng nhập (mật khẩu hoặc bước MFA) này có được nhận refresh token không. */
    public function shouldIssueOnLogin(Request $request): bool
    {
        return $this->mode($request) === self::BODY
            || $this->isTrustedBrowserOrigin($this->requestOrigin($request), $request);
    }

    /** Refresh token client xuất trình, đọc ĐÚNG kênh của request và chỉ kênh đó. */
    public function presentedToken(Request $request): ?string
    {
        $token = $this->mode($request) === self::BODY
            ? $request->input('refresh_token')
            : $request->cookie($this->cookieName());

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Gắn refresh token vào response theo kênh của request. Kênh cookie KHÔNG BAO GIỜ đặt
     * token vào JSON; kênh body không bao giờ đặt cookie.
     */
    public function deliver(Request $request, JsonResponse $response, string $rawToken): JsonResponse
    {
        if ($this->mode($request) === self::BODY) {
            $payload = $response->getData(true);
            $payload['data']['refresh_token'] = $rawToken;
            $response->setData($payload);

            return $response;
        }

        $response->headers->setCookie($this->makeCookie($rawToken));

        return $response;
    }

    public function cookieName(): string
    {
        return (string) config('refresh_token.cookie.name', 'wms_refresh_token');
    }

    public function expiredCookie(): Cookie
    {
        return $this->baseCookie()->withValue('')->withExpires(1);
    }

    private function makeCookie(string $token): Cookie
    {
        return $this->baseCookie()
            ->withValue($token)
            ->withExpires(now()->addMinutes((int) config('refresh_token.ttl_minutes', 20160)));
    }

    private function baseCookie(): Cookie
    {
        $sameSite = strtolower((string) config('refresh_token.cookie.same_site', 'lax'));

        return Cookie::create($this->cookieName())
            ->withPath((string) config('refresh_token.cookie.path', '/api/v1/auth'))
            ->withDomain(config('refresh_token.cookie.domain'))
            ->withSecure((bool) config('refresh_token.cookie.secure', false))
            ->withHttpOnly(true)
            ->withSameSite(in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax');
    }

    private function bodyTransportAllowed(Request $request): bool
    {
        if (! (bool) config('refresh_token.body_transport.enabled', false)) {
            return false;
        }

        $origin = $this->requestOrigin($request);

        if ($origin === null) {
            return true;
        }

        return in_array($origin, $this->normalizeOrigins((array) config('refresh_token.body_transport.allowed_origins', [])), true);
    }

    /** @return array<int, string> */
    private function trustedBrowserOrigins(): array
    {
        $origins = $this->normalizeOrigins((array) config('cors.allowed_origins', []));
        $appOrigin = $this->originOf((string) config('app.url', ''));

        if ($appOrigin !== null) {
            $origins[] = $appOrigin;
        }

        return array_values(array_unique(array_filter(
            $origins,
            static fn (string $origin): bool => $origin !== '' && ! str_contains($origin, '*'),
        )));
    }

    /**
     * @param  array<int, mixed>  $origins
     * @return array<int, string>
     */
    private function normalizeOrigins(array $origins): array
    {
        return array_map(static fn ($origin): string => strtolower(rtrim(trim((string) $origin), '/')), $origins);
    }

    private function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return strtolower($scheme.'://'.$host.(is_int($port) ? ':'.$port : ''));
    }
}
