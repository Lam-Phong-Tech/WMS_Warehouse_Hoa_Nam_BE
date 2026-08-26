<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
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
        Warehouse::create(['code' => 'WH-MAIN', 'name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']);

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

    /**
     * AC-SRS-001 + §8.1 Warehouse: "GET/POST /warehouses ... create/edit name,
     * address, active status; reject second warehouse with 409". Có tạo (Annex C
     * createWarehouse) nhưng KHÔNG có xoá kho — kho là sổ tồn, chỉ deactivate.
     */
    public function test_warehouse_create_exists_and_rejects_second_active_but_delete_stays_absent(): void
    {
        $headers = $this->authHeaders();
        Warehouse::query()->where('status', 'ACTIVE')->update(['status' => 'INACTIVE']);

        $this->postJson('/api/v1/warehouses', ['code' => 'WH-NEW', 'name' => 'Kho mới'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.code', 'WH-NEW');

        $this->postJson('/api/v1/warehouses', ['code' => 'WH-SECOND', 'name' => 'Kho thứ hai'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        $warehouse = Warehouse::query()->where('code', 'WH-NEW')->firstOrFail();
        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}", [], $headers)
            ->assertStatus(405);
    }

    public function test_repository_blocks_creating_a_second_active_warehouse(): void
    {
        Warehouse::create(['code' => 'WH-ONE', 'name' => 'Kho 1', 'status' => 'ACTIVE']);

        $repository = app(WarehouseRepositoryInterface::class);

        $this->expectException(HttpResponseException::class);

        try {
            $repository->create(['code' => 'WH-TWO', 'name' => 'Kho 2', 'status' => 'ACTIVE']);
        } finally {
            $this->assertDatabaseMissing('warehouses', ['code' => 'WH-TWO']);
        }
    }

    /**
     * REQ-SRS-001: hệ thống chỉ vận hành đúng 1 kho tổng vĩnh viễn — kể cả tạo
     * thêm 1 kho INACTIVE (không tranh chấp "kho ACTIVE duy nhất") cũng phải bị
     * chặn 409, không chỉ chặn khi kho mới là ACTIVE.
     */
    public function test_repository_blocks_creating_a_second_inactive_warehouse(): void
    {
        Warehouse::create(['code' => 'WH-ONE', 'name' => 'Kho 1', 'status' => 'ACTIVE']);

        $repository = app(WarehouseRepositoryInterface::class);

        $this->expectException(HttpResponseException::class);

        try {
            $repository->create(['code' => 'WH-EXTRA', 'name' => 'Kho phụ', 'status' => 'INACTIVE']);
        } finally {
            $this->assertDatabaseMissing('warehouses', ['code' => 'WH-EXTRA']);
        }
    }

    public function test_repository_blocks_updating_another_warehouse_to_active(): void
    {
        Warehouse::create(['code' => 'WH-ACTIVE', 'name' => 'Kho active', 'status' => 'ACTIVE']);
        $inactive = Warehouse::create(['code' => 'WH-INACTIVE', 'name' => 'Kho inactive', 'status' => 'INACTIVE']);

        $repository = app(WarehouseRepositoryInterface::class);

        $this->expectException(HttpResponseException::class);

        try {
            $repository->update($inactive->id, ['status' => 'ACTIVE']);
        } finally {
            $this->assertDatabaseHas('warehouses', ['code' => 'WH-INACTIVE', 'status' => 'INACTIVE']);
        }
    }

    public function test_repository_allows_updating_the_same_active_warehouse(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-SELF', 'name' => 'Kho self', 'status' => 'ACTIVE']);

        $repository = app(WarehouseRepositoryInterface::class);
        $updated = $repository->update($warehouse->id, ['status' => 'ACTIVE', 'name' => 'Kho self đã sửa']);

        $this->assertSame('Kho self đã sửa', $updated->name);
    }
}
