<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Envelope DUY NHẤT của nhóm `/api/v1/public/*`.
 *
 * FE App PV chốt đúng SÁU khoá, luôn có mặt, kể cả khi giá trị là null:
 *
 *   {success, message, data, meta:{request_id}, error_code, errors}
 *
 * Không dùng lại App\Traits\ApiResponse được, vì envelope nội bộ khác ở ba điểm
 * mà FE sẽ vỡ nếu gặp:
 *   - thành công thì THIẾU HẲN `error_code`/`errors` (không phải null);
 *   - thất bại thì THIẾU HẲN `data` (không phải null);
 *   - thất bại kèm một object `error:{code,details}` thừa so với hợp đồng mới.
 *
 * Sửa envelope nội bộ để khớp là đụng vào 332 route đang chạy, nên nhóm public
 * có envelope riêng và được EnsurePublicApiEnvelope ép lại một lần nữa ở tầng
 * middleware để cả lỗi do framework ném ra (404 route, 429 throttle, 500) cũng
 * ra đúng hình dạng này.
 */
final class PublicApiEnvelope
{
    /**
     * Header đánh dấu response ĐÃ đúng envelope public. ApiConventionMiddleware
     * đọc X-WMS-Envelope-Ready để bỏ qua vòng decode/encode của nó; header thứ
     * hai để EnsurePublicApiEnvelope biết không phải chuẩn hoá lại.
     */
    public const READY_HEADER = 'X-WMS-Envelope-Ready';

    public const PUBLIC_MARKER_HEADER = 'X-WMS-Public-Envelope';

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $meta  khoá thêm cho meta (không ghi đè request_id)
     */
    public static function success(mixed $data, string $message, int $status = 200, array $meta = []): JsonResponse
    {
        return self::respond([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => self::meta($meta),
            'error_code' => null,
            'errors' => null,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $errorCode, string $message, int $status, ?array $errors = null, array $meta = []): JsonResponse
    {
        return self::respond([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => self::meta($meta),
            'error_code' => $errorCode,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Ép một payload bất kỳ về đúng sáu khoá, giữ nguyên giá trị đã có. Dùng cho
     * response do framework sinh ra (throttle, exception handler) — nơi không
     * gọi được success()/error().
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload, int $status, string $requestId): array
    {
        $isSuccess = $status >= 200 && $status < 300;

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['request_id'] = $requestId;

        // Envelope nội bộ để mã lỗi ở `error_code` HOẶC trong object `error`;
        // đọc cả hai rồi bỏ hẳn `error` đi vì hợp đồng public không có khoá đó.
        $errorCode = $payload['error_code'] ?? ($payload['error']['code'] ?? null);
        $errors = $payload['errors'] ?? ($payload['error']['details'] ?? null);

        return [
            'success' => $isSuccess,
            'message' => is_string($payload['message'] ?? null) && $payload['message'] !== ''
                ? $payload['message']
                : self::defaultMessage($status),
            'data' => $isSuccess ? ($payload['data'] ?? null) : null,
            'meta' => $meta,
            'error_code' => $isSuccess ? null : (is_string($errorCode) && $errorCode !== '' ? $errorCode : self::defaultErrorCode($status)),
            'errors' => $isSuccess ? null : (is_array($errors) && $errors !== [] ? $errors : null),
        ];
    }

    public static function requestId(): string
    {
        $requestId = request()?->attributes->get('request_id');

        return is_string($requestId) && $requestId !== '' ? $requestId : (string) Str::uuid();
    }

    /**
     * Thông điệp mặc định LUÔN an toàn cho người dùng cuối: không nhắc tên bảng,
     * tên cột, stack trace hay bất kỳ chi tiết nội bộ nào.
     */
    public static function defaultMessage(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'Thành công.',
            $status === 400 => 'Yêu cầu không hợp lệ.',
            $status === 404 => 'Không tìm thấy dữ liệu yêu cầu.',
            $status === 405 => 'Phương thức không được hỗ trợ.',
            $status === 409 => 'Yêu cầu bị trùng lặp.',
            $status === 422 => 'Dữ liệu gửi lên chưa hợp lệ.',
            $status === 429 => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.',
            $status === 503 => 'Hệ thống đang bảo trì. Vui lòng quay lại sau.',
            default => 'Không thể tải dữ liệu lúc này.',
        };
    }

    public static function defaultErrorCode(int $status): string
    {
        return match ($status) {
            400 => PublicApiErrorCode::INVALID_QUERY,
            404 => PublicApiErrorCode::RESOURCE_NOT_FOUND,
            405 => PublicApiErrorCode::METHOD_NOT_ALLOWED,
            409 => PublicApiErrorCode::IDEMPOTENCY_CONFLICT,
            422 => PublicApiErrorCode::VALIDATION_FAILED,
            429 => PublicApiErrorCode::RATE_LIMIT_EXCEEDED,
            503 => PublicApiErrorCode::SERVICE_UNAVAILABLE,
            default => PublicApiErrorCode::INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra): array
    {
        return ['request_id' => self::requestId()] + $extra;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function respond(array $payload, int $status): JsonResponse
    {
        return response()
            ->json($payload, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders([
                self::READY_HEADER => '1',
                self::PUBLIC_MARKER_HEADER => '1',
            ]);
    }
}
