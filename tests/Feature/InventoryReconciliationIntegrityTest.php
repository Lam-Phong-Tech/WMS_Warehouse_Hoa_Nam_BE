<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryReconciliationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_detects_ledger_dimension_without_balance(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = $this->createWarehouse('KHO-REC-01');
        $product = $this->createProduct('SP-REC-01');

        StockMovement::create([
            'movement_no' => 'MV-REC-MISSING-BALANCE',
            'business_key' => 'test:reconcile:missing-balance',
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => 7,
            'qty_after' => 7,
            // ERD bảng 30: quantity NOT NULL, luôn dương.
            'quantity' => 7,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$product->id}&stock_status=AVAILABLE",
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertOk()
            ->assertJsonPath('data.summary.rows_checked', 1)
            ->assertJsonPath('data.summary.mismatch_count', 1)
            ->assertJsonPath('data.summary.status', 'FAIL')
            ->assertJsonPath('data.mismatches.0.balance_exists', false)
            ->assertJsonPath('data.mismatches.0.current_on_hand_qty', 0)
            ->assertJsonPath('data.mismatches.0.expected_qty', 7)
            ->assertJsonPath('data.mismatches.0.mismatch_reason', 'BALANCE_MISSING_FOR_LEDGER');
    }

    public function test_reconciliation_detects_balance_dimension_without_ledger(): void
    {
        [, $token] = $this->createSuperAdmin();
        $warehouse = $this->createWarehouse('KHO-REC-02');
        $product = $this->createProduct('SP-REC-02');

        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 9,
            'available_qty' => 9,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$product->id}&stock_status=AVAILABLE",
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertOk()
            ->assertJsonPath('data.summary.rows_checked', 1)
            ->assertJsonPath('data.summary.mismatch_count', 1)
            ->assertJsonPath('data.mismatches.0.balance_exists', true)
            ->assertJsonPath('data.mismatches.0.current_on_hand_qty', 9)
            ->assertJsonPath('data.mismatches.0.expected_qty', 0)
            ->assertJsonPath('data.mismatches.0.mismatch_reason', 'LEDGER_MISSING_FOR_BALANCE');
    }

    private function createSuperAdmin(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'reconciliation-admin',
            'display_name' => 'Reconciliation Admin',
            'email' => 'reconciliation-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createWarehouse(string $code): Warehouse
    {
        // REQ-SRS-001: tái sử dụng warehouse ACTIVE duy nhất.
        return $this->ensureActiveWarehouse($code);
    }

    private function createProduct(string $code): Sku
    {
        return Sku::create([
            'code' => $code,
            'name' => $code,
            // ERD bảng 16: sku_type NOT NULL, không default — fixture phải khai báo tường minh.
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }
}
