<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * Permission là danh mục hệ thống cố định (comment trong PermissionController) — chỉ có
 * route GET, không có route tạo/sửa/xoá qua API.
 */
class PermissionCatalogTest extends TestCase
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

    public function test_permission_list_and_detail_are_readable(): void
    {
        $headers = $this->authHeaders();
        $permission = Permission::create([
            'permission_key' => 'warehouse.view',
            'permission_name' => 'Xem kho',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);

        $this->getJson('/api/v1/permissions', $headers)->assertOk();
        $this->getJson("/api/v1/permissions/{$permission->permission_id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.permission_key', 'warehouse.view');
    }

    public function test_permission_has_no_create_update_or_delete_route(): void
    {
        $headers = $this->authHeaders();
        $permission = Permission::create([
            'permission_key' => 'agency.view',
            'permission_name' => 'Xem đại lý',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v1/permissions', ['permission_key' => 'x', 'permission_name' => 'x'], $headers)
            ->assertStatus(405);
        $this->patchJson("/api/v1/permissions/{$permission->permission_id}", ['permission_name' => 'x'], $headers)
            ->assertStatus(405);
        $this->deleteJson("/api/v1/permissions/{$permission->permission_id}", [], $headers)
            ->assertStatus(405);
    }
}
