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
        ['warehouse.view', 'View warehouses'],
        ['warehouse.manage', 'Manage warehouses'],
        ['product.view', 'View SKUs/products'],
        ['product.manage', 'Manage SKUs/products'],
        ['master.manage', 'Manage master data'],
        ['catalog.manage', 'Manage canonical catalog masters'],
        ['stock_document.view', 'View stock documents'],
        ['stock_document.create', 'Create stock documents'],
        ['stock_document.post', 'Post full stock documents'],
        ['stock_document.scan', 'Scan stock documents'],
        ['stock_document.cancel', 'Cancel stock documents'],
        ['stock_document.reverse', 'Reverse posted stock documents'],
        ['inventory.view', 'View inventory balances and movements'],
        ['inventory.trace', 'Trace inventory movements'],
        ['warranty.temp_only', 'Create temporary warranty intake'],
        ['warranty.manage', 'Manage warranty cases and events'],
        ['warranty.component_issue', 'Issue components for active warranty cases'],
        ['warranty.pii.view', 'View warranty PII'],
        ['warranty.pii.export', 'Export warranty PII'],
        ['scan.classify', 'Classify scans without stock effect'],
        ['report.view', 'View reports'],
        ['report.export', 'Export reports'],
        ['outbound.import', 'Import outbound requests from Excel'],
        ['label.print', 'Print labels'],
        ['label.reprint', 'Reprint labels'],
        ['pv.publish', 'Publish PV catalog'],
        ['audit_log.view', 'View audit logs'],
        ['user.view', 'View users'],
        ['user.manage', 'Manage users and roles'],
        ['role.view', 'View roles'],
        ['role.manage', 'Manage roles and permissions'],
        ['permission.view', 'View permissions'],
        ['agency.view', 'View agencies'],
        ['agency.manage', 'Manage agencies'],
        ['defect_catalog.view', 'View defect catalog'],
        ['defect_catalog.manage', 'Manage defect catalog'],
        ['product_master.view', 'View brand/category/model/package/power masters'],
        ['product_master.manage', 'Manage brand/category/model/package/power masters'],
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
        'WAREHOUSE_STAFF' => [
            'name' => 'Warehouse Staff',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.create', 'stock_document.scan',
                'inventory.view', 'report.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_APPROVER' => [
            'name' => 'Warehouse Approver',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view',
                'inventory.view', 'report.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_KEEPER' => [
            'name' => 'Warehouse Keeper',
            'permissions' => [
                'warehouse.view', 'product.view',
                'stock_document.view', 'stock_document.post', 'stock_document.scan',
                'stock_document.cancel', 'stock_document.reverse',
                'inventory.view', 'inventory.trace', 'report.view', 'outbound.import',
                'label.print', 'label.reprint', 'warranty.manage', 'warranty.component_issue',
                'product_master.view', 'physical_code.view', 'physical_code.manage', 'import_batch.view',
                'container.view', 'container.manage', 'item_instance.view', 'item_instance.manage',
            ],
        ],
        'VIEWER' => [
            'name' => 'Viewer',
            'permissions' => [
                'warehouse.view', 'product.view', 'stock_document.view',
                'inventory.view', 'report.view', 'audit_log.view',
                'product_master.view', 'physical_code.view', 'import_batch.view',
                'container.view', 'item_instance.view',
            ],
        ],
        'WAREHOUSE_MANAGER' => [
            'name' => 'Warehouse Manager',
            'permissions' => [
                'warehouse.view', 'warehouse.manage', 'product.view', 'product.manage',
                'stock_document.view', 'stock_document.create', 'stock_document.post',
                'stock_document.scan', 'stock_document.cancel', 'stock_document.reverse',
                'catalog.manage', 'inventory.view', 'inventory.trace', 'report.view', 'report.export',
                'outbound.import', 'label.print', 'label.reprint', 'pv.publish', 'audit_log.view',
                'warranty.temp_only', 'warranty.manage', 'warranty.component_issue',
                'warranty.pii.view', 'warranty.pii.export', 'scan.classify',
                'user.view', 'user.manage', 'role.view', 'role.manage', 'permission.view',
                'agency.view', 'agency.manage', 'defect_catalog.view', 'defect_catalog.manage',
                'product_master.view', 'product_master.manage', 'physical_code.view', 'physical_code.manage',
                'import_batch.view', 'import_batch.manage', 'container.view', 'container.manage',
                'item_instance.view', 'item_instance.manage',
            ],
        ],
        'SKU_MANAGER' => [
            'name' => 'SKU Manager Test',
            'permissions' => ['product.view', 'product.manage'],
        ],
        'MASTER_MANAGER' => [
            'name' => 'Master Data Manager Test',
            'permissions' => ['master.manage', 'catalog.manage'],
        ],
        'NO_PERMISSION' => [
            'name' => 'No Permission Test',
            'permissions' => [],
        ],
    ];

    private const USERS = [
        ['username' => 'staff01', 'display_name' => 'Warehouse Staff 01', 'email' => 'staff01@gmail.com', 'role' => 'WAREHOUSE_STAFF'],
        ['username' => 'approver01', 'display_name' => 'Warehouse Approver 01', 'email' => 'approver01@gmail.com', 'role' => 'WAREHOUSE_APPROVER'],
        ['username' => 'keeper01', 'display_name' => 'Warehouse Keeper 01', 'email' => 'keeper01@gmail.com', 'role' => 'WAREHOUSE_KEEPER'],
        ['username' => 'viewer01', 'display_name' => 'Viewer 01', 'email' => 'viewer01@gmail.com', 'role' => 'VIEWER'],
        ['username' => 'manager01', 'display_name' => 'Warehouse Manager 01', 'email' => 'manager01@gmail.com', 'role' => 'WAREHOUSE_MANAGER'],
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
            ['role_name' => 'System Administrator', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
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
