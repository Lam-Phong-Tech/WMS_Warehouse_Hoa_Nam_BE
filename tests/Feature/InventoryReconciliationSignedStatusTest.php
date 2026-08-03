<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryReconciliationSignedStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_sum_and_stock_status_dimensions_reconcile_independently(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = Warehouse::query()->create([
            'code' => 'KHO-REC-SIGNED',
            'name' => 'Kho đối soát signed sum',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'code' => 'SP-REC-SIGNED',
            'name' => 'Sản phẩm đối soát signed sum',
            'status' => 'ACTIVE',
        ]);

        InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 7,
            'available_qty' => 7,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'DAMAGED',
            'on_hand_qty' => 2,
            'available_qty' => 0,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        $this->movement($user, $warehouse, $product, 'AVAILABLE', '10.000', '0.000', '10.000', StockMovement::RECEIPT_POSTED);
        $this->movement($user, $warehouse, $product, 'AVAILABLE', '-3.000', '10.000', '7.000', StockMovement::ISSUE_POSTED);
        $this->movement($user, $warehouse, $product, 'DAMAGED', '2.000', '0.000', '2.000', StockMovement::RECEIPT_POSTED);

        $response = $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$product->id}",
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertOk()
            ->assertJsonPath('data.formula', 'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)')
            ->assertJsonPath('data.summary.rows_checked', 2)
            ->assertJsonPath('data.summary.mismatch_count', 0)
            ->assertJsonPath('data.summary.status', 'PASS');

        $rows = collect($response->json('data.rows'))->keyBy('stock_status');
        $this->assertSame(7.0, (float) $rows['AVAILABLE']['expected_qty']);
        $this->assertSame(7.0, (float) $rows['AVAILABLE']['current_on_hand_qty']);
        $this->assertSame(0.0, (float) $rows['AVAILABLE']['delta_qty']);
        $this->assertSame(2.0, (float) $rows['DAMAGED']['expected_qty']);
        $this->assertSame(2.0, (float) $rows['DAMAGED']['current_on_hand_qty']);
        $this->assertSame(0.0, (float) $rows['DAMAGED']['delta_qty']);
    }

    private function movement(
        User $user,
        Warehouse $warehouse,
        Product $product,
        string $stockStatus,
        string $delta,
        string $before,
        string $after,
        string $type
    ): void {
        StockMovement::query()->create([
            'movement_no' => 'MV-REC-'.strtoupper(Str::random(10)),
            'business_key' => hash('sha256', (string) Str::uuid()),
            'movement_type' => $type,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => $stockStatus,
            'qty_before' => $before,
            'qty_delta' => $delta,
            'qty_after' => $after,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function createSuperAdmin(): array
    {
        $role = Role::query()->create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::query()->create([
            'username' => 'signed-reconciliation-admin',
            'display_name' => 'Signed Reconciliation Admin',
            'email' => 'signed-reconciliation-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }
}
