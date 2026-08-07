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
