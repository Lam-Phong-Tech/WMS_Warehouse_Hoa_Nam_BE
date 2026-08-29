<?php

namespace App\Http\Middleware;

use App\Support\PublicApiEnvelope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chốt chặn cuối của envelope nhóm `/api/v1/public/*`.
 *
 * Controller đã trả đúng envelope qua PublicApiEnvelope::success()/error(),
 * nhưng có ba nguồn response KHÔNG đi qua controller và vẫn phải đúng hợp đồng,
 * vì FE parse mọi response bằng cùng một hàm:
 *
 *   1. throttle → 429 do RateLimiter sinh;
 *   2. exception handler → 404 route không tồn tại, 405 sai method, 500;
 *   3. bất kỳ middleware nào phía trong trả về sớm.
 *
 * ĐĂNG KÝ TOÀN CỤC, KHÔNG PHẢI THEO NHÓM ROUTE, và tự no-op ngoài tiền tố
 * `api/v1/public`. Lý do: 405 (sai HTTP method) và 404 (đường dẫn không khớp
 * route nào) được ném ra trong lúc KHỚP ROUTE — trước khi bất kỳ middleware nào
 * của route group được chạy. Đặt theo nhóm thì hai mã lỗi đó thoát ra ngoài với
 * envelope nội bộ, và FE gặp đúng hình dạng lạ khi nó gọi sai đường dẫn — tức
 * là lúc nó cần thông báo lỗi rõ ràng nhất. Vị trí trong bootstrap/app.php
 * (ngay bên trong ApiConventionMiddleware) là có chủ đích.
 *
 * Middleware cũng là hàng rào chống rò rỉ: nó chỉ giữ lại đúng sáu khoá hợp
 * đồng, nên các khoá debug (`exception`, `file`, `line`, `trace`) mà
 * ApiConventionMiddleware gắn vào khi APP_DEBUG=true không bao giờ ra tới người
 * dùng ẩn danh.
 */
class EnsurePublicApiEnvelope
{
    /** Tiền tố duy nhất mà middleware này có tác dụng. */
    public const PATH_PREFIX = 'api/v1/public';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(self::PATH_PREFIX, self::PATH_PREFIX.'/*')) {
            return $next($request);
        }

        $response = $next($request);

        // Đã đúng envelope public rồi (do controller dựng) — không đụng vào.
        if ($response->headers->get(PublicApiEnvelope::PUBLIC_MARKER_HEADER) === '1') {
            return $response;
        }

        $status = $response->getStatusCode();
        $requestId = PublicApiEnvelope::requestId();

        $payload = $this->decodePayload($response);
        $normalized = PublicApiEnvelope::normalize($payload, $status, $requestId);

        $response->setContent((string) json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $response->headers->set('Content-Type', 'application/json');
        // Báo cho ApiConventionMiddleware (chạy ngoài cùng) biết khỏi decode/encode
        // lại và khỏi gắn thêm object `error` của envelope nội bộ.
        $response->headers->set(PublicApiEnvelope::READY_HEADER, '1');
        $response->headers->set(PublicApiEnvelope::PUBLIC_MARKER_HEADER, '1');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Response $response): array
    {
        $content = $response->getContent();
        if (! is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        // Response không phải JSON (ví dụ trang lỗi HTML của Symfony khi
        // Accept không phải json): bỏ hẳn nội dung, chỉ giữ status. Ném HTML
        // nguyên văn vào `message` sẽ vừa vỡ UI vừa có thể lộ đường dẫn file.
        return is_array($decoded) ? $decoded : [];
    }
}
