<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * WWHN-SEC-09: lớp che thông tin nhạy cảm cho LOG ỨNG DỤNG (Log::info/error...).
 *
 * Trước đây masking chỉ tồn tại trong AuditLogger::sanitize() — tức chỉ áp cho
 * before_data/after_data ghi vào bảng audit_log. Mọi thứ đi qua Monolog (kể cả
 * context mà Laravel tự đính khi log exception) rơi thẳng xuống file/stderr
 * không qua lớp che nào. Processor này đóng lỗ đó:
 *
 *  - context/extra: mọi khoá khớp `config('audit.sensitive_keys')` (không phân
 *    biệt hoa thường, quét đệ quy) bị thay bằng `[REDACTED]` — dùng CHUNG danh
 *    sách với audit để hai lớp không bao giờ lệch nhau;
 *  - message: chuỗi dạng JWT (`xxx.yyy.zzz` base64url ba khúc) và
 *    `Bearer <token>` bị thay bằng `[REDACTED_TOKEN]` — đề phòng ai đó nối
 *    thẳng token vào chuỗi message thay vì đưa qua context.
 *
 * Gắn qua `tap` của từng channel trong config/logging.php.
 */
class RedactSensitiveLogContext
{
    private const REDACTED = '[REDACTED]';

    private const REDACTED_TOKEN = '[REDACTED_TOKEN]';

    /** Sâu tối đa khi quét đệ quy — chặn payload tự tham chiếu/độ sâu bất thường. */
    private const MAX_DEPTH = 8;

    private const TOKEN_PATTERNS = [
        // JWT: ba khúc base64url nối bằng dấu chấm, khúc đầu đủ dài để không
        // nhầm với version string kiểu 1.2.3.
        '/\b[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
        '/Bearer\s+[A-Za-z0-9._~+\/-]{16,}=*/i',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(static function (LogRecord $record): LogRecord {
                return $record->with(
                    message: self::scrubMessage($record->message),
                    context: self::scrubArray($record->context, 0),
                    extra: self::scrubArray($record->extra, 0),
                );
            });
        }
    }

    private static function scrubMessage(string $message): string
    {
        // Đường nhanh: message không chứa dấu chấm kép/Bearer thì khỏi tốn regex.
        if (! str_contains($message, '.') && stripos($message, 'bearer') === false) {
            return $message;
        }

        foreach (self::TOKEN_PATTERNS as $pattern) {
            $message = (string) preg_replace($pattern, self::REDACTED_TOKEN, $message);
        }

        return $message;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $data;
        }

        $sensitiveKeys = array_map('strtolower', (array) config('audit.sensitive_keys', []));

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrubArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = self::scrubMessage($value);
            }
        }

        return $data;
    }
}
