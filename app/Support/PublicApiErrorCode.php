<?php

namespace App\Support;

/**
 * Danh mục mã lỗi của nhóm `/api/v1/public/*`.
 *
 * Toàn hệ thống hiện phát mã lỗi bằng chuỗi rời rạc tại từng call site (xem
 * App\Support\ApiError), nên cùng một tình huống dễ ra hai mã khác nhau tuỳ
 * đường đi. FE App PV switch-case trên `error_code`, vì vậy nhóm public gom mã
 * về đúng một nơi: thêm mã mới là sửa file này, và tài liệu
 * docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md phải nhắc lại y nguyên.
 *
 * Quy ước: mã mô tả NGUYÊN NHÂN, không mô tả HTTP status — status đã nói phần
 * đó rồi. Không bao giờ nhúng chi tiết nội bộ vào tên mã.
 */
final class PublicApiErrorCode
{
    // --- 400: tham số sai ---
    public const INVALID_QUERY = 'INVALID_QUERY';

    public const INVALID_CURSOR = 'INVALID_CURSOR';

    public const INVALID_SORT = 'INVALID_SORT';

    public const INVALID_DOMAIN = 'INVALID_DOMAIN';

    // --- 404: không tìm thấy ---
    // PRODUCT_NOT_FOUND tách riêng khỏi RESOURCE_NOT_FOUND vì FE hiện màn hình
    // khác hẳn cho hai ca này. KHÔNG khai thêm mã cho từng loại tài nguyên khi
    // chưa có chỗ phát: một mã nằm trong catalog mà runtime không bao giờ trả
    // ra là thông tin sai cho người đọc hợp đồng.
    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    // --- 405 ---
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    // --- 409/428: idempotency của POST /quote-requests ---
    public const IDEMPOTENCY_KEY_REQUIRED = 'IDEMPOTENCY_KEY_REQUIRED';

    public const IDEMPOTENCY_KEY_INVALID = 'IDEMPOTENCY_KEY_INVALID';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    // --- 422: dữ liệu nghiệp vụ ---
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const CONSENT_REQUIRED = 'CONSENT_REQUIRED';

    public const PRIVACY_VERSION_MISMATCH = 'PRIVACY_VERSION_MISMATCH';

    // --- 429 ---
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    // --- 503 ---
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    public const FEATURE_DISABLED = 'FEATURE_DISABLED';

    // --- 500 ---
    // Dùng LẠI mã của hệ thống thay vì đặt mã riêng: cùng một tình huống mà hai
    // mặt API trả hai mã khác nhau thì FE phải xử lý cả hai, và catalog lỗi có
    // hai dòng đồng nghĩa.
    public const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';

    private function __construct() {}

    /**
     * Toàn bộ mã hợp lệ. Test hợp đồng dùng danh sách này để chặn mã lạ lọt ra
     * response mà không được khai báo.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::INVALID_QUERY,
            self::INVALID_CURSOR,
            self::INVALID_SORT,
            self::INVALID_DOMAIN,
            self::PRODUCT_NOT_FOUND,
            self::RESOURCE_NOT_FOUND,
            self::METHOD_NOT_ALLOWED,
            self::IDEMPOTENCY_KEY_REQUIRED,
            self::IDEMPOTENCY_KEY_INVALID,
            self::IDEMPOTENCY_CONFLICT,
            self::VALIDATION_FAILED,
            self::CONSENT_REQUIRED,
            self::PRIVACY_VERSION_MISMATCH,
            self::RATE_LIMIT_EXCEEDED,
            self::SERVICE_UNAVAILABLE,
            self::FEATURE_DISABLED,
            self::INTERNAL_SERVER_ERROR,
        ];
    }
}
