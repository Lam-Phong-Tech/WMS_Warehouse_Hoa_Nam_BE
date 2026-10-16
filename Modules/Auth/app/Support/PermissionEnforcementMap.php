<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P03DraftController;
use Modules\Warehouse\Http\Controllers\P04InboundController;
use Modules\Warehouse\Http\Controllers\P05OutboundController;
use Modules\Warehouse\Http\Controllers\P06CatalogController;
use Modules\Warehouse\Services\AttachmentUploadReceipts;

/**
 * Nguồn sự thật DUY NHẤT về "mã quyền nào thực sự có cổng chặn".
 *
 * Gốc rễ của lớp lỗi "quyền ma": danh mục `permission` là một bảng dữ liệu tự
 * do, còn cổng chặn thật lại nằm rải rác ở `->middleware('permission:<CODE>')`
 * và vài chỗ `hasPermission()` trong controller. Hai thứ đó chưa bao giờ được
 * đối chiếu, nên một mã có thể tồn tại ACTIVE, được tick trên ma trận IAM-01,
 * được gán cho role — mà không cổng nào đọc tới. Quản trị viên tick `user.manage`
 * tưởng đã cấp quyền quản lý người dùng, trong khi cổng thật là `iam.user.manage`.
 *
 * Lớp này KHÔNG duy trì một danh sách thứ hai để lệch pha: tập "được cưỡng chế"
 * được suy ra trực tiếp từ bảng route lúc chạy. Các ngoại lệ phải khai tay:
 *
 *  - ENFORCED_IN_CODE: quyền chặn bên trong controller (mask PII, bypass scope
 *    audit) nên không xuất hiện dưới dạng middleware `permission:`.
 *  - IN_CODE_GUARDED_ROUTES: route dùng guard của owner, ghim đúng action.
 *  - DEPRECATED: mã cũ còn tồn tại vì hợp đồng đã chốt, nhưng không còn cổng nào
 *    đọc tới. Giữ lại thì phải nói rõ mã canonical thay thế, để UI hiển thị được
 *    "không còn hiệu lực" thay vì bày ra như một quyền dùng được.
 *
 * `AuthorizationCoverageInvariantTest` chốt bất biến: mọi quyền ACTIVE phải nằm
 * trong (route ∪ ENFORCED_IN_CODE ∪ DEPRECATED) — thêm quyền ma mới sẽ đỏ test.
 */
final class PermissionEnforcementMap
{
    /**
     * Quyền được kiểm bên trong controller thay vì bằng middleware `permission:`.
     *
     * @var array<string, string>
     */
    public const ENFORCED_IN_CODE = [
        'warranty.pii.view' => 'Bỏ mask PII bảo hành (CurrentBaselineWarrantyController, OperationalReportController, StockMovementController, CanonicalMvpController)',
        'warranty.pii.export' => 'Cho phép xuất báo cáo bảo hành kèm PII (AlignedWarrantyReportController, OperationalReportController::assertPiiExportAllowed)',
        'audit_log.view_all' => 'Bỏ giới hạn audit log theo module của actor (AuditLogController::allowedModulesFor)',
        'documents.read' => 'P09WarrantyScope, P09WarrantyAttachmentGuard, AttachmentUploadReceipts, P08HistoryController và P20ComponentHistoryController kiểm quyền đọc tài liệu/liên kết trước khi trả dữ liệu.',
    ];

    /**
     * Mã cũ giữ ACTIVE theo hợp đồng WWHN-180 (WmsPermissionCompatibilitySeeder),
     * nhưng KHÔNG còn cổng nào đọc tới. Giá trị = mã canonical thay thế.
     *
     * Mọi mã cũ khác đã được thu hồi hẳn — xem migration
     * 2026_08_27_170000_retire_unenforced_legacy_permissions.
     *
     * @var array<string, string>
     */
    public const DEPRECATED = [
        'scan.inbound' => 'inbound.scan',
        'scan.outbound' => 'outbound.scan',
        'scan.warranty' => 'warranty.view',
    ];

    /**
     * Route cố ý KHÔNG có `permission:` vì đã tự giới hạn theo chính user đang
     * đăng nhập (mỗi truy vấn đều `where user_id = <actor>`). Khai tường minh ở
     * đây để test bất biến phân biệt được "tự-scope có chủ ý" với "quên gate".
     *
     * @var array<int, string>
     */
    public const SELF_SCOPED_ROUTES = [
        'GET api/v1/auth/me',
        // Sửa hồ sơ + quản lý thiết bị của CHÍNH mình. AuthController đọc/ghi theo
        // `auth()->user()` và không nhận user_id từ client; phiên của người khác trả
        // 404. Allowlist trường ở updateMe() chặn cứng việc tự nâng vai trò/quyền.
        'PATCH api/v1/auth/me',
        'GET api/v1/auth/sessions',
        'DELETE api/v1/auth/sessions/{id}',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/change-password',
        // Tự quản lý xác thực hai bước của CHÍNH mình. MfaService khoá mọi truy
        // vấn theo `user_id = <actor>` và không nhận tham số nào trỏ sang user
        // khác, nên không có đường leo thang. Gắn `permission:` vào đây sẽ biến
        // việc tự bật MFA thành một quyền phải được cấp — đúng chiều ngược lại
        // với mục tiêu bảo mật, và sẽ chặn luôn cả người đang bị bắt buộc bật.
        'GET api/v1/auth/mfa/status',
        'POST api/v1/auth/mfa/enroll',
        'POST api/v1/auth/mfa/confirm',
        'POST api/v1/auth/mfa/disable',
        'POST api/v1/auth/switch-context',
        'GET api/v1/audit-logs/me',
        'GET api/v1/notifications',
        'GET api/v1/notifications/unread-count',
        'GET api/v1/notifications/{id}',
        'PATCH api/v1/notifications/{id}/read',
        'POST api/v1/notifications/read-all',
        'POST api/v1/broadcasting/auth',
    ];

    /**
     * Exact owner actions with transactional permission/scope checks in code.
     * These are not permission-free routes or a prefix-wide exemption. Binding
     * each declaration to its action makes redirects/renames fail the invariant.
     *
     * @var array<string, string>
     */
    public const IN_CODE_GUARDED_ROUTES = [
        // Context returns only this actor's session and effective capabilities;
        // it does not grant permission to read any business record.
        'GET api/v1/mini-app/p03/context' => P03DraftController::class.'@context',
        'GET api/v1/mini-app/inbound/context' => P04InboundController::class.'@context',
        'GET api/v1/mini-app/outbound/context' => P05OutboundController::class.'@context',
        'GET api/v1/mini-app/catalog/context' => P06CatalogController::class.'@context',
        // MiniAppDraftService authorizes the operation matrix before reading or
        // replaying, and intersects actor + stable session + confirmed warehouse.
        'GET api/v1/mini-app/drafts' => P03DraftController::class.'@index',
        'GET api/v1/mini-app/drafts/{id}' => P03DraftController::class.'@show',
        'GET api/v1/mini-app/drafts/{id}/requests/{requestId}' => P03DraftController::class.'@reconcile',
        'PUT api/v1/mini-app/drafts/{id}' => P03DraftController::class.'@save',
        'POST api/v1/mini-app/drafts/{id}/cancel' => P03DraftController::class.'@cancel',
        // P04 scope uses INBOUND read/save/cancel rights (including the AND/OR
        // rules in MiniAppDraftService), active canonical warehouse and session.
        // Suppliers is a global directory but still requires INBOUND read.
        'GET api/v1/mini-app/inbound/types' => P04InboundController::class.'@types',
        'GET api/v1/mini-app/inbound/suppliers' => P04InboundController::class.'@suppliers',
        'PUT api/v1/mini-app/inbound/drafts/{id}' => P04InboundController::class.'@save',
        'GET api/v1/mini-app/inbound/drafts/{id}' => P04InboundController::class.'@show',
        'GET api/v1/mini-app/inbound/drafts/{id}/scan-session' => P04InboundController::class.'@scanSession',
        'POST api/v1/mini-app/inbound/drafts/{id}/validate-code' => P04InboundController::class.'@validateCode',
        'POST api/v1/mini-app/inbound/drafts/{id}/review' => P04InboundController::class.'@review',
        'POST api/v1/mini-app/inbound/drafts/{id}/record' => P04InboundController::class.'@record',
        'GET api/v1/mini-app/inbound/drafts/{id}/requests/{requestId}' => P04InboundController::class.'@reconcile',
        'POST api/v1/mini-app/inbound/drafts/{id}/cancel' => P04InboundController::class.'@cancel',
        'GET api/v1/mini-app/inbound/documents/{id}' => P04InboundController::class.'@document',
        // Canonical P05 documents are warehouse-shared, not personal drafts.
        // scope() requires OUTBOUND read and the confirmed active warehouse.
        'GET api/v1/mini-app/outbound/sources' => P05OutboundController::class.'@sources',
        'GET api/v1/mini-app/outbound/sources/{id}' => P05OutboundController::class.'@source',
        'GET api/v1/mini-app/outbound/documents/{id}' => P05OutboundController::class.'@document',
        // P18 checks the parent's read ACL before querying a receipt, then exact
        // actor/session/warehouse/parent/key/digest; UNKNOWN never bypasses ACL.
        'GET api/v1/mini-app/inbound-documents/{id}/attachments/requests/{operationKey}' => AttachmentUploadReceipts::class.'@show',
        'GET api/v1/mini-app/outbound-documents/{id}/attachments/requests/{operationKey}' => AttachmentUploadReceipts::class.'@show',
        'GET api/v1/mini-app/warranty-cases/{id}/attachments/requests/{operationKey}' => AttachmentUploadReceipts::class.'@show',
    ];

    /** @var array<int, string>|null */
    private static ?array $routeCodesMemo = null;

    private function __construct() {}

    /**
     * Mã quyền xuất hiện trong middleware `permission:` của bất kỳ route nào.
     * Dạng OR `A|B` được tách thành từng mã.
     *
     * @return array<int, string>
     */
    public static function routeEnforcedCodes(): array
    {
        if (self::$routeCodesMemo !== null) {
            return self::$routeCodesMemo;
        }

        $codes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }
                foreach (explode('|', substr($middleware, 11)) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        $codes[$candidate] = true;
                    }
                }
            }
        }

        ksort($codes);

        return self::$routeCodesMemo = array_keys($codes);
    }

    /** Chỉ dùng trong test — bảng route thay đổi giữa các test case. */
    public static function flushMemo(): void
    {
        self::$routeCodesMemo = null;
    }

    /** @return array<int, string> */
    public static function enforcedCodes(): array
    {
        $codes = array_unique(array_merge(
            self::routeEnforcedCodes(),
            array_keys(self::ENFORCED_IN_CODE),
        ));
        sort($codes);

        return $codes;
    }

    public static function isEnforced(string $permissionKey): bool
    {
        return in_array($permissionKey, self::enforcedCodes(), true);
    }

    /** Mã canonical thay thế cho một mã đã ngừng hiệu lực (null nếu không phải mã cũ). */
    public static function replacementFor(string $permissionKey): ?string
    {
        return self::DEPRECATED[$permissionKey] ?? null;
    }
}
