<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class SkuManagementTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_sku_update_sets_brand_category_model_and_package_variant(): void
    {
        $headers = $this->authHeaders();
        $brand = ProductMaster::create(['master_type' => 'BRAND', 'code' => 'BR-1', 'name' => 'Brand 1', 'status' => 'ACTIVE']);
        $sku = Sku::create(['sku_code' => 'SKU-UPD', 'sku_name' => 'SKU update', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => 'ACTIVE']);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'brand_id' => $brand->master_id,
            'package_variant' => 'KIT',
            'specification' => ['power' => '750W'],
        ], $headers);

        $response->assertOk()
            ->assertJsonPath('data.brand_id', $brand->master_id)
            ->assertJsonPath('data.package_variant', 'KIT')
            ->assertJsonPath('data.specification.power', '750W');
    }

    public function test_sku_update_writes_audit_log(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-AUDIT', 'sku_name' => 'SKU audit', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => 'ACTIVE']);

        $this->patchJson("/api/v1/products/{$sku->sku_id}", ['sku_name' => 'SKU audit v2'], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'sku',
            'object_id' => $sku->sku_id,
            'action' => 'UPDATE',
        ]);
    }

    private function seedPostedMovement(Sku $sku): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-BR10', 'name' => 'Kho BR-SKU-10', 'status' => 'ACTIVE']);
        $actor = User::create([
            'username' => 'ledger-'.Str::random(6),
            'display_name' => 'Ledger actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        StockMovement::create([
            'movement_no' => 'MOV-'.Str::random(10),
            'business_key' => 'BR-SKU-10-'.Str::random(10),
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->sku_id,
            'qty_before' => 0,
            'qty_delta' => 5,
            'qty_after' => 5,
            // ERD bảng 30: quantity/stock_status/occurred_at NOT NULL.
            'quantity' => 5,
            'stock_status' => 'AVAILABLE',
            'occurred_at' => now(),
            'created_by' => $actor->user_id,
        ]);
    }

    public function test_sku_code_is_locked_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-CODE', 'sku_name' => 'SKU locked code', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'sku_code' => 'SKU-LOCK-CODE-RENAMED',
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK');
        $this->assertSame('SKU-LOCK-CODE', $sku->fresh()->sku_code);
    }

    public function test_is_serial_tracked_is_locked_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-TRACK', 'sku_name' => 'SKU locked tracking', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'is_serial_tracked' => true,
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK');
        $this->assertFalse((bool) $sku->fresh()->is_serial_tracked);
    }

    public function test_sku_update_of_unrelated_fields_still_succeeds_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-OTHER', 'sku_name' => 'SKU other', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'sku_name' => 'SKU other v2',
            'sku_code' => 'SKU-LOCK-OTHER',
            'is_serial_tracked' => false,
        ], $headers);

        $response->assertOk()->assertJsonPath('data.sku_name', 'SKU other v2');
    }
}
