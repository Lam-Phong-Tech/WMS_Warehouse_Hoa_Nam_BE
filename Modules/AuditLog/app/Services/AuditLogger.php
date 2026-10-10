<?php

namespace Modules\AuditLog\Services;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\Warehouse\Support\P22NfcAuditSnapshot;
use RuntimeException;
use Throwable;

/**
 * Audit writer duy nhất của dự án.
 *
 * Policy mặc định `outbox_required`: nếu ghi audit_log lỗi, payload đã redaction phải
 * được ghi vào audit_outbox và cảnh báo ở mức error. Nếu cả hai nơi đều lỗi, request
 * phải fail để không tạo trạng thái nghiệp vụ không có dấu vết audit.
 *
 * Policy `fail_closed`: audit chính lỗi luôn làm request fail, kể cả khi outbox ghi
 * thành công (dùng cho các route bắt buộc có audit, xem Wwhn172AggregateLock).
 *
 * Policy `best_effort`: không bao giờ chặn nghiệp vụ vì audit — kể cả khi cả audit_log
 * lẫn audit_outbox đều lỗi, chỉ log critical rồi return bình thường. Quyết định giữ
 * policy này (thay vì luôn fail-closed) nằm ở ADR-AUDIT-001
 * (`docs/decision-records/ADR-AUDIT-001-audit-write-policy.md`).
 */
class AuditLogger
{
    /**
     * Nhãn module hợp lệ — đúng 5 luồng theo yêu cầu vận hành (mục 6): hệ
     * thống & danh mục, nhập kho, xuất kho, bảo hành, kiểm kê/đối soát.
     */
    public const MODULE_SYSTEM = 'SYSTEM';

    public const MODULE_INBOUND = 'INBOUND';

    public const MODULE_OUTBOUND = 'OUTBOUND';

    public const MODULE_WARRANTY = 'WARRANTY';

    public const MODULE_STOCKTAKE = 'STOCKTAKE';

    public static function record(
        string $action,
        string $objectType,
        ?string $objectId,
        ?string $actorId,
        ?string $requestId = null,
        array $beforeData = [],
        array $afterData = [],
        ?string $reason = null,
        ?string $result = null,
        ?string $errorCode = null,
        ?string $idempotencyKey = null,
        ?string $module = null,
    ): void {
        // NFC-only, trusted owner snapshot BEFORE sanitization and outbox fallback.
        // No new event, mutation, receipt, historical backfill or current-tag read fallback.
        $afterData = P22NfcAuditSnapshot::enrich(
            $action, $objectType, $objectId, $actorId, $afterData, $result,
        );
        $payload = self::payload(
            $action,
            $objectType,
            $objectId,
            $actorId,
            $requestId,
            $beforeData,
            $afterData,
            $reason,
            $result,
            $errorCode,
            $idempotencyKey,
            $module,
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
                    'policy' => $policy,
                ]);

                // best_effort (ADR-AUDIT-001): đã cảnh báo ở mức critical; theo định nghĩa
                // policy này nghiệp vụ không bao giờ bị chặn vì audit, kể cả khi audit
                // chính lẫn outbox đều lỗi. outbox_required/fail_closed vẫn phải fail để
                // không tạo trạng thái nghiệp vụ mất traceability.
                if ($policy !== 'best_effort') {
                    throw new RuntimeException(
                        'Không thể ghi audit hoặc audit outbox; thao tác bị dừng để bảo toàn traceability.',
                        previous: $outboxError,
                    );
                }

                return;
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
        ?string $result = null,
        ?string $errorCode = null,
        ?string $idempotencyKey = null,
        ?string $module = null,
    ): void {
        static::record($action, $objectType, $objectId, $actorId, $requestId, $beforeData, $afterData, $reason, $result, $errorCode, $idempotencyKey, $module);
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
        ?string $result = null,
        ?string $errorCode = null,
        ?string $idempotencyKey = null,
        ?string $module = null,
    ): array {
        return [
            'actor_id' => $actorId,
            'action' => strtoupper($action),
            'object_type' => $objectType,
            'object_id' => $objectId,
            'reason' => $reason,
            'request_id' => self::resolveRequestId($requestId),
            'idempotency_key' => $idempotencyKey,
            'result' => $result,
            'error_code' => $errorCode,
            'module' => $module ?? self::classifyModule($objectType),
            'ip_address' => self::resolveIpAddress(),
            'before_data' => $beforeData === [] ? null : self::sanitize($beforeData),
            'after_data' => $afterData === [] ? null : self::sanitize($afterData),
            'created_at' => now(),
        ];
    }

    /**
     * Suy module/luồng từ `object_type` khi caller không truyền tường minh — cho
     * phép gắn module vào toàn bộ 130+ call site `AuditLogger::record()` hiện có
     * mà KHÔNG phải sửa từng chỗ (rủi ro hồi quy rất cao trên diện rộng đó).
     * Bậc 1: object_type tự thân đã rõ nghĩa (vd 'warranty_case',
     * 'inbound-documents' từ DenyAuditRecorder::inferEntity). Bậc 2 (fallback):
     * tên class gọi trực tiếp AuditLogger::record() — hầu hết controller trong
     * codebase này đặt tên rõ theo luồng (BaM1InboundDocumentController,
     * AlignedOutboundFlowController...). Không suy được -> SYSTEM (an toàn hơn
     * đoán sai vào 1 luồng nghiệp vụ cụ thể).
     */
    private static function classifyModule(string $objectType): string
    {
        $needle = strtolower($objectType);

        $rules = [
            self::MODULE_WARRANTY => ['warranty', 'component_issue'],
            self::MODULE_STOCKTAKE => ['reversal', 'stocktake', 'variance', 'reconciliation'],
            self::MODULE_INBOUND => ['inbound', 'putaway', 'receipt'],
            self::MODULE_OUTBOUND => ['outbound', 'stock_request'],
        ];

        foreach ($rules as $module => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $module;
                }
            }
        }

        $callerClass = self::callerClass();
        if ($callerClass !== null) {
            $needleClass = strtolower($callerClass);
            foreach ($rules as $module => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($needleClass, $keyword)) {
                        return $module;
                    }
                }
            }
        }

        return self::MODULE_SYSTEM;
    }

    /** Class đầu tiên bên ngoài AuditLogger/DenyAuditRecorder trong call stack hiện tại. */
    private static function callerClass(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;
            if ($class !== null && $class !== self::class && $class !== DenyAuditRecorder::class) {
                return $class;
            }
        }

        return null;
    }

    private static function resolveIpAddress(): ?string
    {
        try {
            $current = function_exists('request') ? request() : null;

            return $current?->ip();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * WWHN-222 (DEV222-P1-03): trước đây caller quên truyền $requestId thì cột
     * request_id sẽ null, phá chuỗi truy vết request→log→audit. Fallback về
     * request hiện tại (do ApiConventionMiddleware set) rồi tới Context — cùng
     * nguồn request_id mà middleware đã gắn vào mọi response envelope.
     */
    private static function resolveRequestId(?string $requestId): ?string
    {
        if ($requestId !== null && $requestId !== '') {
            return $requestId;
        }

        $current = function_exists('request') ? request() : null;
        if ($current !== null) {
            $attribute = $current->attributes->get('request_id');
            if (is_string($attribute) && $attribute !== '') {
                return $attribute;
            }
        }

        if (class_exists(Context::class) && Context::has('request_id')) {
            $contextValue = Context::get('request_id');
            if (is_string($contextValue) && $contextValue !== '') {
                return $contextValue;
            }
        }

        return $requestId;
    }

    private static function truncate(string $message): string
    {
        return mb_substr($message, 0, 2000);
    }
}
