<?php

namespace Modules\AuditLog\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WWHN-165 / WWHN-35 (QA1-AUD-002) — ghi audit cho "denied action".
 *
 * Trước thay đổi này, mọi 403 permission (CheckPermission) và 403 data-scope
 * (Enforce*WarehouseScope) đều KHÔNG để lại dấu vết audit — duy nhất
 * LOGIN_DENIED (AuthController) dùng result='DENIED'. ERD §7 quy định
 * `audit_logs.result IN ('SUCCESS','FAILED','DENIED')`, tức là schema đã
 * chừa chỗ cho denied-action audit nhưng không điểm phát nào dùng tới.
 *
 * Policy của call-site này là BEST_EFFORT theo ADR-AUDIT-002
 * (`docs/decision-records/ADR-AUDIT-002-deny-audit-policy.md`):
 * response 403 fail-closed đã là kết cục an toàn, deny không có business
 * side-effect nào cần traceability bắt buộc, nên lỗi ghi audit tuyệt đối
 * không được chặn/đổi response deny. Toàn bộ đường ghi được bọc try/catch
 * nuốt Throwable (AuditLogger vẫn tự lo outbox fallback bên trong); nếu cả
 * chuỗi ghi chết, chỉ Log::critical để tầng vận hành alert.
 *
 * KHÔNG ghi PII/token thô: metadata chỉ chứa route/method/permission/mã lỗi,
 * và AuditLogger::payload() đã sanitize after_data theo config('audit.sensitive_keys').
 */
class DenyAuditRecorder
{
    public const ACTION_PERMISSION_DENIED = 'AUTHZ_PERMISSION_DENIED';

    public const ACTION_DATA_SCOPE_DENIED = 'AUTHZ_DATA_SCOPE_DENIED';

    /**
     * 403 vì thiếu function permission (middleware `permission:<key>`).
     * Error code giữ nguyên vocabulary hiện hành: ACCESS_DENIED (WWHN-267).
     */
    public static function permissionDenied(Request $request, string $requiredPermission, ?string $actorId): void
    {
        self::record(
            $request,
            self::ACTION_PERMISSION_DENIED,
            'ACCESS_DENIED',
            $actorId,
            ['permission_required' => $requiredPermission],
            'Thiếu permission: '.$requiredPermission,
        );
    }

    /**
     * 403 vì dữ liệu nằm ngoài warehouse data-scope của actor.
     *
     * $errorCode nhận đúng mã mà middleware phát ra hiện nay — DATA_SCOPE_DENIED
     * cho inbound/outbound/reversal/component-issue, ACCESS_DENIED cho
     * EnforceInventoryWarehouseScope (WWHN-211). KHÔNG đổi vocabulary tại đây
     * để không vỡ hợp đồng lỗi đã chốt ở WWHN-267.
     */
    public static function dataScopeDenied(
        Request $request,
        string $reason,
        ?string $actorId = null,
        string $errorCode = 'DATA_SCOPE_DENIED',
    ): void {
        self::record(
            $request,
            self::ACTION_DATA_SCOPE_DENIED,
            $errorCode,
            $actorId ?? self::currentActorId(),
            ['scope_reason' => $reason],
            $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function record(
        Request $request,
        string $action,
        string $errorCode,
        ?string $actorId,
        array $metadata,
        string $reason,
    ): void {
        try {
            [$objectType, $objectId] = self::inferEntity($request);
            $requestId = $request->attributes->get('request_id');

            AuditLogger::record(
                action: $action,
                objectType: $objectType,
                objectId: $objectId,
                actorId: $actorId,
                requestId: is_string($requestId) && $requestId !== '' ? $requestId : null,
                afterData: $metadata + [
                    'route' => '/'.ltrim($request->path(), '/'),
                    'method' => $request->method(),
                    'error_code' => $errorCode,
                ],
                reason: $reason,
                result: 'DENIED',
                errorCode: $errorCode,
            );
        } catch (Throwable $exception) {
            // ADR-AUDIT-002: deny-audit là best_effort — response 403 đi tiếp
            // nguyên trạng. critical (không phải error) vì đây là điểm mù
            // audit trail cần alerting vận hành riêng.
            Log::critical('DENY_AUDIT_WRITE_FAILED', [
                'action' => $action,
                'error_code' => $errorCode,
                'path' => $request->path(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Suy entity từ route nếu suy được; middleware global (chạy trước router,
     * ví dụ EnforceInventoryWarehouseScope đăng ký ở bootstrap/app.php) có thể
     * chưa có route object — fallback về segment tài nguyên trên path.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function inferEntity(Request $request): array
    {
        $objectId = null;

        try {
            foreach (['id', 'documentId'] as $parameter) {
                $value = $request->route($parameter);
                if (is_string($value) && $value !== '') {
                    $objectId = $value;
                    break;
                }
            }
        } catch (Throwable) {
            $objectId = null;
        }

        // path dạng api/v1/<resource>/... — lấy <resource> làm object_type
        // (vd inbound-documents, inventory, reversal-documents).
        $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        $objectType = $segments[2] ?? ($segments[0] ?? 'http_request');

        return [$objectType, $objectId];
    }

    private static function currentActorId(): ?string
    {
        try {
            $id = auth('api')->id();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }
}
