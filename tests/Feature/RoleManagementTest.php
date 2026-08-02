<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

class RoleManagementTest extends TestCase
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

    public function test_role_can_be_created_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/roles', [
            'role_code' => 'CUSTOM_ROLE',
            'role_name' => 'Vai trò tuỳ chỉnh',
        ], $headers);

        $create->assertCreated()->assertJsonPath('data.role_code', 'CUSTOM_ROLE');
        $id = $create->json('data.role_id');

        $update = $this->patchJson("/api/v1/roles/{$id}", ['role_name' => 'Vai trò đã sửa'], $headers);
        $update->assertOk()->assertJsonPath('data.role_name', 'Vai trò đã sửa');
    }

    public function test_role_code_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        Role::create(['role_code' => 'DUP_ROLE', 'role_name' => 'Gốc', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/roles', [
            'role_code' => 'DUP_ROLE',
            'role_name' => 'Trùng code',
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_role_can_be_assigned_permissions(): void
    {
        $headers = $this->authHeaders();
        $role = Role::create(['role_code' => 'PERM_TEST', 'role_name' => 'Perm test', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'warehouse.view'],
            ['permission_name' => 'Xem kho', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );

        $response = $this->postJson("/api/v1/roles/{$role->role_id}/permissions", [
            'permission_ids' => [$permission->permission_id],
        ], $headers);

        $response->assertOk();
        $this->assertTrue($role->fresh()->permissions()->where('permission.permission_id', $permission->permission_id)->exists());
    }

    public function test_role_delete_is_blocked_when_assigned_to_user(): void
    {
        $headers = $this->authHeaders();
        $role = Role::create(['role_code' => 'IN_USE_ROLE', 'role_name' => 'Đang dùng', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'role-holder',
            'display_name' => 'Role holder',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $response = $this->deleteJson("/api/v1/roles/{$role->role_id}", [], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'REFERENCE_EXISTS');
    }

    public function test_role_delete_succeeds_when_not_assigned(): void
    {
        $headers = $this->authHeaders();
        $role = Role::create(['role_code' => 'UNUSED_ROLE', 'role_name' => 'Không dùng', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);

        $response = $this->deleteJson("/api/v1/roles/{$role->role_id}", [], $headers);

        $response->assertOk();
        $this->assertNull(Role::find($role->role_id));
    }
}
