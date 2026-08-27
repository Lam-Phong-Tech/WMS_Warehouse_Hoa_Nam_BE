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
            'reason' => 'Test reason for audit',
        ], $headers);

        $response->assertOk();
        $this->assertTrue($target->fresh()->roles()->where('role.role_id', $targetRole->role_id)->exists());
    }

    public function test_user_cannot_assign_roles_to_self(): void
    {
        $ownRole = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $actor = User::create([
            'username' => 'self-escalator-'.Str::random(6),
            'display_name' => 'Self escalator',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $actor->roles()->attach($ownRole->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($actor);
        $headers = ['Authorization' => "Bearer {$token}"];

        $grantedRole = Role::create(['role_code' => 'SELF_ESCALATION_TEST', 'role_name' => 'Escalation target', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);

        $response = $this->postJson("/api/v1/users/{$actor->user_id}/roles", [
            'role_ids' => [$grantedRole->role_id],
            'reason' => 'Test reason for audit',
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'SELF_ESCALATION_DENIED');
        $this->assertTrue($actor->fresh()->roles()->where('role.role_id', $ownRole->role_id)->exists());
        $this->assertFalse($actor->fresh()->roles()->where('role.role_id', $grantedRole->role_id)->exists());
    }

    /**
     * BR-IAM-01/06 + retention >=5 năm: tài khoản là chủ thể của audit trail nên
     * không có endpoint xoá (Annex C cũng không công bố deleteUser). Vô hiệu hoá
     * bằng PATCH status=INACTIVE, bản ghi và lịch sử giữ nguyên.
     */
    public function test_user_has_no_delete_route_and_is_disabled_by_status(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'to-disable',
            'display_name' => 'To disable',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $this->deleteJson("/api/v1/users/{$target->user_id}", [], $headers)
            ->assertStatus(405);

        $this->patchJson("/api/v1/users/{$target->user_id}", ['status' => 'INACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');
        $this->assertNotNull(User::find($target->user_id));
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

    /**
     * §8.1 IAM "If-Match on update" + Annex C updateUser (412). Body `version` là
     * đường legacy, nay trả cùng mã 412 STALE_VERSION như mọi aggregate khác.
     */
    public function test_user_update_with_stale_version_in_body_returns_412(): void
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

        $response->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertSame('Stale user', $target->fresh()->display_name);
    }

    public function test_user_update_with_stale_if_match_header_returns_412(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'stale-header',
            'display_name' => 'Stale header user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 3,
        ]);

        $response = $this->patchJson(
            "/api/v1/users/{$target->user_id}",
            ['display_name' => 'Ghi đè trái phép'],
            $headers + ['If-Match' => '1']
        );

        $response->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertSame('Stale header user', $target->fresh()->display_name);
    }

    public function test_user_update_with_matching_if_match_header_succeeds_and_bumps_version(): void
    {
        $headers = $this->authHeaders();
        $target = User::create([
            'username' => 'fresh-header',
            'display_name' => 'Fresh header user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 3,
        ]);

        $response = $this->patchJson(
            "/api/v1/users/{$target->user_id}",
            ['display_name' => 'Đã đổi tên'],
            $headers + ['If-Match' => '3']
        );

        $response->assertOk()->assertJsonPath('data.version', 4);
        $this->assertSame('Đã đổi tên', $target->fresh()->display_name);
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

    /**
     * AuthController::login() chấp nhận cả username lẫn email
     * (LoginRequest: email required_without:username và ngược lại) — không còn
     * bắt buộc chỉ email-only. Username sai lệch (chưa trim/normalize khi tạo)
     * không khớp bản ghi nên trả 401, không phải lỗi validate 422.
     */
    public function test_login_with_unmatched_username_returns_unauthenticated(): void
    {
        User::create([
            'username' => 'normalized',
            'email' => 'normalized@wms-hoanam.test',
            'display_name' => 'Normalized user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => '  Normalized  ',
            'password' => 'secret123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
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
