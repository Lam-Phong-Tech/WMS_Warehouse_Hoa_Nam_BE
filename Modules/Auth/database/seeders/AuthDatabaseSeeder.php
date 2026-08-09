<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

class AuthDatabaseSeeder extends Seeder
{
    private const PERMISSIONS = [
        ['warehouse.view', 'Xem kho'],
        ['warehouse.manage', 'Tạo/sửa kho'],
        ['product.view', 'Xem sản phẩm'],
        ['product.manage', 'Tạo/sửa sản phẩm'],
        ['master.manage', 'Quản lý master data'],
        ['stock_document.view', 'Xem phiếu kho'],
        ['stock_document.create', 'Tạo phiếu kho'],
        ['stock_document.submit', 'Gửi duyệt phiếu kho'],
        ['stock_document.approve', 'Duyệt/từ chối phiếu kho'],
        ['stock_document.post', 'Hạch toán phiếu kho (post-receipt/post-issue/close-remainder)'],
        ['stock_document.scan', 'Validate scan trên phiếu kho, không đổi tồn'],
        ['stock_document.cancel', 'Huỷ phiếu kho'],
        ['stock_document.reverse', 'Đảo ngược (reverse/correction) phiếu kho đã hạch toán'],
        ['safe_stock.view', 'Xem định mức/cảnh báo tồn kho an toàn'],
        ['safe_stock.manage', 'Cấu hình định mức tồn kho an toàn'],
        ['inventory.view', 'Xem tồn kho hiện tại và lịch sử biến động (stock-movements)'],
        ['stocktake.adjust', 'Áp dụng điều chỉnh kiểm kê (apply-adjustment)'],
        ['warranty.temp_only', 'Bảo hành nhận tạm (TEMP_ONLY)'],
        ['scan.classify', 'Phân loại/validate mã scan, không đổi tồn'],
        ['report.view', 'Xem báo cáo tồn kho'],
        ['audit_log.view', 'Xem nhật ký audit'],
        ['stock_request.view', 'Xem yêu cầu xuất/nhập/điều chuyển kho'],
        ['stock_request.create', 'Tạo/sửa/xoá/hủy yêu cầu xuất/nhập/điều chuyển kho'],
        ['stock_request.submit', 'Gửi duyệt yêu cầu kho'],
        ['stock_request.approve', 'Duyệt/từ chối/chuyển đổi yêu cầu kho'],
        ['transfer.view', 'Xem phiếu điều chuyển kho'],
        ['transfer.create', 'Tạo/sửa/huỷ phiếu điều chuyển kho'],
        ['transfer.submit', 'Gửi duyệt phiếu điều chuyển kho'],
        ['transfer.approve', 'Duyệt/từ chối phiếu điều chuyển kho'],
        ['transfer.post', 'Xuất/nhận/trả/ghi nhận mất mát hàng điều chuyển kho'],
        ['user.view', 'Xem người dùng'],
        ['user.manage', 'Tạo/sửa/xoá người dùng, gán role'],
        ['role.view', 'Xem role'],
        ['role.manage', 'Tạo/sửa/xoá role, gán permission'],
        ['permission.view', 'Xem danh mục permission'],
        ['agency.view', 'Xem đại lý'],
        ['agency.manage', 'Tạo/sửa/xoá đại lý'],
        ['defect_catalog.view', 'Xem danh mục lỗi/bệnh máy'],
        ['defect_catalog.manage', 'Tạo/sửa/xoá danh mục lỗi/bệnh máy'],
        ['product_master.view', 'Xem danh mục brand/category/model/package/power'],
        ['product_master.manage', 'Tạo/sửa danh mục brand/category/model/package/power'],
        ['physical_code.view', 'Xem mã vật lý (QR/barcode/RFID/NFC...)'],
        ['physical_code.manage', 'Đăng ký/sửa mã vật lý'],
        ['import_batch.view', 'Xem lô nhập khẩu'],
        ['import_batch.manage', 'Tạo/sửa lô nhập khẩu'],
        ['container.view', 'Xem container (carton/box/tray) và item bên trong'],
        ['container.manage', 'Tạo/sửa container, gán/gỡ item khỏi container'],
        ['item_instance.view', 'Xem item instance (máy/linh kiện có serial) và truy vết'],
        ['item_instance.manage', 'Tạo/sửa item instance'],
    ];

    private const ROLES = [
        'WAREHOUSE_STAFF' => [
            'name' => 'Nhân viên kho',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.create', 'stock_document.submit',
                'safe_stock.view', 'inventory.view', 'report.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_APPROVER' => [
            'name' => 'Người duyệt phiếu',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.approve',
                'safe_stock.view', 'inventory.view', 'report.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_KEEPER' => [
            'name' => 'Thủ kho (hạch toán)',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.post', 'stock_document.scan', 'stock_document.cancel', 'stock_document.reverse',
                'safe_stock.view', 'safe_stock.manage', 'inventory.view', 'report.view',
                'product_master.view', 'physical_code.view', 'physical_code.manage', 'import_batch.view',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
            ],
        ],
        'VIEWER' => [
            'name' => 'Chỉ xem/báo cáo',
            'permissions' => [
                'warehouse.view', 'product.view', 'stock_document.view',
                'safe_stock.view', 'inventory.view', 'report.view', 'audit_log.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_MANAGER' => [
            'name' => 'Quản lý kho',
            'permissions' => [
                'warehouse.view', 'warehouse.manage', 'product.view', 'product.manage',
                'stock_document.view', 'stock_document.create', 'stock_document.submit',
                'stock_document.approve', 'stock_document.post', 'stock_document.scan', 'stock_document.cancel', 'stock_document.reverse',
                'safe_stock.view', 'safe_stock.manage', 'inventory.view', 'report.view', 'audit_log.view',
                'stock_request.view', 'stock_request.create', 'stock_request.submit', 'stock_request.approve',
                'transfer.view', 'transfer.create', 'transfer.submit', 'transfer.approve', 'transfer.post',
                'stocktake.adjust', 'warranty.temp_only', 'scan.classify',
                'user.view', 'user.manage', 'role.view', 'role.manage', 'permission.view',
                'agency.view', 'agency.manage', 'defect_catalog.view', 'defect_catalog.manage',
                'product_master.view', 'product_master.manage', 'physical_code.view', 'physical_code.manage',
                'import_batch.view', 'import_batch.manage', 'container.view', 'container.manage',
                'item_instance.view', 'item_instance.manage',
            ],
        ],
        'SKU_MANAGER' => [
            'name' => 'Quản lý SKU (test)',
            'permissions' => ['product.view', 'product.manage'],
        ],
        'MASTER_MANAGER' => [
            'name' => 'Quản lý Master Data (test)',
            'permissions' => ['master.manage'],
        ],
        'NO_PERMISSION' => [
            'name' => 'Không có quyền (test)',
            'permissions' => [],
        ],
    ];

    private const USERS = [
        ['username' => 'staff01', 'display_name' => 'Nhân viên kho 01', 'email' => 'staff01@gmail.com', 'role' => 'WAREHOUSE_STAFF'],
        ['username' => 'approver01', 'display_name' => 'Người duyệt 01', 'email' => 'approver01@gmail.com', 'role' => 'WAREHOUSE_APPROVER'],
        ['username' => 'keeper01', 'display_name' => 'Thủ kho 01', 'email' => 'keeper01@gmail.com', 'role' => 'WAREHOUSE_KEEPER'],
        ['username' => 'viewer01', 'display_name' => 'Người xem 01', 'email' => 'viewer01@gmail.com', 'role' => 'VIEWER'],
        ['username' => 'manager01', 'display_name' => 'Quản lý kho 01', 'email' => 'manager01@gmail.com', 'role' => 'WAREHOUSE_MANAGER'],
        ['username' => 'test-sku-manage', 'display_name' => 'Test SKU Manage', 'email' => 'test-sku-manage@hoanam.com', 'role' => 'SKU_MANAGER'],
        ['username' => 'test-master-manage', 'display_name' => 'Test Master Manage', 'email' => 'test-master-manage@hoanam.com', 'role' => 'MASTER_MANAGER'],
        ['username' => 'test-no-permission', 'display_name' => 'Test No Permission', 'email' => 'test-no-permission@hoanam.com', 'role' => 'NO_PERMISSION'],
        ['username' => 'test-super-admin', 'display_name' => 'Test Super Admin', 'email' => 'test-super-admin@hoanam.com', 'role' => 'SUPER_ADMIN'],
    ];

    private const DEFAULT_PASSWORD = 'Cntt@1234';

    public function run(): void
    {
        $permissionsByKey = [];
        foreach (self::PERMISSIONS as [$key, $name]) {
            $permissionsByKey[$key] = Permission::firstOrCreate(
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
                'display_name' => 'Administrator',
                'email' => 'admin@gmail.com',
                'password_hash' => Hash::make($this->defaultPassword()),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );
        $this->attachRole($adminUser, $superAdminRole);

        foreach (self::ROLES as $code => $def) {
            $role = Role::firstOrCreate(
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

        // Alias rows must be seeded only after canonical role assignments exist so
        // compatibility permissions inherit exactly the same roles.
        $this->call(WmsPermissionCompatibilitySeeder::class);

        foreach (self::USERS as $u) {
            $user = User::updateOrCreate(
                ['username' => $u['username']],
                [
                    'display_name' => $u['display_name'],
                    'email' => $u['email'],
                    'password_hash' => Hash::make($this->defaultPassword()),
                    'status' => 'ACTIVE',
                    'version' => 1,
                ]
            );
            $this->attachRole($user, $roleModels[$u['role']]);
        }
    }

    private function attachRole(User $user, Role $role): void
    {
        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }
    }

    private function defaultPassword(): string
    {
        $ciPassword = env('CI_DEMO_PASSWORD');
        return is_string($ciPassword) && $ciPassword !== '' ? $ciPassword : self::DEFAULT_PASSWORD;
    }
}
