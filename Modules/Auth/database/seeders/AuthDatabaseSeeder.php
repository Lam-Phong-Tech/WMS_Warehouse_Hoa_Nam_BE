<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

class AuthDatabaseSeeder extends Seeder
{
    /**
     * Canonical permissions follow HN-BA-MASTER-DEVTEST-001 v1.0.
     * Legacy keys remain additive for backward compatibility only.
     */
    private const PERMISSIONS = [
        ['iam.user.view', 'View IAM users'],
        ['iam.user.manage', 'Manage IAM users and assignments'],
        ['iam.role.view', 'View IAM roles'],
        ['iam.role.manage', 'Manage IAM roles and permissions'],
        ['iam.permission.view', 'View IAM permissions'],

        ['warehouse.view', 'View warehouses'],
        ['warehouse.manage', 'Manage warehouses'],
        ['catalog.view', 'View canonical catalog masters'],
        ['catalog.manage', 'Manage canonical catalog masters'],
        ['sku.view', 'View SKUs/products'],
        ['sku.manage', 'Manage SKUs/products'],
        ['agency.view', 'View agencies'],
        ['agency.manage', 'Manage agencies'],
        ['defect.view', 'View defect catalog'],
        ['defect.manage', 'Manage defect catalog'],

        ['inbound.view', 'View inbound documents'],
        ['inbound.create', 'Create and edit inbound drafts'],
        ['inbound.scan', 'Scan inbound documents'],
        ['inbound.post', 'Post inbound receipt atomically'],
        ['inbound.cancel', 'Cancel inbound drafts'],
        ['outbound.view', 'View outbound documents'],
        ['outbound.import', 'Import outbound requests from Excel'],
        // outbound.create đã thu hồi: §8.1 gỡ POST /outbound-documents trực tiếp
        // từ lâu (phiếu xuất chỉ sinh qua Confirm yêu cầu xuất), nên mã này không
        // còn route nào đọc. Vai trò tạo yêu cầu xuất dùng outbound.request.*.
        ['outbound.scan', 'Scan and match outbound items'],
        ['outbound.post', 'Post outbound issue atomically'],
        ['outbound.cancel', 'Cancel outbound drafts'],

        ['inventory.view', 'View inventory balances and movements'],
        ['inventory.trace', 'Trace inventory movements and item history'],
        ['inventory.reversal.post', 'Post controlled reversal/correction'],
        ['physical_code.assign_rfid', 'Assign or revoke RFID physical code'],
        ['physical_code.create', 'Issue a brand-new item instance with a new Barcode/QR physical code'],
        ['label.print', 'Print labels'],
        ['label.reprint', 'Reprint labels'],
        ['warranty.manage', 'Manage warranty cases and events'],
        ['warranty.component_issue', 'Issue components for active warranty cases'],
        ['warranty.pii.view', 'View warranty PII'],
        ['warranty.pii.export', 'Export warranty PII'],
        ['scan.classify', 'Classify scans without stock effect'],
        ['pv.publish', 'Publish PV catalog'],
        ['report.view', 'View reports'],
        ['report.export', 'Export reports'],
        ['audit_log.view', 'View audit logs'],
        // Mục 6 (audit log theo module): audit_log.view giờ bị giới hạn theo
        // module/quyền của actor (xem AuditLogController::allowedModulesFor).
        // audit_log.view_all là cờ bypass — chỉ cấp cho các role vốn đã được
        // xem audit log không giới hạn từ trước, để KHÔNG đổi hành vi hiện có.
        ['audit_log.view_all', 'View audit logs across all modules (bypass per-module scope)'],

        ['putaway.view', 'View putaway tasks (Annex C)'],
        ['putaway.manage', 'Create/confirm/cancel putaway tasks (Annex C)'],
        ['warranty.view', 'View warranty cases (Annex C)'],
        ['scan.execute', 'Classify scan batches (Annex C alias of scan.classify)'],
        ['packing_label.preview', 'Preview packing label before completion'],
        ['packing_label.ready', 'Freeze packing label snapshot (READY_TO_PRINT)'],
        ['packing_label.print', 'Print packing label'],
        ['packing_label.reprint', 'Reprint packing label after completion'],
        ['outbound.request.import', 'Import outbound request (Annex C)'],
        ['outbound.request.confirm', 'Confirm outbound request import (Annex C)'],
        ['outbound.request.manual_create', 'Create outbound request manually (Annex C)'],
        ['pv.master.manage', 'Manage PV industry/tag/mapping/recommendation'],

        ['warranty.temp_only', 'Legacy warranty temporary intake permission'],

        // 21 mã cũ (user.*, role.*, permission.view, product.*, product_master.*,
        // master.manage, defect_catalog.*, physical_code.view/manage,
        // stock_document.*, outbound.create) ĐÃ ĐƯỢC THU HỒI — xem migration
        // 2026_08_27_170000_retire_unenforced_legacy_permissions và
        // Modules/Auth/app/Support/PermissionEnforcementMap.
        //
        // Chúng chưa bao giờ có cổng chặn nào đọc tới: mọi route dùng mã canonical
        // (iam.user.manage, sku.view, inbound.scan, ...). Giữ chúng ACTIVE trong
        // danh mục chỉ tạo ra "quyền ma" trên ma trận IAM-01 — quản trị viên tick
        // `user.manage` tưởng đã cấp quyền quản lý người dùng trong khi không cấp
        // gì cả. Mọi role đang giữ mã cũ đều đã giữ sẵn mã canonical tương ứng nên
        // việc thu hồi KHÔNG đổi quyền hiệu dụng của bất kỳ ai (ngoại lệ duy nhất
        // là physical_code.view -> bù bằng inventory.trace, xem VIEWER/WAREHOUSE_STAFF).
        //
        // KHÔNG thêm mã mới vào đây mà không có route/`hasPermission()` đọc tới:
        // AuthorizationCoverageInvariantTest sẽ đỏ.

        ['import_batch.view', 'View import batches'],
        ['import_batch.manage', 'Manage import batches'],
        ['container.view', 'View containers'],
        ['container.manage', 'Manage containers'],
        ['item_instance.view', 'View item instances'],
        ['item_instance.manage', 'Manage item instances'],
    ];

    private const ROLES = [
        'ADMIN' => [
            'name' => 'Administrator',
            'permissions' => [
                'iam.user.view', 'iam.user.manage', 'iam.role.view', 'iam.role.manage', 'iam.permission.view',
                'warehouse.view', 'warehouse.manage', 'catalog.view', 'catalog.manage', 'sku.view', 'sku.manage',
                'agency.view', 'agency.manage', 'defect.view', 'defect.manage',
                'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
                'outbound.view', 'outbound.import', 'outbound.scan', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint',
                'warranty.manage', 'warranty.component_issue', 'warranty.pii.view', 'warranty.pii.export',
                // Ghi chú kiểm tra 27/08: ADMIN KHÔNG có warranty.temp_only trong khi
                // WAREHOUSE_MANAGER có — lệch giữa hai vai quản trị. Cố ý KHÔNG tự thêm
                // ở đây: mở rộng quyền là quyết định nghiệp vụ, không phải việc của một
                // đợt siết bảo mật. Chờ BA chốt rồi bổ sung.
                'scan.classify', 'pv.publish', 'report.view', 'report.export', 'audit_log.view', 'audit_log.view_all',
                'import_batch.view', 'import_batch.manage',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
                'putaway.view', 'putaway.manage', 'warranty.view', 'scan.execute',
                'packing_label.preview', 'packing_label.ready', 'packing_label.print', 'packing_label.reprint',
                'outbound.request.import', 'outbound.request.confirm', 'outbound.request.manual_create',
                'pv.master.manage',
            ],
        ],
        'WAREHOUSE_STAFF' => [
            'name' => 'Warehouse Staff',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'inbound.create', 'inbound.scan',
                'outbound.view', 'outbound.scan',
                'inventory.view', 'report.view',
                // inventory.trace thay cho physical_code.view đã thu hồi: màn SCAN/
                // tra cứu mã vật lý (GET /physical-codes, /inventory/trace/{code})
                // cưỡng chế bằng inventory.trace. Trước đây vai này vào được màn nhờ
                // scan.execute nhưng mọi lời gọi danh sách đều 403 — physical_code.view
                // mà họ đang giữ không có cổng nào đọc tới.
                'inventory.trace',
                'agency.view', 'defect.view',
                'import_batch.view', 'container.view', 'item_instance.view',
                'putaway.view', 'putaway.manage', 'scan.execute',
                'packing_label.preview', 'packing_label.ready', 'packing_label.print',
                'outbound.request.import', 'outbound.request.confirm', 'outbound.request.manual_create',
            ],
        ],
        'WAREHOUSE_APPROVER' => [
            'name' => 'Warehouse Approver',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'inbound.post', 'inbound.cancel',
                'outbound.view', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'report.view',
                'agency.view', 'defect.view',
                'import_batch.view', 'container.view', 'item_instance.view',
                'putaway.view', 'warranty.view',
            ],
        ],
        'WAREHOUSE_KEEPER' => [
            'name' => 'Warehouse Keeper',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'inbound.scan', 'inbound.post', 'inbound.cancel',
                'outbound.view', 'outbound.import', 'outbound.scan', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint',
                'warranty.manage', 'warranty.component_issue', 'report.view',
                'import_batch.view',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
                'putaway.view', 'putaway.manage', 'warranty.view', 'scan.execute',
                'packing_label.preview', 'packing_label.ready', 'packing_label.print', 'packing_label.reprint',
                'outbound.request.import', 'outbound.request.confirm', 'outbound.request.manual_create',
            ],
        ],
        'WARRANTY_STAFF' => [
            'name' => 'Warranty Staff',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inventory.view', 'inventory.trace',
                'warranty.manage', 'warranty.component_issue',
                'scan.classify', 'item_instance.view',
                'warranty.view', 'scan.execute',
            ],
        ],
        'LEADERSHIP' => [
            'name' => 'Leadership',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inventory.view', 'inventory.trace', 'report.view',
                'agency.view', 'defect.view', 'audit_log.view', 'audit_log.view_all',
                'putaway.view', 'warranty.view',
            ],
        ],
        'VIEWER' => [
            'name' => 'Viewer',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'outbound.view', 'inventory.view', 'report.view', 'audit_log.view',
                // audit_log.view_all ĐÃ GỠ khỏi VIEWER: cờ này bỏ qua toàn bộ giới hạn
                // audit theo module của actor (AuditLogController::allowedModulesFor),
                // tức một vai "chỉ xem" lại đọc được nhật ký thao tác của MỌI module và
                // MỌI người dùng, kể cả IAM. Giữ lại cho LEADERSHIP/ADMIN/WAREHOUSE_MANAGER
                // là các vai giám sát thật sự. VIEWER vẫn có audit_log.view (xem nhật ký
                // trong phạm vi module mà chính họ có quyền nghiệp vụ).
                'agency.view', 'defect.view', 'import_batch.view',
                'container.view', 'item_instance.view',
                // inventory.trace: xem ghi chú ở WAREHOUSE_STAFF — thay physical_code.view.
                'inventory.trace',
            ],
        ],
        'WAREHOUSE_MANAGER' => [
            'name' => 'Warehouse Manager',
            'permissions' => [
                'iam.user.view', 'iam.user.manage', 'iam.role.view', 'iam.role.manage', 'iam.permission.view',
                'warehouse.view', 'warehouse.manage', 'catalog.view', 'catalog.manage', 'sku.view', 'sku.manage',
                'agency.view', 'agency.manage', 'defect.view', 'defect.manage',
                'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
                'outbound.view', 'outbound.import', 'outbound.scan', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint', 'pv.publish',
                'pv.master.manage',
                'warranty.manage', 'warranty.component_issue', 'warranty.pii.view', 'warranty.pii.export',
                'warranty.temp_only',
                'scan.classify', 'report.view', 'report.export', 'audit_log.view', 'audit_log.view_all',
                'import_batch.view', 'import_batch.manage',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
            ],
        ],
        'SKU_MANAGER' => [
            'name' => 'SKU Manager Test',
            'permissions' => ['catalog.view', 'sku.view', 'sku.manage'],
        ],
        'MASTER_MANAGER' => [
            'name' => 'Master Data Manager Test',
            'permissions' => ['catalog.view', 'catalog.manage'],
        ],
        'NO_PERMISSION' => [
            'name' => 'No Permission Test',
            'permissions' => [],
        ],
        'INBOUND_ONLY_TEST' => [
            'name' => 'Inbound Only Test',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint',
                'report.view',
                'import_batch.view',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
                'putaway.view', 'putaway.manage', 'scan.execute',
            ],
        ],
    ];

    /**
     * 'department' (department_code, xem DepartmentSeeder) chỉ khai cho user có vai
     * trò khớp tự nhiên với một phòng ban thật — tránh gán bừa để không tạo dữ liệu
     * tổ chức sai lệch. Warehouse Staff/Approver/Keeper/Manager đều trực tiếp vận
     * hành kho nên thuộc "Phòng quản lý kho"; các user còn lại (test-*, viewer01)
     * chưa có vai trò kinh doanh/bảo hành tương ứng nên để trống (null), hiển thị
     * "—" ở cột Phòng ban cho tới khi được gán tay qua UI.
     */
    private const USERS = [
        ['username' => 'staff01', 'display_name' => 'Warehouse Staff 01', 'email' => 'staff01@gmail.com', 'role' => 'WAREHOUSE_STAFF', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'approver01', 'display_name' => 'Warehouse Approver 01', 'email' => 'approver01@gmail.com', 'role' => 'WAREHOUSE_APPROVER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'keeper01', 'display_name' => 'Warehouse Keeper 01', 'email' => 'keeper01@gmail.com', 'role' => 'WAREHOUSE_KEEPER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'viewer01', 'display_name' => 'Viewer 01', 'email' => 'viewer01@gmail.com', 'role' => 'VIEWER'],
        ['username' => 'manager01', 'display_name' => 'Warehouse Manager 01', 'email' => 'manager01@gmail.com', 'role' => 'WAREHOUSE_MANAGER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'test-sku-manage', 'display_name' => 'Test SKU Manage', 'email' => 'test-sku-manage@hoanam.com', 'role' => 'SKU_MANAGER'],
        ['username' => 'test-master-manage', 'display_name' => 'Test Master Manage', 'email' => 'test-master-manage@hoanam.com', 'role' => 'MASTER_MANAGER'],
        ['username' => 'test-no-permission', 'display_name' => 'Test No Permission', 'email' => 'test-no-permission@hoanam.com', 'role' => 'NO_PERMISSION'],
        ['username' => 'test-super-admin', 'display_name' => 'Test Super Admin', 'email' => 'test-super-admin@hoanam.com', 'role' => 'SUPER_ADMIN'],
        ['username' => 'inbound-tester01', 'display_name' => 'Inbound Only Tester', 'email' => 'inbound-tester01@hoanam.com', 'role' => 'INBOUND_ONLY_TEST'],
    ];

    private const NON_PRODUCTION_ROLES = ['SKU_MANAGER', 'MASTER_MANAGER', 'NO_PERMISSION', 'INBOUND_ONLY_TEST'];

    private const DEFAULT_PASSWORD = 'Cntt@1234';

    public function run(): void
    {
        $withFixtures = $this->allowsNonProductionFixtures();
        $adminPassword = $this->adminPassword();

        $permissionsByKey = [];
        foreach (self::PERMISSIONS as [$key, $name]) {
            $permissionsByKey[$key] = Permission::updateOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $name, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
        }

        $superAdminRole = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'System Administrator', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $roleModels = ['SUPER_ADMIN' => $superAdminRole];

        $adminUser = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Administrator',
                'email' => 'admin@gmail.com',
                'password_hash' => Hash::make($adminPassword),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );
        $this->attachRole($adminUser, $superAdminRole);

        foreach (self::ROLES as $code => $def) {
            if (! $withFixtures && in_array($code, self::NON_PRODUCTION_ROLES, true)) {
                continue;
            }

            $role = Role::updateOrCreate(
                ['role_code' => $code],
                ['role_name' => $def['name'], 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );

            $pivotData = [];
            foreach ($def['permissions'] as $key) {
                $pivotData[$permissionsByKey[$key]->permission_id] = ['role_permission_id' => (string) Str::uuid()];
            }
            $role->permissions()->sync($pivotData);
            $roleModels[$code] = $role;
        }

        $this->call(WmsPermissionCompatibilitySeeder::class);
        $this->call(DepartmentSeeder::class);
        $departmentIdByCode = Department::query()->pluck('department_id', 'department_code')->all();

        if (! $withFixtures) {
            $this->command?->warn(
                'AuthDatabaseSeeder: bỏ qua toàn bộ tài khoản/role fixture vì môi trường "'
                .app()->environment().'" không nằm trong golive.development_environments. '
                .'Tạo người dùng thật qua IAM (POST /api/v1/users).'
            );

            return;
        }

        foreach (self::USERS as $u) {
            $user = User::updateOrCreate(
                ['username' => $u['username']],
                [
                    'display_name' => $u['display_name'],
                    'email' => $u['email'],
                    'password_hash' => Hash::make($this->fixturePassword()),
                    'status' => 'ACTIVE',
                    'version' => 1,
                    'department_id' => isset($u['department']) ? ($departmentIdByCode[$u['department']] ?? null) : null,
                ]
            );
            $this->attachRole($user, $roleModels[$u['role']]);
        }

        $this->seedSampleUserPermissionGrant($permissionsByKey, $adminUser);
    }

    /**
     * Demo tính năng "quyền cấp riêng cho tài khoản" (User::grantedPermissions(),
     * bảng user_permission — mới thêm 27/08) — trước đây bảng này luôn rỗng sau
     * db:seed dù UI/BE đã hỗ trợ, không có dữ liệu mẫu nào để kiểm thử.
     * keeper01 (WAREHOUSE_KEEPER) vốn không có report.export qua role, cấp thêm
     * riêng để minh hoạ đúng ngữ nghĩa "cộng thêm, không thay thế quyền role".
     */
    private function seedSampleUserPermissionGrant(array $permissionsByKey, User $adminUser): void
    {
        if (! Schema::hasTable('user_permission')) {
            return;
        }

        $keeper = User::where('username', 'keeper01')->first();
        $reportExport = $permissionsByKey['report.export'] ?? null;
        if ($keeper === null || $reportExport === null) {
            return;
        }

        DB::table('user_permission')->updateOrInsert(
            ['user_id' => $keeper->user_id, 'permission_id' => $reportExport->permission_id],
            [
                'user_permission_id' => (string) Str::uuid(),
                'granted_by' => $adminUser->user_id,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function attachRole(User $user, Role $role): void
    {
        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }
    }

    private function allowsNonProductionFixtures(): bool
    {
        if ($this->isDevelopmentEnvironment()) {
            return true;
        }

        return (bool) config('golive.seed.demo_accounts', false);
    }

    private function isDevelopmentEnvironment(): bool
    {
        return in_array(
            app()->environment(),
            array_map('strval', (array) config('golive.development_environments', ['local', 'testing'])),
            true,
        );
    }

    private function adminPassword(): string
    {
        return self::DEFAULT_PASSWORD;
    }

    private function fixturePassword(): string
    {
        $ciPassword = env('CI_DEMO_PASSWORD');

        return is_string($ciPassword) && $ciPassword !== '' ? $ciPassword : self::DEFAULT_PASSWORD;
    }
}
