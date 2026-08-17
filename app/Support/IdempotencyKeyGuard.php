<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Chuẩn hóa contract Idempotency-Key cho toàn bộ operation bắt buộc idempotency.
 *
 * Không đặt validation rải rác trong controller để tránh lệch 428/422 hoặc giới hạn
 * độ dài giữa các bounded context.
 */
final class IdempotencyKeyGuard
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 100;

    public static function require(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            ApiError::abort(
                428,
                'PRECONDITION_REQUIRED',
                'Header Idempotency-Key là bắt buộc cho thao tác này.'
            );
        }

        return self::assertLength($key);
    }

    /**
     * Dùng cho operation mà Annex C KHÔNG khai Idempotency-Key (createWarrantyCase,
     * transitionWarrantyStatus). Cả hai đều stock-neutral nên không thuộc phạm vi
     * NFR-SRS-001/§13.3 và §8.1 không liệt kê 428 cho nhóm Warranty.
     *
     * Thiếu header: thực thi trực tiếp, không 428. Có header: vẫn validate độ dài và
     * đi qua IdempotencyStore để replay/conflict giống operation bắt buộc.
     */
    public static function optional(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : self::assertLength($key);
    }

    private static function assertLength(string $key): string
    {
        $length = mb_strlen($key, 'UTF-8');
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            ApiError::abort(
                422,
                'VALIDATION_ERROR',
                'Header Idempotency-Key phải có độ dài từ 8 đến 100 ký tự.',
                [
                    'Idempotency-Key' => [
                        'Độ dài hợp lệ là 8–100 ký tự sau khi trim.',
                    ],
                ]
            );
        }

        return $key;
    }
}
