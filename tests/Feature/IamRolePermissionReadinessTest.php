<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

class IamRolePermissionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(array $permissionKeys, string $username): array
    {
        $role = Role::create([
            'role_code' => Str::upper($username),
            'role_name' => 'IAM test role',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user = User::create([
            'username' => $username,
            'display_name' => 'IAM readiness user',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    public function test_role_list_returns_permission_membership_for_matrix_ui(): void
    {
        $permission = Permission::create([
            'permission_key' => 'inbound.view',
            'permission_name' => 'View inbound',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create([
            'role_code' => 'INBOUND_VIEWER',
            'role_name' => 'Inbound Viewer',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        $role->permissions()->attach($permission->permission_id, [
            'role_permission_id' => (string) Str::uuid(),
        ]);

        $headers = $this->authHeaders(['iam.role.view'], 'role_reader');

        $response = $this->getJson('/api/v1/roles?keyword=INBOUND_VIEWER&per_page=100', $headers);
        $response->assertOk()
            ->assertJsonPath('data.0.role_code', 'INBOUND_VIEWER')
            ->assertJsonPath('data.0.permissions.0.permission_key', 'inbound.view');
    }

    public function test_permission_catalog_requires_iam_permission_view(): void
    {
        Permission::create([
            'permission_key' => 'iam.permission.view',
            'permission_name' => 'View permission catalog',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);

        $without = $this->authHeaders(['iam.role.view'], 'permission_denied');
        $this->getJson('/api/v1/permissions', $without)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $with = $this->authHeaders(['iam.permission.view'], 'permission_reader');
        $this->getJson('/api/v1/permissions?per_page=100', $with)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_role_manager_can_create_update_and_atomically_sync_permissions(): void
    {
        // SCR-IAM-02 (27/08): người thao tác chỉ được cấp/thu hồi quyền mà CHÍNH HỌ
        // đang có. Trước đây fixture này chỉ giữ nhóm iam.* rồi gán inbound.* cho một
        // role khác — đúng kịch bản tự nâng quyền mà PermissionDelegation nay chặn.
        // Cấp inbound.view/inbound.create cho chính actor để test quay lại đo đúng thứ
        // nó muốn đo (sync ma trận là atomic). Kịch bản "gán quyền vượt tầm bị chặn"
        // được đo riêng ở PrivilegeEscalationGuardTest.
        $headers = $this->authHeaders(
            ['iam.role.view', 'iam.role.manage', 'iam.permission.view', 'inbound.view', 'inbound.create'],
            'role_manager'
        );
        $p1 = Permission::query()->where('permission_key', 'inbound.view')->firstOrFail();
        $p2 = Permission::query()->where('permission_key', 'inbound.create')->firstOrFail();

        $create = $this->postJson('/api/v1/roles', [
            'role_code' => 'INBOUND_OPERATOR',
            'role_name' => 'Inbound Operator',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.role_code', 'INBOUND_OPERATOR');
        $roleId = $create->json('data.role_id');

        $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => [$p1->permission_id, $p2->permission_id],
            'reason' => 'Test reason for audit',
        ], $headers)->assertOk()
            ->assertJsonCount(2, 'data.permissions');

        $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => [$p1->permission_id],
            'reason' => 'Test reason for audit',
        ], $headers)->assertOk()
            ->assertJsonCount(1, 'data.permissions')
            ->assertJsonPath('data.permissions.0.permission_key', 'inbound.view');

        $this->patchJson("/api/v1/roles/{$roleId}", [
            'role_name' => 'Inbound Operator Updated',
            'status' => 'INACTIVE',
        ], $headers)->assertOk()
            ->assertJsonPath('data.role_name', 'Inbound Operator Updated')
            ->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_super_admin_role_is_immutable_even_for_authorized_direct_api_calls(): void
    {
        $headers = $this->authHeaders(['iam.role.manage'], 'system_role_attacker');
        $superAdmin = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'System Administrator',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $permission = Permission::create([
            'permission_key' => 'iam.user.view',
            'permission_name' => 'View IAM users',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);

        $this->patchJson("/api/v1/roles/{$superAdmin->role_id}", [
            'role_name' => 'Compromised',
        ], $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'SYSTEM_ROLE_IMMUTABLE');

        $this->postJson("/api/v1/roles/{$superAdmin->role_id}/permissions", [
            'permission_ids' => [$permission->permission_id],
        ], $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'SYSTEM_ROLE_IMMUTABLE');

        $this->deleteJson("/api/v1/roles/{$superAdmin->role_id}", [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SYSTEM_ROLE_IMMUTABLE');

        $this->assertDatabaseHas('role', [
            'role_id' => $superAdmin->role_id,
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'System Administrator',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_role_assigned_to_user_cannot_be_deleted(): void
    {
        $headers = $this->authHeaders(['iam.role.manage'], 'delete_role_manager');
        $role = Role::create([
            'role_code' => 'ASSIGNED_ROLE',
            'role_name' => 'Assigned role',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'assigned_role_user',
            'display_name' => 'Assigned role user',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $this->deleteJson("/api/v1/roles/{$role->role_id}", [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REFERENCE_EXISTS');
    }
}
