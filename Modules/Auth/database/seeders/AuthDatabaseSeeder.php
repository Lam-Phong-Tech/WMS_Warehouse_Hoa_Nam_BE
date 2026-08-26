<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
        ['outbound.create', 'Create and edit outbound drafts'],
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

        ['product.view', 'Legacy alias: view SKUs/products'],
        ['product.manage', 'Legacy alias: manage SKUs/products'],
        ['master.manage', 'Legacy alias: manage master data'],
        ['stock_document.view', 'Legacy alias: view stock documents'],
        ['stock_document.create', 'Legacy alias: create stock documents'],
        ['stock_document.post', 'Legacy alias: post stock documents'],
        ['stock_document.scan', 'Legacy alias: scan stock documents'],
        ['stock_document.cancel', 'Legacy alias: cancel stock documents'],
        ['stock_document.reverse', 'Legacy alias: reverse stock documents'],
        ['warranty.temp_only', 'Legacy warranty temporary intake permission'],
        ['user.view', 'Legacy IAM user view'],
        ['user.manage', 'Legacy IAM user manage'],
        ['role.view', 'Legacy IAM role view'],
        ['role.manage', 'Legacy IAM role manage'],
        ['permission.view', 'Legacy IAM permission view'],
        ['defect_catalog.view', 'Legacy defect catalog view'],
        ['defect_catalog.manage', 'Legacy defect catalog manage'],
        ['product_master.view', 'Legacy catalog master view'],
        ['product_master.manage', 'Legacy catalog master manage'],
        ['physical_code.view', 'View physical codes'],
        ['physical_code.manage', 'Manage physical codes'],
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
                'outbound.view', 'outbound.import', 'outbound.create', 'outbound.scan', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint',
                'warranty.manage', 'warranty.component_issue', 'warranty.pii.view', 'warranty.pii.export',
                'scan.classify', 'pv.publish', 'report.view', 'report.export', 'audit_log.view',
                'physical_code.view', 'physical_code.manage', 'import_batch.view', 'import_batch.manage',
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
                'outbound.view', 'outbound.create', 'outbound.scan',
                'inventory.view', 'report.view',
                'agency.view', 'defect.view',
                'physical_code.view', 'import_batch.view', 'container.view', 'item_instance.view',
                'product.view', 'stock_document.view', 'stock_document.create', 'stock_document.scan',
                'product_master.view',
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
                'physical_code.view', 'import_batch.view', 'container.view', 'item_instance.view',
                'product.view', 'stock_document.view',
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
                'physical_code.view', 'physical_code.manage', 'import_batch.view',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
                'product.view', 'stock_document.view', 'stock_document.post', 'stock_document.scan',
                'stock_document.cancel', 'stock_document.reverse', 'product_master.view',
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
                'scan.classify', 'physical_code.view', 'item_instance.view',
                'warranty.view', 'scan.execute',
            ],
        ],
        'LEADERSHIP' => [
            'name' => 'Leadership',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inventory.view', 'inventory.trace', 'report.view',
                'agency.view', 'defect.view', 'audit_log.view',
                'putaway.view', 'warranty.view',
            ],
        ],
        'VIEWER' => [
            'name' => 'Viewer',
            'permissions' => [
                'warehouse.view', 'catalog.view', 'sku.view',
                'inbound.view', 'outbound.view', 'inventory.view', 'report.view', 'audit_log.view',
                'agency.view', 'defect.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
                'product.view', 'stock_document.view', 'product_master.view',
            ],
        ],
        'WAREHOUSE_MANAGER' => [
            'name' => 'Warehouse Manager',
            'permissions' => [
                'iam.user.view', 'iam.user.manage', 'iam.role.view', 'iam.role.manage', 'iam.permission.view',
                'warehouse.view', 'warehouse.manage', 'catalog.view', 'catalog.manage', 'sku.view', 'sku.manage',
                'agency.view', 'agency.manage', 'defect.view', 'defect.manage',
                'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inbound.cancel',
                'outbound.view', 'outbound.import', 'outbound.create', 'outbound.scan', 'outbound.post', 'outbound.cancel',
                'inventory.view', 'inventory.trace', 'inventory.reversal.post',
                'physical_code.assign_rfid', 'physical_code.create', 'label.print', 'label.reprint', 'pv.publish',
                'pv.master.manage',
                'warranty.manage', 'warranty.component_issue', 'warranty.pii.view', 'warranty.pii.export',
                'scan.classify', 'report.view', 'report.export', 'audit_log.view',
                'physical_code.view', 'physical_code.manage', 'import_batch.view', 'import_batch.manage',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
                'product.view', 'product.manage', 'master.manage',
                'stock_document.view', 'stock_document.create', 'stock_document.post',
                'stock_document.scan', 'stock_document.cancel', 'stock_document.reverse',
                'warranty.temp_only', 'user.view', 'user.manage', 'role.view', 'role.manage', 'permission.view',
                'defect_catalog.view', 'defect_catalog.manage', 'product_master.view', 'product_master.manage',
            ],
        ],
        'SKU_MANAGER' => [
            'name' => 'SKU Manager Test',
            'permissions' => ['catalog.view', 'sku.view', 'sku.manage', 'product.view', 'product.manage'],
        ],
        'MASTER_MANAGER' => [
            'name' => 'Master Data Manager Test',
            'permissions' => ['catalog.view', 'catalog.manage', 'master.manage', 'product_master.view', 'product_master.manage'],
        ],
        'NO_PERMISSION' => [
            'name' => 'No Permission Test',
            'permissions' => [],
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
    ];

    private const NON_PRODUCTION_ROLES = ['SKU_MANAGER', 'MASTER_MANAGER', 'NO_PERMISSION'];

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
