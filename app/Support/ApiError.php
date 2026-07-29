<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class ApiError
{
    /**
     * Ném lỗi API theo đúng envelope chuẩn (success/error.code/meta.request_id),
     * dùng được từ repository/service không có $this của controller.
     *
     * @return never
     */
    public static function abort(int $httpCode, string $errorCode, string $message, array $details = [], ?array $data = null): void
    {
        $requestId = request()?->attributes->get('request_id');

        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => $details,
            'error' => [
                'code' => $errorCode,
                'details' => $details,
            ],
            'meta' => [
                'request_id' => is_string($requestId) ? $requestId : (string) Str::uuid(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        throw new HttpResponseException(response()->json($payload, $httpCode));
    }
}
