<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Ném lỗi theo envelope public từ bất kỳ đâu (controller, service, read model).
 *
 * Khác App\Support\ApiError ở chỗ payload không có object `error` thừa và luôn
 * có `data: null` — đúng hợp đồng FE App PV.
 */
final class PublicApiError
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>|null  $errors
     * @return never
     */
    public static function abort(int $status, string $errorCode, string $message, ?array $errors = null): void
    {
        throw new HttpResponseException(
            PublicApiEnvelope::error($errorCode, $message, $status, $errors)
        );
    }

    /**
     * @return never
     */
    public static function invalidQuery(string $message, ?array $errors = null): void
    {
        self::abort(400, PublicApiErrorCode::INVALID_QUERY, $message, $errors);
    }

    /**
     * §Bảo mật: 404 phải che luôn ca "có tồn tại nhưng chưa publish". Trả 403
     * cho người ẩn danh là xác nhận sản phẩm đó có thật trong hệ thống.
     *
     * @return never
     */
    public static function productNotFound(): void
    {
        self::abort(404, PublicApiErrorCode::PRODUCT_NOT_FOUND, 'Không tìm thấy sản phẩm.');
    }
}
