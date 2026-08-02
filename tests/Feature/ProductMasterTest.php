<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ProductMaster;
use Tests\TestCase;

class ProductMasterTest extends TestCase
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

    public function test_product_master_can_be_created_listed_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/product-masters', [
            'master_type' => 'BRAND',
            'code' => 'BR-BOSCH',
            'name' => 'Bosch',
        ], $headers);

        $create->assertCreated()->assertJsonPath('data.code', 'BR-BOSCH');
        $id = $create->json('data.master_id');

        $this->getJson('/api/v1/product-masters?master_type=BRAND', $headers)
            ->assertOk()->assertJsonPath('data.0.code', 'BR-BOSCH');

        $update = $this->patchJson("/api/v1/product-masters/{$id}", ['name' => 'Bosch VN'], $headers);
        $update->assertOk()->assertJsonPath('data.name', 'Bosch VN');
    }

    public function test_code_must_be_unique_within_same_master_type_but_can_repeat_across_types(): void
    {
        $headers = $this->authHeaders();
        ProductMaster::create(['master_type' => 'BRAND', 'code' => 'DUP', 'name' => 'Dup Brand', 'status' => 'ACTIVE']);

        $sameType = $this->postJson('/api/v1/product-masters', ['master_type' => 'BRAND', 'code' => 'DUP', 'name' => 'Trung ma'], $headers);
        $sameType->assertStatus(422);

        $otherType = $this->postJson('/api/v1/product-masters', ['master_type' => 'CATEGORY', 'code' => 'DUP', 'name' => 'Danh muc khac loai'], $headers);
        $otherType->assertCreated();
    }

    public function test_product_master_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/product-masters', [
            'master_type' => 'CATEGORY', 'code' => 'CAT-01', 'name' => 'May khoan',
        ], $headers);

        $id = $create->json('data.master_id');
        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'product_master',
            'object_id' => $id,
            'action' => 'CREATE',
        ]);
    }
}
