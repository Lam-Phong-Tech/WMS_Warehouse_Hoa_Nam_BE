<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Route;

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
 * được suy ra trực tiếp từ bảng route lúc chạy. Chỉ hai thứ phải khai tay:
 *
 *  - ENFORCED_IN_CODE: quyền chặn bên trong controller (mask PII, bypass scope
 *    audit) nên không xuất hiện dưới dạng middleware `permission:`.
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
        'PATCH api/v1/notifications/{id}/read',
        'POST api/v1/notifications/read-all',
        'POST api/v1/broadcasting/auth',
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
