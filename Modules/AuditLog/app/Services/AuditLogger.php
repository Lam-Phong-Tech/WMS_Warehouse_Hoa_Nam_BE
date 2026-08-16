<?php

namespace Modules\AuditLog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use RuntimeException;
use Throwable;

/**
 * Audit writer duy nhất của dự án.
 *
 * Policy mặc định `outbox_required`: nếu ghi audit_log lỗi, payload đã redaction phải
 * được ghi vào audit_outbox và cảnh báo ở mức error. Nếu cả hai nơi đều lỗi, request
 * phải fail để không tạo trạng thái nghiệp vụ không có dấu vết audit.
 */
class AuditLogger
{
    public static function record(
        string $action,
        string $objectType,
        ?string $objectId,
        ?string $actorId,
        ?string $requestId = null,
        array $beforeData = [],
        array $afterData = [],
        ?string $reason = null,
    ): void {
        $payload = self::payload(
            $action,
            $objectType,
            $objectId,
            $actorId,
            $requestId,
            $beforeData,
            $afterData,
            $reason,
        );

        try {
            // PostgreSQL: một câu lệnh lỗi làm abort toàn bộ transaction đang mở,
            // nên fallback outbox bên dưới cũng sẽ chết với 25P02. Bọc trong
            // transaction lồng để Laravel cấp SAVEPOINT — lỗi chỉ rollback tới
            // savepoint và transaction nghiệp vụ vẫn ghi được outbox.
            DB::transaction(fn () => AuditLog::create($payload));

            return;
        } catch (Throwable $primaryError) {
            Log::error('AUDIT_WRITE_FAILED', [
                'request_id' => $requestId,
                'action' => $payload['action'],
                'object_type' => $objectType,
                'object_id' => $objectId,
                'exception' => $primaryError::class,
                'message' => $primaryError->getMessage(),
            ]);

            $policy = (string) config('audit.write_policy', 'outbox_required');

            try {
                DB::transaction(fn () => AuditOutbox::create([
                    'payload' => $payload,
                    'attempts' => 0,
                    'last_error' => self::truncate($primaryError->getMessage()),
                    'available_at' => now(),
                ]));
            } catch (Throwable $outboxError) {
                Log::critical('AUDIT_OUTBOX_WRITE_FAILED', [
                    'request_id' => $requestId,
                    'action' => $payload['action'],
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'primary_exception' => $primaryError::class,
                    'outbox_exception' => $outboxError::class,
                ]);

                throw new RuntimeException(
                    'Không thể ghi audit hoặc audit outbox; thao tác bị dừng để bảo toàn traceability.',
                    previous: $outboxError,
                );
            }

            if ($policy === 'fail_closed') {
                throw new RuntimeException(
                    'Audit chính thất bại; payload đã được đưa vào outbox nhưng policy yêu cầu fail-closed.',
                    previous: $primaryError,
                );
            }
        }
    }

    public function log(
        string $action,
        string $objectType,
        ?string $objectId,
        ?string $actorId,
        ?string $requestId = null,
        array $beforeData = [],
        array $afterData = [],
        ?string $reason = null,
    ): void {
        static::record($action, $objectType, $objectId, $actorId, $requestId, $beforeData, $afterData, $reason);
    }

    public static function sanitize(array $data): array
    {
        $sensitiveKeys = array_map(
            static fn (string $key): string => strtolower($key),
            (array) config('audit.sensitive_keys', []),
        );
        $replacement = (string) config('audit.redacted_value', '[REDACTED]');

        $walk = static function (mixed $value, ?string $key = null) use (&$walk, $sensitiveKeys, $replacement): mixed {
            if ($key !== null && in_array(strtolower($key), $sensitiveKeys, true)) {
                return $replacement;
            }

            if (! is_array($value)) {
                return $value;
            }

            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $walk($childValue, (string) $childKey);
            }

            return $sanitized;
        };

        return $walk($data);
    }

    private static function payload(
        string $action,
        string $objectType,
        ?string $objectId,
        ?string $actorId,
        ?string $requestId,
        array $beforeData,
        array $afterData,
        ?string $reason,
    ): array {
        return [
            'actor_id' => $actorId,
            'action' => strtoupper($action),
            'object_type' => $objectType,
            'object_id' => $objectId,
            'reason' => $reason,
            'request_id' => $requestId,
            'before_data' => $beforeData === [] ? null : self::sanitize($beforeData),
            'after_data' => $afterData === [] ? null : self::sanitize($afterData),
            'created_at' => now(),
        ];
    }

    private static function truncate(string $message): string
    {
        return mb_substr($message, 0, 2000);
    }
}
