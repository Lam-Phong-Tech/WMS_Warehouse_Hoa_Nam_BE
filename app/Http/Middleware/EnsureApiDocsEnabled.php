<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WWHN-SEC-04 — cổng bật/tắt Swagger UI + tệp OpenAPI (OWASP API9:2023
 * Improper Inventory Management).
 *
 * Bằng chứng: `curl -o /dev/null -w '%{http_code}' http://localhost:8080/api/documentation`
 * trả 200 khi CHƯA đăng nhập, và `config/l5-swagger.php` khai
 * `routes.middleware.api = []`. Tức toàn bộ bản đồ 345 endpoint — đường dẫn,
 * tham số, shape request/response, mã lỗi — được phát công khai. Điều đó không
 * tự nó cho phép đọc dữ liệu (RBAC vẫn chặn, đã kiểm), nhưng nó xoá sạch bước
 * trinh sát của kẻ tấn công và là thứ không cần có mặt trên môi trường thật.
 *
 * Mặc định CÓ CHỦ Ý: bật ở mọi môi trường TRỪ production. DEV/UAT/QA vẫn dùng
 * Swagger như hiện tại nên không ai bị mất công cụ; chỉ production tắt. Cần bật
 * lại tạm thời trên production thì đặt API_DOCS_ENABLED=true.
 *
 * Trả 404 (không phải 403) là có chủ ý: 403 xác nhận "có trang tài liệu ở đây",
 * 404 thì không tiết lộ gì.
 */
class EnsureApiDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabled()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    public static function enabled(): bool
    {
        $configured = env('API_DOCS_ENABLED');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return ! app()->environment('production');
    }
}
