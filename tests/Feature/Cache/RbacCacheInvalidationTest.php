<?php

namespace Tests\Feature\Cache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Mục 11 + mục 29 "RBAC revoke": permission/warehouse-scope cache key theo
 * user.version, TTL 300s — nhưng revoke/disable phải có hiệu lực NGAY, không được
 * chờ hết TTL. Test dựng role/permission riêng (không phụ thuộc seeder) để cô lập.
 */
class RbacCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private User $superAdmin;

    private string $superAdminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
        // RolePermissionsChanged tự bắn 1 queued listener (module Notification,
        // ShouldQueue) — không liên quan gì tới RBAC cache đang test ở đây, fake
        // queue để cô lập, tránh phụ thuộc vào trạng thái/schema notification.
        Queue::fake();

        $this->superAdmin = User::where('username', 'test-super-admin')->firstOrFail();
        $this->superAdminToken = JWTAuth::fromUser($this->superAdmin);
    }

    private function makeSubjectWithPermission(string $permissionKey): array
    {
        $permission = Permission::firstOrCreate(
            ['permission_key' => $permissionKey],
            ['permission_name' => $permissionKey, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );

        $role = Role::create([
            'role_code' => 'RBAC_CACHE_TEST_'.Str::upper(Str::random(6)),
            'role_name' => 'RBAC Cache Test Role',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $user = User::create([
            'username' => 'rbac-cache-subject-'.Str::lower(Str::random(8)),
            'display_name' => 'RBAC Cache Subject',
            'email' => Str::lower(Str::random(8)).'@hoanam.test',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$role, $user->fresh()];
    }

    public function test_permission_codes_are_cached_and_hit_on_second_call(): void
    {
        [, $user] = $this->makeSubjectWithPermission('rbac_cache.probe');
        $roleIds = $user->activeRoleModels()->pluck('role_id');

        $first = $user->permissionCodes();
        // Xoá role_permission ở DB nhưng KHÔNG bump version — dùng MỘT INSTANCE MỚI
        // (không dính request-local activeRolesMemo của $user gốc) để chứng minh kết
        // quả thứ 2 đến từ Cache::remember(), không phải object memoization hay DB
        // fresh read.
        DB::table('role_permission')->whereIn('role_id', $roleIds)->delete();
        $second = User::query()->find($user->user_id)->permissionCodes();

        $this->assertContains('rbac_cache.probe', $first);
        $this->assertSame($first, $second, 'permissionCodes() phải trả cache cũ khi version chưa đổi (dù DB đã đổi).');
    }

    public function test_revoking_user_role_takes_effect_on_next_request_not_after_ttl(): void
    {
        [, $user] = $this->makeSubjectWithPermission('rbac_cache.revoke_probe');
        $token = JWTAuth::fromUser($user);

        $this->assertTrue($user->hasPermission('rbac_cache.revoke_probe'));

        // Warm cache qua đúng đường dùng thật của app: /auth/me đọc permissionCodes().
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.permissions', fn ($permissions) => in_array('rbac_cache.revoke_probe', $permissions, true));

        // AssignUserRoleRequest coi role_ids=[] là "thiếu field" (required) nên
        // không revoke được về rỗng qua endpoint này — thay bằng gán sang 1 role
        // không có permission nào, tương đương thu hồi hoàn toàn quyền cũ.
        $emptyRole = Role::create([
            'role_code' => 'RBAC_CACHE_EMPTY_'.Str::upper(Str::random(6)),
            'role_name' => 'RBAC Cache Empty Role',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        $this->postJson("/api/v1/users/{$user->user_id}/roles", [
            'role_ids' => [$emptyRole->role_id],
            'reason' => 'Test reason for audit',
        ], [
            'Authorization' => "Bearer {$this->superAdminToken}",
        ])->assertOk();

        $fresh = User::query()->find($user->user_id);
        $this->assertFalse(
            $fresh->hasPermission('rbac_cache.revoke_probe'),
            'Revoke role phải có hiệu lực ngay lập tức, không được giữ quyền cũ tới khi TTL 300s hết hạn.'
        );
    }

    public function test_disabling_role_takes_effect_immediately_for_all_holders(): void
    {
        [$role, $user] = $this->makeSubjectWithPermission('rbac_cache.disable_probe');

        $this->assertTrue($user->hasPermission('rbac_cache.disable_probe'));

        // Vô hiệu hoá role qua PATCH /roles/{id} — đây chính là gap đã sửa
        // (RoleController::update trước đây không bump version user giữ role).
        $this->patchJson("/api/v1/roles/{$role->role_id}", ['status' => 'INACTIVE'], [
            'Authorization' => "Bearer {$this->superAdminToken}",
        ])->assertOk();

        $fresh = User::query()->find($user->user_id);
        $this->assertFalse(
            $fresh->hasPermission('rbac_cache.disable_probe'),
            'Disable role phải có hiệu lực ngay cho MỌI user đang giữ role đó.'
        );
    }

    public function test_role_permission_reassignment_takes_effect_immediately(): void
    {
        [$role, $user] = $this->makeSubjectWithPermission('rbac_cache.reassign_probe_old');

        $this->assertTrue($user->hasPermission('rbac_cache.reassign_probe_old'));

        $newPermission = Permission::firstOrCreate(
            ['permission_key' => 'rbac_cache.reassign_probe_new'],
            ['permission_name' => 'new', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );

        $this->postJson("/api/v1/roles/{$role->role_id}/permissions", [
            'permission_ids' => [$newPermission->permission_id],
            'reason' => 'Test reason for audit',
        ], ['Authorization' => "Bearer {$this->superAdminToken}"])->assertOk();

        $fresh = User::query()->find($user->user_id);
        $this->assertFalse($fresh->hasPermission('rbac_cache.reassign_probe_old'));
        $this->assertTrue($fresh->hasPermission('rbac_cache.reassign_probe_new'));
    }

    /**
     * IamRolePermissionScreen (IAM-01 tab "Vai trò & ma trận quyền") không đọc
     * enforcement (hasPermission) — nó đọc lại GET /roles/{id} (RoleRepository,
     * cache domain 'auth.role', TTL rbac) để hiển thị đúng những gì vừa lưu.
     * assignPermissions() ghi qua $role->permissions()->sync() thẳng trên Eloquent
     * relation, bỏ qua RoleRepository::update() — nếu quên forgetCache() thì GET
     * /roles/{id} vẫn trả permission set CŨ tới hết TTL dù DB/enforcement đã đúng,
     * khiến UI trông như "lưu không ăn".
     */
    public function test_get_role_detail_reflects_permission_reassignment_immediately(): void
    {
        [$role] = $this->makeSubjectWithPermission('rbac_cache.detail_probe_old');

        // Warm cache 'auth.role:detail' qua đúng đường FE dùng thật.
        $this->getJson("/api/v1/roles/{$role->role_id}", [
            'Authorization' => "Bearer {$this->superAdminToken}",
        ])->assertOk()->assertJsonPath('data.permissions.0.permission_key', 'rbac_cache.detail_probe_old');

        $newPermission = Permission::firstOrCreate(
            ['permission_key' => 'rbac_cache.detail_probe_new'],
            ['permission_name' => 'new', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );

        $this->postJson("/api/v1/roles/{$role->role_id}/permissions", [
            'permission_ids' => [$newPermission->permission_id],
            'reason' => 'Test reason for audit',
        ], ['Authorization' => "Bearer {$this->superAdminToken}"])->assertOk();

        $keys = fn ($permissions) => collect($permissions)->pluck('permission_key')->all();

        $this->getJson("/api/v1/roles/{$role->role_id}", [
            'Authorization' => "Bearer {$this->superAdminToken}",
        ])->assertOk()
            ->assertJsonPath('data.permissions', fn ($permissions) => $keys($permissions) === ['rbac_cache.detail_probe_new']);

        $this->getJson('/api/v1/roles?per_page=100', [
            'Authorization' => "Bearer {$this->superAdminToken}",
        ])->assertOk()->assertJsonPath('data', function ($roles) use ($role, $keys) {
            $match = collect($roles)->firstWhere('role_id', $role->role_id);

            return $match !== null && $keys($match['permissions']) === ['rbac_cache.detail_probe_new'];
        });
    }
}
