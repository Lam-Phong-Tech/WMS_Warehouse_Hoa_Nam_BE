<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
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
}
