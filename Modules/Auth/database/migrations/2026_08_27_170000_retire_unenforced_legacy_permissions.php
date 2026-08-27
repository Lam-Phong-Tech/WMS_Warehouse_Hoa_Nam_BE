<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thu hồi 21 mã quyền KHÔNG có cổng chặn nào đọc tới ("quyền ma").
 *
 * Bối cảnh (audit phân quyền 27/08): danh mục `permission` có 84 mã nhưng chỉ 58
 * mã xuất hiện trong middleware `permission:` và 3 mã được kiểm trong controller.
 * 21 mã còn lại (trừ 3 mã alias bị hợp đồng WWHN-180 ghim, xem
 * PermissionEnforcementMap::DEPRECATED) vẫn ACTIVE, vẫn được gán cho role và vẫn
 * hiện ra để tick trên ma trận IAM-01 — trong khi không route nào đọc tới. Quản
 * trị viên tick `user.manage` tưởng đã cấp quyền quản lý người dùng; cổng thật
 * là `iam.user.manage`.
 *
 * Vì sao thu hồi KHÔNG đổi quyền hiệu dụng của ai: mọi role đang giữ một mã cũ
 * đều đã giữ sẵn mã canonical tương ứng (đối chiếu trên DB dev 27/08). Ngoại lệ
 * duy nhất là `physical_code.view` — VIEWER và WAREHOUSE_STAFF giữ nó mà không có
 * `inventory.trace`. Nhưng vì `physical_code.view` chưa bao giờ được cưỡng chế,
 * hai vai đó vốn đã KHÔNG xem được danh sách mã vật lý (GET /physical-codes đòi
 * inventory.trace, trả 403). Migration cấp `inventory.trace` cho hai vai này để
 * giữ đúng ý định ban đầu thay vì âm thầm bỏ mất chức năng.
 *
 * Đồng thời gỡ `audit_log.view_all` khỏi VIEWER: cờ này bỏ qua giới hạn audit
 * theo module (AuditLogController::allowedModulesFor), tức một vai "chỉ xem" đọc
 * được nhật ký thao tác của mọi module và mọi người dùng, kể cả IAM.
 *
 * Dòng trong bảng `permission` được giữ lại với status=INACTIVE (không DELETE) để
 * không vỡ khoá ngoại và để audit log cũ còn tham chiếu được. `permissionCodes()`
 * chỉ đọc quyền ACTIVE nên INACTIVE là đủ để ngừng hiệu lực; các FormRequest cấp
 * quyền cũng đã kiểm status=ACTIVE để không ai gán lại được.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const RETIRED = [
        'user.view', 'user.manage',
        'role.view', 'role.manage', 'permission.view',
        'product.view', 'product.manage',
        'product_master.view', 'product_master.manage',
        'master.manage',
        'defect_catalog.view', 'defect_catalog.manage',
        'physical_code.view', 'physical_code.manage',
        'stock_document.view', 'stock_document.create', 'stock_document.scan',
        'stock_document.post', 'stock_document.cancel', 'stock_document.reverse',
        'outbound.create',
    ];

    /** @var array<string, array<int, string>> role_code => permission_key cần cấp bù */
    private const COMPENSATING_GRANTS = [
        'VIEWER' => ['inventory.trace'],
        'WAREHOUSE_STAFF' => ['inventory.trace'],
    ];

    /** @var array<string, array<int, string>> role_code => permission_key cần thu hồi theo chính sách */
    private const POLICY_REVOKES = [
        'VIEWER' => ['audit_log.view_all'],
    ];

    public function up(): void
    {
        if (! $this->relationExists('permission') || ! $this->relationExists('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::COMPENSATING_GRANTS as $roleCode => $permissionKeys) {
                foreach ($permissionKeys as $permissionKey) {
                    $this->grant($roleCode, $permissionKey);
                }
            }

            foreach (self::POLICY_REVOKES as $roleCode => $permissionKeys) {
                foreach ($permissionKeys as $permissionKey) {
                    $this->revoke($roleCode, $permissionKey);
                }
            }

            $retiredIds = DB::table('permission')
                ->whereIn('permission_key', self::RETIRED)
                ->pluck('permission_id')
                ->all();

            if ($retiredIds === []) {
                return;
            }

            DB::table('role_permission')->whereIn('permission_id', $retiredIds)->delete();

            if ($this->relationExists('user_permission')) {
                DB::table('user_permission')->whereIn('permission_id', $retiredIds)->delete();
            }

            DB::table('permission')
                ->whereIn('permission_id', $retiredIds)
                ->update(['status' => 'INACTIVE']);

            // Ai đang giữ role vừa đổi tập quyền phải được resolve lại RBAC ngay
            // request kế tiếp thay vì chờ hết TTL 300s (AuthCache key theo user.version).
            DB::table('app_user')->update(['version' => DB::raw('version + 1')]);
        });
    }

    /**
     * down() cố ý KHÔNG cấp lại quyền cho role.
     *
     * Rollback chỉ đưa danh mục về ACTIVE để migration thuận nghịch về mặt lược
     * đồ. Khôi phục các dòng role_permission đã xoá thì không: chúng chưa bao giờ
     * cấp thêm quyền thật nào, và tự động gán lại một tập quyền theo suy đoán là
     * thay đổi phân quyền âm thầm — đúng loại việc mà migration không được làm.
     * Cần khôi phục nguyên trạng thì chạy lại seeder ở môi trường dev.
     */
    public function down(): void
    {
        if (! $this->relationExists('permission')) {
            return;
        }

        DB::table('permission')
            ->whereIn('permission_key', self::RETIRED)
            ->update(['status' => 'ACTIVE']);
    }

    /**
     * `Schema::hasTable()` KHÔNG dùng được ở đây.
     *
     * Trên PostgreSQL, `permission` / `role` / `role_permission` / `app_user` là
     * VIEW tương thích (bảng canonical là `permissions` / `roles` /
     * `role_permissions` / `app_users`, ghi qua INSTEAD OF trigger). Laravel chỉ
     * liệt kê BASE TABLE nên `Schema::hasTable('permission')` trả false và toàn bộ
     * migration này im lặng không làm gì — migrate báo DONE, dữ liệu y nguyên.
     * Trên sqlite (môi trường test) chúng lại là bảng thật, nên lỗi không lộ ra ở
     * test. Kiểm bằng một truy vấn thật để đúng cho cả hai driver và cả hai dạng.
     */
    private function relationExists(string $name): bool
    {
        try {
            DB::table($name)->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function grant(string $roleCode, string $permissionKey): void
    {
        $roleId = DB::table('role')->where('role_code', $roleCode)->value('role_id');
        $permissionId = DB::table('permission')->where('permission_key', $permissionKey)->value('permission_id');

        if ($roleId === null || $permissionId === null) {
            return;
        }

        $exists = DB::table('role_permission')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('role_permission')->insert([
            'role_permission_id' => (string) Str::uuid(),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    private function revoke(string $roleCode, string $permissionKey): void
    {
        $roleId = DB::table('role')->where('role_code', $roleCode)->value('role_id');
        $permissionId = DB::table('permission')->where('permission_key', $permissionKey)->value('permission_id');

        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permission')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
    }
};
