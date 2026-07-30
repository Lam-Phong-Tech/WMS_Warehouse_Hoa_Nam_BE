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
    /**
     * Danh mục permission_key hiện đang được route Warehouse/AuditLog tham chiếu qua
     * middleware `permission:<CODE>`. SUPER_ADMIN bypass toàn bộ nên không cần gán ở đây,
     * nhưng các row này bắt buộc phải tồn tại để sau này gán cho role không phải admin.
     */
    private const PERMISSIONS = [
        ['warehouse.view', 'Xem kho'],
        ['warehouse.manage', 'Tạo/sửa kho'],
        ['product.view', 'Xem sản phẩm'],
        ['product.manage', 'Tạo/sửa sản phẩm'],
        ['stock_document.view', 'Xem phiếu kho'],
        ['stock_document.create', 'Tạo phiếu kho'],
        ['stock_document.submit', 'Gửi duyệt phiếu kho'],
        ['stock_document.approve', 'Duyệt/từ chối phiếu kho'],
        ['stock_document.post', 'Hạch toán phiếu kho (post-receipt/post-issue/scan/close-remainder)'],
        ['stock_document.cancel', 'Huỷ phiếu kho'],
        ['stock_document.reverse', 'Đảo ngược (reverse/correction) phiếu kho đã hạch toán'],
        ['safe_stock.view', 'Xem định mức/cảnh báo tồn kho an toàn'],
        ['safe_stock.manage', 'Cấu hình định mức tồn kho an toàn'],
        ['inventory.view', 'Xem tồn kho hiện tại và lịch sử biến động (stock-movements)'],
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
    ];

    /**
     * Role demo theo nhóm quyền (ngoài SUPER_ADMIN) để test middleware `permission:<CODE>`
     * với user không bypass. permission_key liệt kê ở đây phải là tập con của self::PERMISSIONS.
     */
    private const ROLES = [
        'WAREHOUSE_STAFF' => [
            'name' => 'Nhân viên kho',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.create', 'stock_document.submit',
                'safe_stock.view', 'inventory.view', 'report.view',
            ],
        ],
        'WAREHOUSE_APPROVER' => [
            'name' => 'Người duyệt phiếu',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.approve',
                'safe_stock.view', 'inventory.view', 'report.view',
            ],
        ],
        'WAREHOUSE_KEEPER' => [
            'name' => 'Thủ kho (hạch toán)',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.post', 'stock_document.cancel', 'stock_document.reverse',
                'safe_stock.view', 'safe_stock.manage', 'inventory.view', 'report.view',
            ],
        ],
        'VIEWER' => [
            'name' => 'Chỉ xem/báo cáo',
            'permissions' => [
                'warehouse.view', 'product.view', 'stock_document.view',
                'safe_stock.view', 'inventory.view', 'report.view', 'audit_log.view',
            ],
        ],
        'WAREHOUSE_MANAGER' => [
            'name' => 'Quản lý kho',
            'permissions' => [
                'warehouse.view', 'warehouse.manage', 'product.view', 'product.manage',
                'stock_document.view', 'stock_document.create', 'stock_document.submit',
                'stock_document.approve', 'stock_document.post', 'stock_document.cancel', 'stock_document.reverse',
                'safe_stock.view', 'safe_stock.manage', 'inventory.view', 'report.view', 'audit_log.view',
                'stock_request.view', 'stock_request.create', 'stock_request.submit', 'stock_request.approve',
                'transfer.view', 'transfer.create', 'transfer.submit', 'transfer.approve', 'transfer.post',
            ],
        ],
    ];

    /**
     * Tài khoản demo cho từng role, dùng chung mật khẩu baseline để tiện test theo permission.
     */
    private const USERS = [
        ['username' => 'staff01', 'display_name' => 'Nhân viên kho 01', 'email' => 'staff01@wms-hoanam.local', 'role' => 'WAREHOUSE_STAFF'],
        ['username' => 'approver01', 'display_name' => 'Người duyệt 01', 'email' => 'approver01@wms-hoanam.local', 'role' => 'WAREHOUSE_APPROVER'],
        ['username' => 'keeper01', 'display_name' => 'Thủ kho 01', 'email' => 'keeper01@wms-hoanam.local', 'role' => 'WAREHOUSE_KEEPER'],
        ['username' => 'viewer01', 'display_name' => 'Người xem 01', 'email' => 'viewer01@wms-hoanam.local', 'role' => 'VIEWER'],
        ['username' => 'manager01', 'display_name' => 'Quản lý kho 01', 'email' => 'manager01@wms-hoanam.local', 'role' => 'WAREHOUSE_MANAGER'],
    ];

    private const DEFAULT_PASSWORD = 'secret123';

    /**
     * Seed tối thiểu để smoke test base: role SUPER_ADMIN (bypass mọi permission) + admin,
     * cộng thêm 4 role demo theo nhóm quyền + 1 user mỗi role để test permission thật.
     * Không phải seed đầy đủ theo BRD — chỉ đủ để chứng minh Auth chạy được.
     */
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

        $adminUser = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Administrator',
                'email' => 'admin@wms-hoanam.local',
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );
        $this->attachRole($adminUser, $superAdminRole);

        $roleModels = [];
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

        foreach (self::USERS as $u) {
            $user = User::updateOrCreate(
                ['username' => $u['username']],
                [
                    'display_name' => $u['display_name'],
                    'email' => $u['email'],
                    'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
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
}
