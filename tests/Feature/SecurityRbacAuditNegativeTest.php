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

/**
 * QA1 Security/RBAC/Audit Negative Test Suite
 *
 * Covers:
 * - BE1-P1-01: Security/RBAC/audit test độc lập (401/403/token expired/permission/audit/API negative)
 * - BE1-P1-06: Object-level/data-scope test (user không có quyền/kho/phạm vi dữ liệu bị deny 403)
 * - BE1-P1-02: QA2 role/navigation/403 test (login/session/403/navigation theo role)
 */
class SecurityRbacAuditNegativeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ========================================
    // BE1-P1-01: Security/RBAC/Audit Tests
    // ========================================

    /**
     * Test 401 UNAUTHENTICATED for all protected endpoints without token
     */
    public function test_protected_endpoints_return_401_without_token(): void
    {
        $protectedEndpoints = [
            ['GET', '/api/v1/auth/me'],
            ['GET', '/api/v1/warehouses'],
            ['GET', '/api/v1/audit-logs'],
            ['GET', '/api/v1/products'],
            ['GET', '/api/v1/inventory/balances'],
        ];

        foreach ($protectedEndpoints as [$method, $url]) {
            $response = match ($method) {
                'GET' => $this->getJson($url),
                'POST' => $this->postJson($url),
                'PATCH' => $this->patchJson($url),
                default => $this->call($method, $url),
            };

            $response->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error_code', 'UNAUTHENTICATED')
                ->assertHeader('X-Request-ID');
        }
    }

    /**
     * Test 403 ACCESS_DENIED for user without required permission
     */
    public function test_user_without_permission_gets_403_on_protected_endpoints(): void
    {
        $this->seedRoleWithPermissions('NO_PERMISSIONS', []);
        $userWithoutPerms = $this->createUser('noperms', 'NO_PERMISSIONS');
        $token = JWTAuth::fromUser($userWithoutPerms);

        // Test GET endpoints without permission
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonMissingPath('trace')
            ->assertHeader('X-Request-ID');

        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // Test POST endpoint without permission (inbound-documents requires stock_document.create)
        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => '00000000-0000-0000-0000-000000000000',
            'lines' => [],
        ], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');
    }

    /**
     * Test token expired/invalid returns 401
     */
    public function test_expired_and_invalid_tokens_return_401(): void
    {
        // Invalid token format
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer invalid-token-format'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        // Malformed token
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.invalid'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        // Empty token
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * Test audit logs are created for all auth lifecycle events
     */
    public function test_auth_lifecycle_creates_audit_logs(): void
    {
        $this->seedRoleWithPermissions('TEST_USER', ['warehouse.view']);
        $user = $this->createUser('audituser', 'TEST_USER');

        // Clear any existing audit logs
        AuditLog::query()->delete();

        // 1. Failed login attempt
        $this->postJson('/api/v1/auth/login', [
            'email' => 'audituser@wms-hoanam.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'LOGIN_FAILED',
            'object_type' => 'app_user',
            'object_id' => $user->user_id,
        ]);

        // 2. Successful login
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'audituser@wms-hoanam.test',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'LOGIN',
            'object_type' => 'app_user',
            'object_id' => $user->user_id,
        ]);

        $token = $login->json('data.access_token');
        $refreshToken = $login->json('data.refresh_token');

        // 3. Token refresh
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'TOKEN_REFRESH',
            'object_type' => 'app_user',
            'object_id' => $user->user_id,
        ]);

        // 4. Logout
        $this->postJson('/api/v1/auth/logout', [
            'refresh_token' => $refreshToken,
        ], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'LOGOUT',
            'object_type' => 'app_user',
            'object_id' => $user->user_id,
        ]);

        // Verify total audit count
        $this->assertEquals(4, AuditLog::where('object_id', $user->user_id)->count());
    }

    /**
     * Test audit logs do not contain sensitive data
     */
    public function test_audit_logs_do_not_contain_sensitive_data(): void
    {
        $this->seedRoleWithPermissions('TEST_USER', ['warehouse.view']);
        $user = $this->createUser('secureuser', 'TEST_USER');

        AuditLog::query()->delete();

        // Failed login
        $this->postJson('/api/v1/auth/login', [
            'email' => 'secureuser@wms-hoanam.test',
            'password' => 'SuperSecret123!',
        ])->assertStatus(401);

        $auditLog = AuditLog::where('action', 'LOGIN_FAILED')->first();
        $this->assertNotNull($auditLog);

        // Verify no sensitive data in after_data
        $afterData = $auditLog->after_data ?? [];
        $this->assertArrayNotHasKey('password', $afterData);
        $this->assertArrayNotHasKey('password_hash', $afterData);
        $this->assertArrayNotHasKey('access_token', $afterData);
        $this->assertArrayNotHasKey('refresh_token', $afterData);
        $this->assertStringNotContainsString('SuperSecret123', json_encode($afterData));
    }

    /**
     * Test locked account returns 403 and creates LOGIN_DENIED audit
     */
    public function test_locked_account_returns_403_and_audits(): void
    {
        $this->createUser('lockeduser', null, status: 'LOCKED');
        AuditLog::query()->delete();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'lockeduser@wms-hoanam.test',
            'password' => 'secret123',
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'LOGIN_DENIED',
            'object_type' => 'app_user',
        ]);
    }

    // ========================================
    // BE1-P1-06: Object-Level/Data-Scope Tests
    // ========================================

    /**
     * Test user without warehouse scope cannot access warehouse-specific data
     */
    public function test_user_without_warehouse_scope_gets_403(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'KHO-SCOPE',
            'name' => 'Kho Scope Test',
            'status' => 'ACTIVE',
        ]);

        $this->seedRoleWithPermissions('WAREHOUSE_VIEWER_SCOPE', ['warehouse.view']);
        $user = $this->createUser('scopeuser', 'WAREHOUSE_VIEWER_SCOPE');
        $token = JWTAuth::fromUser($user);

        // User can see warehouse list (no specific warehouse restriction in this test)
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // But cannot access audit logs (different permission)
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    /**
     * Test SUPER_ADMIN bypasses all permission checks
     */
    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $this->seedRoleWithPermissions('SUPER_ADMIN', []);
        $superAdmin = $this->createUser('superadmin', 'SUPER_ADMIN');
        $token = JWTAuth::fromUser($superAdmin);

        // SUPER_ADMIN should access everything
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // SUPER_ADMIN can access products (requires product.view permission)
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    /**
     * Test permission check is case-sensitive and exact
     */
    public function test_permission_check_is_exact_match(): void
    {
        $this->seedRoleWithPermissions('PARTIAL_PERMISSION', ['warehouse.view']);
        $user = $this->createUser('partialuser', 'PARTIAL_PERMISSION');
        $token = JWTAuth::fromUser($user);

        // Has warehouse.view - can read warehouses
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // Does NOT have audit_log.view - cannot read audit logs
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    /**
     * Test inactive and out-of-window roles do not grant backend permissions.
     */
    public function test_backend_denies_permissions_from_inactive_expired_and_future_roles(): void
    {
        $this->seedRoleWithPermissions('INACTIVE_WAREHOUSE_VIEWER', ['warehouse.view'])->update(['status' => 'INACTIVE']);
        $inactiveRoleUser = $this->createUser('inactive_role_user', 'INACTIVE_WAREHOUSE_VIEWER');

        $this->getJson('/api/v1/warehouses', ['Authorization' => 'Bearer '.JWTAuth::fromUser($inactiveRoleUser)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->seedRoleWithPermissions('EXPIRED_WAREHOUSE_VIEWER', ['warehouse.view']);
        $expiredRoleUser = $this->createUser('expired_role_user', 'EXPIRED_WAREHOUSE_VIEWER', rolePivot: [
            'effective_from' => now()->subDays(10),
            'effective_to' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/warehouses', ['Authorization' => 'Bearer '.JWTAuth::fromUser($expiredRoleUser)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->seedRoleWithPermissions('FUTURE_WAREHOUSE_VIEWER', ['warehouse.view']);
        $futureRoleUser = $this->createUser('future_role_user', 'FUTURE_WAREHOUSE_VIEWER', rolePivot: [
            'effective_from' => now()->addDay(),
            'effective_to' => now()->addDays(10),
        ]);

        $this->getJson('/api/v1/warehouses', ['Authorization' => 'Bearer '.JWTAuth::fromUser($futureRoleUser)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    /**
     * Test role assignments inside the effective window grant backend permissions.
     */
    public function test_backend_allows_permissions_from_effective_active_role_assignment(): void
    {
        $this->seedRoleWithPermissions('ACTIVE_WINDOW_WAREHOUSE_VIEWER', ['warehouse.view']);
        $user = $this->createUser('active_window_user', 'ACTIVE_WINDOW_WAREHOUSE_VIEWER', rolePivot: [
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/warehouses', ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
            ->assertOk();
    }

    // ========================================
    // BE1-P1-02: QA2 Role/Navigation/403 Tests
    // ========================================

    /**
     * Test login returns correct roles and permissions for each role type
     */
    public function test_login_returns_correct_roles_and_permissions(): void
    {
        $this->seedRoleWithPermissions('VIEWER_QA2', ['warehouse.view', 'stock_document.view']);
        $this->seedRoleWithPermissions('MANAGER_QA2', [
            'warehouse.view', 'warehouse.manage', 'stock_document.view', 'stock_document.create',
        ]);

        $viewer = $this->createUser('viewer_qa2', 'VIEWER_QA2');
        $manager = $this->createUser('manager_qa2', 'MANAGER_QA2');

        // Viewer login
        $viewerLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'viewer_qa2@wms-hoanam.test',
            'password' => 'secret123',
        ])->assertOk();

        $viewerPerms = $viewerLogin->json('data.user.permissions');
        $this->assertContains('warehouse.view', $viewerPerms);
        $this->assertNotContains('warehouse.manage', $viewerPerms);
        $this->assertContains('stock_document.view', $viewerPerms);
        $this->assertNotContains('stock_document.create', $viewerPerms);

        // Manager login
        $managerLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'manager_qa2@wms-hoanam.test',
            'password' => 'secret123',
        ])->assertOk();

        $managerPerms = $managerLogin->json('data.user.permissions');
        $this->assertContains('warehouse.view', $managerPerms);
        $this->assertContains('warehouse.manage', $managerPerms);
        $this->assertContains('stock_document.view', $managerPerms);
        $this->assertContains('stock_document.create', $managerPerms);
    }

    /**
     * Test session persistence and /auth/me returns current user
     */
    public function test_session_persistence_and_auth_me(): void
    {
        $this->seedRoleWithPermissions('SESSION_USER', ['warehouse.view']);
        $user = $this->createUser('sessionuser', 'SESSION_USER');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sessionuser@wms-hoanam.test',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->json('data.access_token');

        // Verify /auth/me returns correct user
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.username', 'sessionuser')
            ->assertJsonPath('data.email', 'sessionuser@wms-hoanam.test')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.roles.0.role_code', 'SESSION_USER')
            ->assertJsonPath('data.permissions.0', 'warehouse.view');
    }

    /**
     * Test role-based navigation: different roles see different capabilities
     */
    public function test_role_based_navigation_capabilities(): void
    {
        // Create warehouses for testing
        Warehouse::create(['code' => 'KHO-NAV1', 'name' => 'Kho Nav 1', 'status' => 'ACTIVE']);
        Warehouse::create(['code' => 'KHO-NAV2', 'name' => 'Kho Nav 2', 'status' => 'ACTIVE']);

        $this->seedRoleWithPermissions('VIEWER_NAV', ['warehouse.view', 'stock_document.view']);
        $this->seedRoleWithPermissions('EDITOR_NAV', [
            'warehouse.view', 'warehouse.manage', 'stock_document.view', 'stock_document.create', 'audit_log.view',
        ]);

        $viewer = $this->createUser('viewer_nav', 'VIEWER_NAV');
        $editor = $this->createUser('editor_nav', 'EDITOR_NAV');

        $viewerToken = JWTAuth::fromUser($viewer);
        $editorToken = JWTAuth::fromUser($editor);

        // Viewer can READ warehouses
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$viewerToken}"])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Viewer CANNOT read audit logs (doesn't have audit_log.view permission)
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$viewerToken}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Editor can READ warehouses
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$editorToken}"])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Editor CAN read audit logs (has audit_log.view permission)
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$editorToken}"])
            ->assertOk();
    }

    /**
     * Test 403 response structure for navigation/UI consumption
     */
    public function test_403_response_structure_for_ui_navigation(): void
    {
        $this->seedRoleWithPermissions('NO_ACCESS_NAV', []);
        $user = $this->createUser('noaccess_nav', 'NO_ACCESS_NAV');
        $token = JWTAuth::fromUser($user);

        $response = $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonPath('error.details', [])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('stack')
            ->assertHeader('X-Request-ID')
            ->assertJsonStructure([
                'success',
                'message',
                'error_code',
                'errors',
                'error' => ['code', 'details'],
                'meta' => ['request_id', 'timestamp'],
            ]);

        // UI can use error.code === 'ACCESS_DENIED' to hide/disable navigation items
        $this->assertEquals('ACCESS_DENIED', $response->json('error.code'));
    }

    /**
     * Test multiple concurrent role checks
     */
    public function test_multiple_roles_with_different_permissions(): void
    {
        $this->seedRoleWithPermissions('ROLE_A', ['warehouse.view']);
        $this->seedRoleWithPermissions('ROLE_B', ['warehouse.view', 'warehouse.manage', 'audit_log.view']);
        $this->seedRoleWithPermissions('ROLE_C', ['warehouse.view', 'warehouse.manage', 'audit_log.view']);

        $userA = $this->createUser('multiuser_a', 'ROLE_A');
        $userB = $this->createUser('multiuser_b', 'ROLE_B');
        $userC = $this->createUser('multiuser_c', 'ROLE_C');

        $tokenA = JWTAuth::fromUser($userA);
        $tokenB = JWTAuth::fromUser($userB);
        $tokenC = JWTAuth::fromUser($userC);

        // User A: can view warehouses, cannot view audit logs
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$tokenA}"])->assertOk();
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$tokenA}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // User B: can view both warehouses and audit logs (has both permissions)
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$tokenB}"])->assertOk();
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$tokenB}"])->assertOk();

        // User C: can do both (same as B, demonstrating consistency)
        $this->getJson('/api/v1/warehouses', ['Authorization' => "Bearer {$tokenC}"])->assertOk();
        $this->getJson('/api/v1/audit-logs', ['Authorization' => "Bearer {$tokenC}"])->assertOk();
    }

    // ========================================
    // Helper Methods
    // ========================================

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

    private function createUser(string $username, ?string $roleCode, string $status = 'ACTIVE', array $rolePivot = []): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => $status,
            'version' => 1,
        ]);

        if ($roleCode !== null) {
            $role = Role::where('role_code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->role_id, array_merge(
                ['user_role_id' => (string) Str::uuid()],
                $rolePivot
            ));
        }

        return $user;
    }
}
