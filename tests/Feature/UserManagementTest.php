<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    public function test_user_can_be_created_without_leaking_password_hash(): void
    {
        $headers = $this->authHeaders();

        $response = $this->postJson('/api/v1/users', [
            'username' => 'newstaff',
            'display_name' => 'Nhân viên mới',
            'email' => 'newstaff@wms-hoanam.local',
            'password' => 'Cntt@1234',
        ], $headers);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'newstaff')
            ->assertJsonMissingPath('data.password_hash')
            ->assertJsonMissingPath('data.password');
    }

    public function test_username_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        User::create([
            'username' => 'dupuser',
            'display_name' => 'Existing',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/users', [
            'username' => 'dupuser',
            'display_name' => 'Trung username',
            'password' => 'Cntt@1234',
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_user_can_be_assigned_roles(): void
    {
        $headers = $this->authHeaders();
        $targetRole = Role::create(['role_code' => 'VIEWER_TEST', 'role_name' => 'Viewer test', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $target = User::create([
            'username' => 'assignee',
            'display_name' => 'Assignee',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/users/{$target->user_id}/roles", [
            'role_ids' => [$targetRole->role_id],
        ], $headers);

        $response->assertOk();
        $this->assertTrue($target->fresh()->roles()->where('role.role_id', $targetRole->role_id)->exists());
    }

    public function test_user_delete_is_soft_delete(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'to-delete',
            'display_name' => 'To delete',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->deleteJson("/api/v1/users/{$target->user_id}", [], $headers);

        $response->assertOk();
        $this->assertNull(User::find($target->user_id));
        $this->assertNotNull(User::withTrashed()->find($target->user_id));
    }

    public function test_user_update_with_matching_version_succeeds_and_bumps_version(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'versioned',
            'display_name' => 'Versioned user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->patchJson("/api/v1/users/{$target->user_id}", [
            'display_name' => 'Versioned user v2',
            'version' => 1,
        ], $headers);

        $response->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_user_update_with_stale_version_returns_409(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'stale',
            'display_name' => 'Stale user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 3,
        ]);

        $response = $this->patchJson("/api/v1/users/{$target->user_id}", [
            'display_name' => 'Ghi đè trái phép',
            'version' => 1,
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
        $this->assertSame('Stale user', $target->fresh()->display_name);
    }

    public function test_username_is_normalized_to_lowercase_trimmed_on_create(): void
    {
        $headers = $this->authHeaders();

        $response = $this->postJson('/api/v1/users', [
            'username' => '  MixedCase  ',
            'display_name' => 'Mixed case user',
            'password' => 'Cntt@1234',
        ], $headers);

        $response->assertCreated()->assertJsonPath('data.username', 'mixedcase');
        $this->assertDatabaseHas('app_user', ['username' => 'mixedcase']);
    }

    public function test_login_is_case_insensitive_against_normalized_username(): void
    {
        User::create([
            'username' => 'normalized',
            'display_name' => 'Normalized user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => '  Normalized  ',
            'password' => 'secret123',
        ]);

        $response->assertOk();
    }

    public function test_user_create_and_update_write_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/users', [
            'username' => 'audited',
            'display_name' => 'Audited user',
            'password' => 'Cntt@1234',
        ], $headers);
        $id = $create->json('data.user_id');

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'user',
            'object_id' => $id,
            'action' => 'CREATE',
        ]);

        $this->patchJson("/api/v1/users/{$id}", ['display_name' => 'Audited user v2', 'version' => 1], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'user',
            'object_id' => $id,
            'action' => 'UPDATE',
        ]);
    }
}
