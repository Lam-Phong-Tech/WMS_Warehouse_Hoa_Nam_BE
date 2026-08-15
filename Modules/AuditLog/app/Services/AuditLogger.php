<?php

namespace Modules\AuditLog\Services;

use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use RuntimeException;
use Throwable;

/**
 * Audit writer duy nhất của dự án.
 *
 * Supported write policies:
 * - outbox_required: primary audit failure may continue only when the redacted payload
 *   is durably persisted to audit_outbox. If both writes fail, the business operation fails.
 * - fail_closed: any primary audit failure fails the business operation, even when the
 *   payload was persisted to audit_outbox for recovery.
 * - best_effort: primary/outbox failures are explicitly logged but do not fail the business
 *   operation. This mode is intentionally weaker and must only be enabled by an approved
 *   operational/architecture decision.
 *
 * Unknown policy values fail safe instead of silently degrading to best_effort.
 */
class AuditLogger
{
    private const POLICY_OUTBOX_REQUIRED = 'outbox_required';

    private const POLICY_FAIL_CLOSED = 'fail_closed';

    private const POLICY_BEST_EFFORT = 'best_effort';

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
        $policy = self::writePolicy();
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
            AuditLog::create($payload);

            return;
        } catch (Throwable $primaryError) {
            Log::error('AUDIT_WRITE_FAILED', [
                'request_id' => $requestId,
                'action' => $payload['action'],
                'object_type' => $objectType,
                'object_id' => $objectId,
                'policy' => $policy,
                'exception' => $primaryError::class,
                'message' => $primaryError->getMessage(),
            ]);

            try {
                AuditOutbox::create([
                    'payload' => $payload,
                    'attempts' => 0,
                    'last_error' => self::truncate($primaryError->getMessage()),
                    'available_at' => now(),
                ]);
            } catch (Throwable $outboxError) {
                Log::critical('AUDIT_OUTBOX_WRITE_FAILED', [
                    'request_id' => $requestId,
                    'action' => $payload['action'],
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'policy' => $policy,
                    'primary_exception' => $primaryError::class,
                    'outbox_exception' => $outboxError::class,
                ]);

                if ($policy === self::POLICY_BEST_EFFORT) {
                    Log::warning('AUDIT_BEST_EFFORT_CONTINUED_WITHOUT_DURABLE_AUDIT', [
                        'request_id' => $requestId,
                        'action' => $payload['action'],
                        'object_type' => $objectType,
                        'object_id' => $objectId,
                    ]);

                    return;
                }

                throw new RuntimeException(
                    'Không thể ghi audit hoặc audit outbox; thao tác bị dừng để bảo toàn traceability.',
                    previous: $outboxError,
                );
            }

            if ($policy === self::POLICY_FAIL_CLOSED) {
                throw new RuntimeException(
                    'Audit chính thất bại; payload đã được đưa vào outbox nhưng policy yêu cầu fail-closed.',
                    previous: $primaryError,
                );
            }

            if ($policy === self::POLICY_BEST_EFFORT) {
                Log::warning('AUDIT_BEST_EFFORT_FALLBACK_TO_OUTBOX', [
                    'request_id' => $requestId,
                    'action' => $payload['action'],
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                ]);
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

    private static function writePolicy(): string
    {
        $policy = strtolower(trim((string) config('audit.write_policy', self::POLICY_OUTBOX_REQUIRED)));
        $allowed = [
            self::POLICY_OUTBOX_REQUIRED,
            self::POLICY_FAIL_CLOSED,
            self::POLICY_BEST_EFFORT,
        ];

        if (! in_array($policy, $allowed, true)) {
            Log::critical('AUDIT_WRITE_POLICY_INVALID', ['policy' => $policy]);

            throw new RuntimeException(
                'Cấu hình audit.write_policy không hợp lệ; thao tác bị dừng theo nguyên tắc fail-safe.'
            );
        }

        return $policy;
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
