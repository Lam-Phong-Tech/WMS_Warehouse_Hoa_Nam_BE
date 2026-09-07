<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-SEC-03 — header bảo mật chung cho mọi response.
 *
 * Bằng chứng trước khi có lớp này (`curl -D- http://localhost:8080/api/v1/health`):
 * response chỉ có Cache-Control/Vary/X-Request-ID; không có nosniff, không có
 * Referrer-Policy, không có chống nhúng iframe, và lộ `X-Powered-By: PHP/8.3.x`
 * — tức công bố sẵn phiên bản PHP cho bước dò CVE.
 *
 * Nguyên tắc chọn header: CHỈ những header có tác dụng thật với một API JSON +
 * trang Swagger, không thêm header trang trí và không thêm gì có thể phá FE:
 *  - `Cross-Origin-Resource-Policy` CỐ Ý không đặt: ảnh PV được FE ở origin khác
 *    tải trực tiếp, đặt same-origin/same-site sẽ chặn đúng luồng đó.
 *  - `Content-Security-Policy` chỉ đặt cho response JSON (`default-src 'none'`,
 *    nội dung JSON không được nạp tài nguyên nào). KHÔNG đặt cho HTML vì trang
 *    Swagger UI dùng script/style inline và sẽ trắng trang.
 *  - HSTS chỉ đặt khi request thực sự đi qua HTTPS. Đặt trên HTTP dev sẽ ghim
 *    trình duyệt vào https://localhost và làm hỏng môi trường phát triển; giá
 *    trị này do đó tự bật đúng ở môi trường thật sau TLS terminator (đã có
 *    trustProxies + X-Forwarded-Proto trong bootstrap/app.php).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ẩn phiên bản PHP. header_remove() xử lý header do chính PHP phát
        // (expose_php) — nằm ngoài bag của Symfony nên phải gọi cả hai.
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'json')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
