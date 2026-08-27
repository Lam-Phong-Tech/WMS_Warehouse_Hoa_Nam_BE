<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;

/**
 * Compatibility permission catalog. API middleware stays on canonical
 * permissions; FE aliases are additive only. `sku.manage` is retained as a
 * legacy API contract permission for the dedicated /skus aggregate endpoints.
 *
 * LƯU Ý (audit phân quyền 27/08): trong 8 mã ở ALIASES, ba mã `scan.inbound`,
 * `scan.outbound`, `scan.warranty` KHÔNG có cổng chặn nào đọc tới — không route
 * nào khai `permission:scan.inbound|...`, và FE cũng không gate bằng chúng
 * (WMS_ACTION_PERMISSIONS.SCAN_INBOUND trỏ về `inbound.scan`). Chúng được giữ
 * ACTIVE vì hợp đồng WWHN-180 ghim, nên bắt buộc phải khai trong
 * PermissionEnforcementMap::DEPRECATED để `GET /permissions` trả
 * `is_enforced=false` + `replaced_by`, và ma trận IAM-01 hiển thị "Ngừng hiệu lực"
 * thay vì bày ra như một quyền dùng được.
 *
 * 21 mã cũ còn lại (user.*, role.*, product.*, stock_document.*, ...) đã bị thu
 * hồi hẳn — xem migration 2026_08_27_170000_retire_unenforced_legacy_permissions.
 * KHÔNG thêm alias mới ở đây nếu không có route nào đọc tới:
 * AuthorizationCoverageInvariantTest sẽ đỏ.
 */
class WmsPermissionCompatibilitySeeder extends Seeder
{
    private const ALIASES = [
        'inbound.post' => 'FE alias cho stock_document.post ở Post Receipt',
        'outbound.post' => 'FE alias cho stock_document.post ở Post Issue',
        'scan.inbound' => 'FE alias cho stock_document.scan trong luồng nhập kho',
        'scan.outbound' => 'FE alias cho stock_document.scan trong luồng xuất kho',
        'scan.warranty' => 'FE action guard cho scan trong ngữ cảnh bảo hành',
        'inventory.trace' => 'FE route-entry guard cho màn Scan/Trace',
        'warranty.manage' => 'FE route-entry guard cho màn bảo hành',
        'report.export' => 'FE action guard cho xuất báo cáo',
    ];

    private const ROLE_ASSIGNMENTS = [
        'WAREHOUSE_KEEPER' => [
            'inbound.post', 'outbound.post', 'scan.inbound', 'scan.outbound', 'inventory.trace',
        ],
        'WAREHOUSE_MANAGER' => [
            'inbound.post', 'outbound.post', 'scan.inbound', 'scan.outbound', 'scan.warranty',
            'inventory.trace', 'warranty.manage', 'report.export',
        ],
    ];

    private const SKU_MANAGE_ROLES = ['SKU_MANAGER', 'WAREHOUSE_MANAGER'];

    public function run(): void
    {
        $permissions = [];
        foreach (self::ALIASES as $key => $name) {
            $permissions[$key] = Permission::updateOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $name, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
        }

        foreach (self::ROLE_ASSIGNMENTS as $roleCode => $permissionKeys) {
            $role = Role::query()->where('role_code', $roleCode)->firstOrFail();
            foreach ($permissionKeys as $permissionKey) {
                $this->attach($role, $permissions[$permissionKey]);
            }
        }

        $skuManage = Permission::updateOrCreate(
            ['permission_key' => 'sku.manage'],
            [
                'permission_name' => 'Quản lý SKU aggregate compatibility contract',
                'scope_type' => 'FUNCTION',
                'status' => 'ACTIVE',
            ]
        );
        foreach (self::SKU_MANAGE_ROLES as $roleCode) {
            // SKU_MANAGER là role FIXTURE của bộ test RBAC: từ WWHN-300/DEV300-P1-01
            // nó không còn được seed ngoài local/testing. Alias chỉ gắn cho role
            // thực sự tồn tại — vắng fixture không phải lỗi seeding.
            $role = Role::query()->where('role_code', $roleCode)->first();

            if ($role === null) {
                continue;
            }

            $this->attach($role, $skuManage);
        }
    }

    private function attach(Role $role, Permission $permission): void
    {
        $exists = DB::table('role_permission')
            ->where('role_id', $role->role_id)
            ->where('permission_id', $permission->permission_id)
            ->exists();

        if (! $exists) {
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
            ]);
        }
    }
}
