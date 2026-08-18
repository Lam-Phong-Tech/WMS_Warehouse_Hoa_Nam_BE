<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WWHN-289 (DEV289-P1-04) — envelope 429 dùng chung cho mọi rate limiter.
 *
 * §8.2 HTTP/Error Contract: mọi phản hồi lỗi phải cùng shape
 * success/message/error_code/errors/error.code/meta.request_id. Laravel mặc định
 * ném ThrottleRequestsException với body {"message": "Too Many Attempts."} —
 * lệch contract nên FE/QA không parse được. Lớp này giữ 429 đúng envelope và
 * vẫn trả Retry-After/X-RateLimit-* để client tự backoff.
 */
class RateLimitResponse
{
    public const ERROR_CODE = 'RATE_LIMIT_EXCEEDED';

    /**
     * @param  array<string, int|string>  $headers
     */
    public static function make(Request $request, array $headers = [], ?string $message = null): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');

        return response()->json([
            'success' => false,
            'message' => $message ?? 'Vượt quá giới hạn số lần gọi cho phép. Vui lòng thử lại sau.',
            'error_code' => self::ERROR_CODE,
            'errors' => [],
            'error' => [
                'code' => self::ERROR_CODE,
                'details' => [],
            ],
            'meta' => [
                'request_id' => is_string($requestId) ? $requestId : (string) Str::uuid(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], 429, $headers);
    }
}
