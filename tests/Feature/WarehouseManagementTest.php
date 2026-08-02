<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
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

    public function test_warehouse_list_returns_seeded_kho_tong(): void
    {
        $headers = $this->authHeaders();
        Warehouse::create(['code' => 'WH-MAIN', 'name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']);

        $response = $this->getJson('/api/v1/warehouses', $headers);

        $response->assertOk()->assertJsonPath('data.0.code', 'WH-MAIN');
    }

    public function test_warehouse_detail_for_unknown_id_returns_404(): void
    {
        $headers = $this->authHeaders();

        $response = $this->getJson('/api/v1/warehouses/'.Str::uuid(), $headers);

        $response->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_warehouse_update_writes_audit_log(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'WH-AUDIT', 'name' => 'Kho audit', 'status' => 'ACTIVE']);

        $response = $this->patchJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Kho audit đã sửa',
        ], $headers);

        $response->assertOk()->assertJsonPath('data.name', 'Kho audit đã sửa');
        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'warehouse',
            'object_id' => $warehouse->id,
            'action' => 'UPDATE',
        ]);
    }

    public function test_warehouse_has_no_create_or_delete_route(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/warehouses', ['code' => 'WH-NEW', 'name' => 'Kho mới'], $headers)
            ->assertStatus(405);

        $warehouse = Warehouse::create(['code' => 'WH-NODELETE', 'name' => 'Kho không xoá được', 'status' => 'ACTIVE']);
        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}", [], $headers)
            ->assertStatus(405);
    }
}
