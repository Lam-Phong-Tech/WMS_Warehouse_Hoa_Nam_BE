<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

class AuthDatabaseSeeder extends Seeder
{
    private const PERMISSIONS = [
        'master-data.warehouse.view' => ['Warehouse view', 'WAREHOUSE'],
        'master-data.warehouse.create' => ['Warehouse create', 'WAREHOUSE'],
        'master-data.product.view' => ['Product view', 'FUNCTION'],
        'master-data.product.create' => ['Product create', 'FUNCTION'],
        'warehouse.stock-document.view' => ['Stock document view', 'WAREHOUSE'],
        'warehouse.stock-document.create' => ['Stock document create', 'WAREHOUSE'],
        'warehouse.stock-document.submit' => ['Stock document submit', 'WAREHOUSE'],
        'warehouse.stock-document.approve' => ['Stock document approve/reject', 'WAREHOUSE'],
        'warehouse.stock-document.post' => ['Stock document post/dispatch/receive', 'WAREHOUSE'],
        'warehouse.stock-document.cancel' => ['Stock document cancel', 'WAREHOUSE'],
        'warehouse.stock-request.view' => ['Stock request view', 'WAREHOUSE'],
        'warehouse.stock-request.create' => ['Stock request create', 'WAREHOUSE'],
        'warehouse.stock-request.submit' => ['Stock request submit', 'WAREHOUSE'],
        'warehouse.stock-request.approve' => ['Stock request approve/reject', 'WAREHOUSE'],
        'warehouse.stock-request.cancel' => ['Stock request cancel', 'WAREHOUSE'],
        'warehouse.stock-request.convert' => ['Stock request convert', 'WAREHOUSE'],
        'warehouse.safe-stock.view' => ['Safe stock view', 'WAREHOUSE'],
        'warehouse.safe-stock.manage' => ['Safe stock manage', 'WAREHOUSE'],
        'report.inventory.view' => ['Inventory report view', 'WAREHOUSE'],
        'audit-log.view' => ['Audit log view', 'SYSTEM'],
    ];

    private const ROLE_PERMISSIONS = [
        'WAREHOUSE_OPERATOR' => [
            'master-data.warehouse.view',
            'master-data.product.view',
            'warehouse.stock-document.view',
            'warehouse.stock-document.create',
            'warehouse.stock-document.submit',
            'warehouse.stock-document.post',
            'warehouse.stock-request.view',
            'warehouse.stock-request.create',
            'warehouse.stock-request.submit',
            'warehouse.safe-stock.view',
        ],
        'WAREHOUSE_MANAGER' => [
            'master-data.warehouse.view',
            'master-data.warehouse.create',
            'master-data.product.view',
            'master-data.product.create',
            'warehouse.stock-document.view',
            'warehouse.stock-document.create',
            'warehouse.stock-document.submit',
            'warehouse.stock-document.approve',
            'warehouse.stock-document.post',
            'warehouse.stock-document.cancel',
            'warehouse.stock-request.view',
            'warehouse.stock-request.create',
            'warehouse.stock-request.submit',
            'warehouse.stock-request.approve',
            'warehouse.stock-request.cancel',
            'warehouse.stock-request.convert',
            'warehouse.safe-stock.view',
            'warehouse.safe-stock.manage',
            'report.inventory.view',
        ],
        'AUDITOR' => [
            'master-data.warehouse.view',
            'master-data.product.view',
            'warehouse.stock-document.view',
            'warehouse.stock-request.view',
            'warehouse.safe-stock.view',
            'report.inventory.view',
            'audit-log.view',
        ],
    ];

    public function run(): void
    {
        $permissions = $this->seedPermissions();

        $superAdmin = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'System administrator', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );

        foreach (self::ROLE_PERMISSIONS as $roleCode => $permissionKeys) {
            $role = Role::firstOrCreate(
                ['role_code' => $roleCode],
                [
                    'role_name' => str_replace('_', ' ', Str::headline(strtolower($roleCode))),
                    'scope_type' => $roleCode === 'AUDITOR' ? 'SYSTEM' : 'WAREHOUSE',
                    'status' => 'ACTIVE',
                ]
            );

            $this->syncRolePermissions($role, $permissionKeys, $permissions);
        }

        $this->syncRolePermissions($superAdmin, array_keys(self::PERMISSIONS), $permissions);

        $user = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Administrator',
                'email' => 'admin@wms-hoanam.local',
                'password_hash' => Hash::make('ChangeMe123!'),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );

        if (! $user->roles()->where('role.role_id', $superAdmin->role_id)->exists()) {
            $user->roles()->attach($superAdmin->role_id, ['user_role_id' => (string) Str::uuid()]);
        }
    }

    /**
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $permissions = [];

        foreach (self::PERMISSIONS as $key => [$name, $scopeType]) {
            $permissions[$key] = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $name, 'scope_type' => $scopeType, 'status' => 'ACTIVE']
            );
        }

        return $permissions;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     * @param  array<string, Permission>  $permissions
     */
    private function syncRolePermissions(Role $role, array $permissionKeys, array $permissions): void
    {
        foreach ($permissionKeys as $permissionKey) {
            $permission = $permissions[$permissionKey];

            DB::table('role_permission')->updateOrInsert(
                [
                    'role_id' => $role->role_id,
                    'permission_id' => $permission->permission_id,
                ],
                [
                    'role_permission_id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
