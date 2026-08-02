<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class FoundationAuthRbacAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_login_me_refresh_logout_contract_and_audit_are_recorded(): void
    {
        $this->seedRoleWithPermissions('WAREHOUSE_MANAGER', [
            'warehouse.view',
            'stock_document.view',
        ]);

        $user = $this->createUser('manager', 'WAREHOUSE_MANAGER');

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'manager',
            'password' => 'Cntt@1234',
        ]);

        $login->assertOk()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.username', 'manager')
            ->assertJsonPath('data.user.roles.0.role_code', 'WAREHOUSE_MANAGER')
            ->assertJsonPath('data.user.permissions.0', 'warehouse.view')
            ->assertJsonStructure([
                'data' => ['access_token', 'refresh_token', 'expires_in', 'user' => ['roles', 'permissions']],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'LOGIN',
            'object_type' => 'app_user',
            'object_id' => $user->user_id,
        ]);

        $headers = ['Authorization' => 'Bearer '.$login->json('data.access_token')];
        $me = $this->getJson('/api/v1/auth/me', $headers);
        $me->assertOk()
            ->assertJsonPath('data.username', 'manager')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.roles.0.role_code', 'WAREHOUSE_MANAGER');

        $refresh = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login->json('data.refresh_token'),
        ]);
        $refresh->assertOk()
            ->assertJsonPath('data.user.username', 'manager')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

        $this->postJson('/api/v1/auth/logout', [
            'refresh_token' => $refresh->json('data.refresh_token'),
        ], ['Authorization' => 'Bearer '.$refresh->json('data.access_token')])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_log', ['action' => 'TOKEN_REFRESH', 'object_id' => $user->user_id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'LOGOUT', 'object_id' => $user->user_id]);
    }

    public function test_auth_failures_use_standard_error_contract_and_do_not_leak_secrets(): void
    {
        $this->createUser('locked', null, status: 'LOCKED');
        $this->createUser('active', null);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'active',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED')
            ->assertJsonMissing(['password' => 'wrong-password'])
            ->assertJsonStructure(['error' => ['code', 'details'], 'meta' => ['request_id', 'timestamp']]);

        $locked = $this->postJson('/api/v1/auth/login', [
            'username' => 'locked',
            'password' => 'Cntt@1234',
        ]);

        $locked->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonMissingPath('trace');

        $this->assertDatabaseHas('audit_log', ['action' => 'LOGIN_FAILED']);
        $this->assertDatabaseHas('audit_log', ['action' => 'LOGIN_DENIED']);
    }

    public function test_permission_middleware_blocks_and_allows_by_role_permission(): void
    {
        Warehouse::create(['code' => 'KHO-RBAC', 'name' => 'Kho RBAC', 'status' => 'ACTIVE']);

        $this->seedRoleWithPermissions('NO_WAREHOUSE_ACCESS', []);
        $this->seedRoleWithPermissions('WAREHOUSE_VIEWER', ['warehouse.view']);

        $deniedUser = $this->createUser('denied', 'NO_WAREHOUSE_ACCESS');
        $allowedUser = $this->createUser('viewer', 'WAREHOUSE_VIEWER');

        $this->assertFalse($deniedUser->fresh()->hasPermission('warehouse.view'));
        $this->assertTrue($allowedUser->fresh()->hasPermission('warehouse.view'));

        $deniedToken = JWTAuth::fromUser($deniedUser);
        $allowedToken = JWTAuth::fromUser($allowedUser);

        $denied = $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$deniedToken}"]);
        $denied->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonMissingPath('trace');

        auth('api')->forgetUser();
        $this->flushHeaders();

        $allowed = $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$allowedToken}"]);
        $allowed->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'timestamp']]);
    }

    public function test_protected_endpoints_and_audit_logs_require_auth_and_permission(): void
    {
        AuditLog::create([
            'actor_id' => null,
            'action' => 'TEST_EVENT',
            'object_type' => 'foundation',
            'object_id' => null,
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->seedRoleWithPermissions('WAREHOUSE_VIEWER', ['warehouse.view']);
        $this->seedRoleWithPermissions('AUDITOR', ['audit_log.view']);

        $viewer = $this->createUser('viewer2', 'WAREHOUSE_VIEWER');
        $auditor = $this->createUser('auditor', 'AUDITOR');

        $this->assertFalse($viewer->fresh()->hasPermission('audit_log.view'));
        $this->assertTrue($auditor->fresh()->hasPermission('audit_log.view'));

        $viewerToken = JWTAuth::fromUser($viewer);
        $auditorToken = JWTAuth::fromUser($auditor);

        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$viewerToken}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        auth('api')->forgetUser();
        $this->flushHeaders();

        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$auditorToken}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_malformed_json_returns_standard_400_contract(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"username": "admin", "password":'
        );

        $response->assertStatus(400)
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'MALFORMED_JSON')
            ->assertJsonStructure(['errors', 'meta' => ['request_id', 'timestamp']]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function seedRoleWithPermissions(string $roleCode, array $permissionKeys): Role
    {
        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );

            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                [
                    'role_permission_id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $role;
    }

    private function createUser(string $username, ?string $roleCode, string $status = 'ACTIVE'): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => $status,
            'version' => 1,
        ]);

        if ($roleCode !== null) {
            $role = Role::where('role_code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }
}
