<?php

namespace Modules\AuditLog\Services;

use Modules\AuditLog\Models\AuditLog;

/**
 * Class AuditLogger DUY NHẤT của dự án — không để module khác tự viết logger riêng đè lên
 * nó (bài học từ ERP-Backend gốc: từng có nhiều class AuditLogger trùng tên khác đích, một
 * class trỏ nhầm bảng đã bị module khác `dropIfExists`, gây mất audit trail âm thầm).
 *
 * Gọi trực tiếp từ Service/Controller ở mọi module tại các mutation point quan trọng —
 * không phải Eloquent observer/global event listener tự động.
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
        try {
            AuditLog::create([
                'actor_id' => $actorId,
                'action' => strtoupper($action),
                'object_type' => $objectType,
                'object_id' => $objectId,
                'reason' => $reason,
                'request_id' => $requestId,
                'before_data' => $beforeData ?: null,
                'after_data' => $afterData ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Lỗi ghi audit KHÔNG được làm hỏng nghiệp vụ chính — chỉ report để theo dõi.
            report($e);
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
}
