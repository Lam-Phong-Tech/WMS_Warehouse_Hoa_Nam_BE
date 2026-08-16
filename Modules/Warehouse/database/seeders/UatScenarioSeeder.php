<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;

class UatScenarioSeeder extends Seeder
{
    /** @var array<string, string> */
    private const UAT_USERS = [
        'uat.inbound' => 'WAREHOUSE_STAFF',
        'uat.approver' => 'WAREHOUSE_APPROVER',
        'uat.keeper' => 'WAREHOUSE_KEEPER',
        'uat.viewer' => 'VIEWER',
    ];

    public function run(): void
    {
        $password = (string) config('warehouse.uat_seed_password');
        if ($password === '') {
            throw new \RuntimeException('UAT seed password is required. Set UAT_SEED_PASSWORD or pass --password.');
        }

        DB::transaction(function () use ($password) {
            $this->seedUsers($password);
            $this->seedImportScenario();
        });
    }

    private function seedUsers(string $password): void
    {
        foreach (self::UAT_USERS as $username => $roleCode) {
            $role = Role::query()->where('role_code', $roleCode)->firstOrFail();
            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'display_name' => 'UAT '.str($roleCode)->replace('_', ' ')->title(),
                    'email' => "{$username}@example.test",
                    'password_hash' => Hash::make($password),
                    'status' => 'ACTIVE',
                    'version' => 1,
                ]
            );

            if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
                $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
            }
        }
    }

    private function seedImportScenario(): void
    {
        $warehouse = Warehouse::query()->where('code', 'KHO-TONG-HN')->firstOrFail();
        $sku = Sku::query()->where('sku_code', 'HN-PV-BASE-001')->firstOrFail();

        $batch = ImportBatch::updateOrCreate(
            ['import_batch_code' => 'UAT-IMP-2026-001'],
            [
                'shipment_container_code' => 'UAT-SHIPMENT-001',
                'supplier_batch_no' => 'UAT-SUPPLIER-001',
                'import_period' => '2026-UAT',
                'supplier_name' => 'UAT Sample Supplier',
                'status' => 'ACTIVE',
            ]
        );

        $code = PhysicalCode::updateOrCreate(
            ['code_value' => 'UAT-QR-ITEM-0001'],
            [
                'code_type' => 'ITEM',
                'carrier_type' => 'QR',
                'status' => 'ACTIVE',
            ]
        );

        ItemInstance::updateOrCreate(
            ['serial_number' => 'UAT-ITEM-0001'],
            [
                'sku_id' => $sku->sku_id,
                'physical_code_id' => $code->physical_code_id,
                'item_status' => 'NEW',
                'item_condition' => 'NORMAL',
                'import_batch_id' => $batch->import_batch_id,
                'version' => 1,
            ]
        );

        InventorySerial::updateOrCreate(
            ['serial_no' => 'UAT-INV-0001'],
            [
                'product_id' => $sku->sku_id,
                'warehouse_id' => $warehouse->id,
                'physical_code_id' => null,
                'condition' => 'GOOD',
                'stock_condition' => 'AVAILABLE',
                'item_condition' => 'NEW',
                'status' => 'IN_STOCK',
            ]
        );
    }
}
