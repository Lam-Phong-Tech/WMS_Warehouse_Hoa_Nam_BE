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
        ['iam.user.view', 'Xem người dùng IAM'],
        ['iam.user.manage', 'Quản lý người dùng và gán vai trò IAM'],
        ['iam.role.view', 'Xem vai trò IAM'],
        ['iam.role.manage', 'Quản lý vai trò và quyền IAM'],
        ['iam.permission.view', 'Xem danh mục quyền IAM'],

        ['warehouse.view', 'Xem kho'],
        ['warehouse.manage', 'Quản lý kho'],
        ['catalog.view', 'Xem danh mục gốc chuẩn hóa'],
        ['catalog.manage', 'Quản lý danh mục gốc chuẩn hóa'],
        ['sku.view', 'Xem SKU/sản phẩm'],
        ['sku.manage', 'Quản lý SKU/sản phẩm'],
        ['agency.view', 'Xem đại lý'],
        ['agency.manage', 'Quản lý đại lý'],
        ['defect.view', 'Xem danh mục lỗi'],
        ['defect.manage', 'Quản lý danh mục lỗi'],

        ['inbound.view', 'Xem chứng từ nhập kho'],
        ['inbound.create', 'Tạo và sửa phiếu nhập nháp'],
        ['inbound.scan', 'Quét mã chứng từ nhập kho'],
        ['inbound.post', 'Ghi sổ phiếu nhập nguyên tử'],
        ['inbound.cancel', 'Hủy phiếu nhập nháp'],
        ['outbound.view', 'Xem chứng từ xuất kho'],
        ['outbound.import', 'Nhập yêu cầu xuất kho từ Excel'],
        // outbound.create đã thu hồi: §8.1 gỡ POST /outbound-documents trực tiếp
        // từ lâu (phiếu xuất chỉ sinh qua Confirm yêu cầu xuất), nên mã này không
        // còn route nào đọc. Vai trò tạo yêu cầu xuất dùng outbound.request.*.
        ['outbound.scan', 'Quét và khớp mặt hàng xuất kho'],
        ['outbound.post', 'Ghi sổ phiếu xuất nguyên tử'],
        ['outbound.cancel', 'Hủy phiếu xuất nháp'],

        ['inventory.view', 'Xem tồn kho và biến động'],
        ['inventory.trace', 'Truy vết biến động và lịch sử mặt hàng'],
        ['inventory.reversal.post', 'Ghi sổ điều chỉnh/đảo bút toán có kiểm soát'],
        ['physical_code.assign_rfid', 'Gán hoặc thu hồi mã vật lý RFID'],
        ['physical_code.create', 'Cấp mã vật lý Barcode/QR mới cho mặt hàng'],
        ['label.print', 'In nhãn'],
        ['label.reprint', 'In lại nhãn'],
        ['warranty.manage', 'Quản lý case và sự kiện bảo hành'],
        ['warranty.component_issue', 'Xuất linh kiện cho case bảo hành đang xử lý'],
        ['warranty.pii.view', 'Xem dữ liệu cá nhân (PII) bảo hành'],
        ['warranty.pii.export', 'Xuất dữ liệu cá nhân (PII) bảo hành'],
        ['scan.classify', 'Phân loại lượt quét không ảnh hưởng tồn kho'],
        ['pv.publish', 'Công bố danh mục PV'],
        ['report.view', 'Xem báo cáo'],
        ['report.export', 'Xuất báo cáo'],
        ['audit_log.view', 'Xem nhật ký audit'],
        // Mục 6 (audit log theo module): audit_log.view giờ bị giới hạn theo
        // module/quyền của actor (xem AuditLogController::allowedModulesFor).
        // audit_log.view_all là cờ bypass — chỉ cấp cho các role vốn đã được
        // xem audit log không giới hạn từ trước, để KHÔNG đổi hành vi hiện có.
        ['audit_log.view_all', 'Xem nhật ký audit của mọi module (bỏ qua giới hạn theo từng module)'],

        ['putaway.view', 'Xem tác vụ cất hàng (Annex C)'],
        ['putaway.manage', 'Tạo/xác nhận/hủy tác vụ cất hàng (Annex C)'],
        ['warranty.view', 'Xem case bảo hành (Annex C)'],
        ['scan.execute', 'Phân loại lô quét (Annex C, tương đương scan.classify)'],
        ['packing_label.preview', 'Xem trước nhãn đóng gói trước khi hoàn tất'],
        ['packing_label.ready', 'Đóng băng bản chụp nhãn đóng gói (READY_TO_PRINT)'],
        ['packing_label.print', 'In nhãn đóng gói'],
        ['packing_label.reprint', 'In lại nhãn đóng gói sau khi hoàn tất'],
        ['outbound.request.import', 'Nhập yêu cầu xuất kho (Annex C)'],
        ['outbound.request.confirm', 'Xác nhận import yêu cầu xuất kho (Annex C)'],
        ['outbound.request.manual_create', 'Tạo thủ công yêu cầu xuất kho (Annex C)'],
        ['pv.master.manage', 'Quản lý ngành hàng/tag/mapping/gợi ý PV'],

        ['warranty.temp_only', 'Quyền tiếp nhận tạm thời bảo hành (legacy)'],

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

        ['import_batch.view', 'Xem lô nhập dữ liệu'],
        ['import_batch.manage', 'Quản lý lô nhập dữ liệu'],
        ['container.view', 'Xem container'],
        ['container.manage', 'Quản lý container'],
        ['item_instance.view', 'Xem instance mặt hàng'],
        ['item_instance.manage', 'Quản lý instance mặt hàng'],
    ];

    private const ROLES = [
        'ADMIN' => [
            'name' => 'Quản trị viên',
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
            'name' => 'Nhân viên kho',
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
            'name' => 'Người duyệt kho',
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
            'name' => 'Thủ kho',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                // inbound.create bổ sung 05/09/2026: SRS v1.12 §3 hàng "Inbound
                // create/cancel/scan/post" đánh A cho actor "Quản lý kho", mà
                // ROLE_BASELINE_PIN.json ánh xạ WAREHOUSE_KEEPER về chính actor đó.
                // Seed cũ thiếu key này nên Thủ kho quét xong không gửi duyệt được
                // phiếu nhập: POST /api/v1/mini-app/inbound/record (và cả đường
                // fallback POST /api/v1/inbound-documents) đều gác inbound.create
                // nên trả 403 ACCESS_DENIED — chặn đứng Mini App ở bước "Gửi duyệt".
                'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
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
            'name' => 'Nhân viên bảo hành',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inventory.view', 'inventory.trace',
                'warranty.manage', 'warranty.component_issue',
                'scan.classify', 'item_instance.view',
                'warranty.view', 'scan.execute',
            ],
        ],
        'LEADERSHIP' => [
            'name' => 'Ban lãnh đạo',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inventory.view', 'inventory.trace', 'report.view',
                'agency.view', 'defect.view', 'audit_log.view', 'audit_log.view_all',
                'putaway.view', 'warranty.view',
            ],
        ],
        'VIEWER' => [
            'name' => 'Người xem',
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
            'name' => 'Quản lý kho',
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
            'name' => 'Quản lý SKU (Test)',
            'permissions' => ['catalog.view', 'sku.view', 'sku.manage'],
        ],
        'MASTER_MANAGER' => [
            'name' => 'Quản lý dữ liệu gốc (Test)',
            'permissions' => ['catalog.view', 'catalog.manage'],
        ],
        'NO_PERMISSION' => [
            'name' => 'Không có quyền (Test)',
            'permissions' => [],
        ],
        'INBOUND_ONLY_TEST' => [
            'name' => 'Chỉ nhập kho (Test)',
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
        ['username' => 'staff01', 'display_name' => 'Nhân viên kho 01', 'email' => 'staff01@gmail.com', 'role' => 'WAREHOUSE_STAFF', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'approver01', 'display_name' => 'Người duyệt kho 01', 'email' => 'approver01@gmail.com', 'role' => 'WAREHOUSE_APPROVER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'keeper01', 'display_name' => 'Thủ kho 01', 'email' => 'keeper01@gmail.com', 'role' => 'WAREHOUSE_KEEPER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'viewer01', 'display_name' => 'Người xem 01', 'email' => 'viewer01@gmail.com', 'role' => 'VIEWER'],
        ['username' => 'manager01', 'display_name' => 'Quản lý kho 01', 'email' => 'manager01@gmail.com', 'role' => 'WAREHOUSE_MANAGER', 'department' => 'QUAN_LY_KHO'],
        ['username' => 'test-sku-manage', 'display_name' => 'Test Quản lý SKU', 'email' => 'test-sku-manage@gmail.com', 'role' => 'SKU_MANAGER'],
        ['username' => 'test-master-manage', 'display_name' => 'Test Quản lý dữ liệu gốc', 'email' => 'test-master-manage@gmail.com', 'role' => 'MASTER_MANAGER'],
        ['username' => 'test-no-permission', 'display_name' => 'Test Không có quyền', 'email' => 'test-no-permission@gmail.com', 'role' => 'NO_PERMISSION'],
        ['username' => 'test-super-admin', 'display_name' => 'Test Siêu quản trị', 'email' => 'test-super-admin@gmail.com', 'role' => 'SUPER_ADMIN'],
        ['username' => 'inbound-tester01', 'display_name' => 'Test Chỉ nhập kho', 'email' => 'inbound-tester01@gmail.com', 'role' => 'INBOUND_ONLY_TEST'],
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
            ['role_name' => 'Quản trị hệ thống', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $roleModels = ['SUPER_ADMIN' => $superAdminRole];

        $adminUser = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Quản trị viên',
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

        // Nhân sự phủ đủ vai trò / phòng ban / trạng thái cho màn IAM-01: ba bộ
        // lọc của màn (vai trò, phòng ban, trạng thái) trước đây trả rỗng ở phần
        // lớn giá trị. Gọi trong nhánh $withFixtures để dùng chung guard môi
        // trường với các tài khoản demo phía trên.
        $this->call(IamCoverageSeeder::class);

        // Ba tài khoản ĐĂNG NHẬP ĐƯỢC cho MỖI vai trò, tên theo đúng mã vai trò
        // (`warehouse-keeper-01`…). Chạy SAU IamCoverageSeeder vì cần 5 phòng ban
        // mà seeder đó tạo thêm, và vì seeder đó cố ý để một phần tài khoản
        // INACTIVE/LOCKED cho bộ lọc — bộ này bù phần "mở để đăng nhập thử".
        $this->call(RoleAccountMatrixSeeder::class);
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
