<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * P0-1: Backend Authorization Test Suite
 * 
 * 36 test cases (9 endpoints × 4 users):
 * - User A: test-sku-manage@hoanam.com - có sku.manage
 * - User B: test-master-manage@hoanam.com - có master.manage
 * - User C: test-no-permission@hoanam.com - không có quyền
 * - User D: test-super-admin@hoanam.com - super admin
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Modules\Auth\Database\Seeders\AuthDatabaseSeeder::class);
        Cache::flush();
    }

    /**
     * Test 9 endpoints with sku.manage permission
     */
    public function test_sku_manage_endpoints_with_different_users(): void
    {
        DB::table('sku')->insert([
            'sku_id' => '00000000-0000-0000-0000-000000000001',
            'sku_code' => 'DUMMY-AUTH-SKU',
            'sku_name' => 'Dummy Auth SKU',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test users
        $skuManager = $this->createTestUser('test-sku-manage', 'SKU_MANAGER');
        $masterManager = $this->createTestUser('test-master-manage', 'MASTER_MANAGER');
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');

        $skuManagerToken = JWTAuth::fromUser($skuManager);
        $masterManagerToken = JWTAuth::fromUser($masterManager);
        $noPermissionToken = JWTAuth::fromUser($noPermission);
        $superAdminToken = JWTAuth::fromUser($superAdmin);

        // 9 endpoints requiring sku.manage permission
        $skuEndpoints = [
            ['GET', '/api/v1/products'],
            ['POST', '/api/v1/products'],
            ['GET', '/api/v1/products/00000000-0000-0000-0000-000000000001'],
            ['PATCH', '/api/v1/products/00000000-0000-0000-0000-000000000001/enable'],
            ['PATCH', '/api/v1/products/00000000-0000-0000-0000-000000000001/disable'],
            ['GET', '/api/v1/skus'],
            ['POST', '/api/v1/skus'],
            ['GET', '/api/v1/skus/00000000-0000-0000-0000-000000000001'],
        ];

        foreach ($skuEndpoints as [$method, $url]) {
            // User with sku.manage should have access
            $response = $this->makeRequest($method, $url, $skuManagerToken);
            $this->assertTrue(in_array($response->getStatusCode(), [200, 201], true), "Failed asserting that {$response->getStatusCode()} is identical to 200 or 201.");

            // User with master.manage should be denied (doesn't have sku.manage)
            $this->makeRequest($method, $url, $masterManagerToken)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            // User with no permission should be denied
            $this->makeRequest($method, $url, $noPermissionToken)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            // Super admin should have access (bypass)
            $response = $this->makeRequest($method, $url, $superAdminToken);
            $this->assertTrue(in_array($response->getStatusCode(), [200, 201], true), "Failed asserting that {$response->getStatusCode()} is identical to 200 or 201.");
        }
    }

    /**
     * Test 9 endpoints with master.manage permission
     */
    public function test_master_manage_endpoints_with_different_users(): void
    {
        // Create test users
        $skuManager = $this->createTestUser('test-sku-manage', 'SKU_MANAGER');
        $masterManager = $this->createTestUser('test-master-manage', 'MASTER_MANAGER');
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');

        $skuManagerToken = JWTAuth::fromUser($skuManager);
        $masterManagerToken = JWTAuth::fromUser($masterManager);
        $noPermissionToken = JWTAuth::fromUser($noPermission);
        $superAdminToken = JWTAuth::fromUser($superAdmin);

        // 9 endpoints requiring master.manage permission
        $masterEndpoints = [
            ['GET', '/api/v1/product-categories'],
            ['GET', '/api/v1/uoms'],
            ['GET', '/api/v1/suppliers'],
        ];

        foreach ($masterEndpoints as [$method, $url]) {
            // User with sku.manage should be denied (doesn't have master.manage)
            $this->makeRequest($method, $url, $skuManagerToken)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            // User with master.manage should have access
            $this->makeRequest($method, $url, $masterManagerToken)
                ->assertStatus(200);

            // User with no permission should be denied
            $this->makeRequest($method, $url, $noPermissionToken)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            // Super admin should have access (bypass)
            $this->makeRequest($method, $url, $superAdminToken)
                ->assertStatus(200);
        }
    }

    /**
     * Test 403 response format is correct
     */
    public function test_403_response_format_is_correct(): void
    {
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $token = JWTAuth::fromUser($noPermission);

        $response = $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"]);

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
    }

    /**
     * Test SUPER_ADMIN bypasses all permission checks
     */
    public function test_super_admin_bypasses_sku_and_master_permissions(): void
    {
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');
        $token = JWTAuth::fromUser($superAdmin);

        // SUPER_ADMIN should access sku.manage endpoints
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);

        // SUPER_ADMIN should access master.manage endpoints
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);
    }

    /**
     * Test user with no permission gets 403 on all protected endpoints
     */
    public function test_user_with_no_permission_gets_403_on_all_endpoints(): void
    {
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $token = JWTAuth::fromUser($noPermission);

        $protectedEndpoints = [
            '/api/v1/products',
            '/api/v1/product-categories',
            '/api/v1/uoms',
            '/api/v1/suppliers',
            '/api/v1/skus',
        ];

        foreach ($protectedEndpoints as $url) {
            $this->getJson($url, ['Authorization' => "Bearer {$token}"])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');
        }
    }

    /**
     * Helper method to make HTTP requests
     */
    private function makeRequest(string $method, string $url, string $token)
    {
        $headers = ['Authorization' => "Bearer {$token}"];
        
        return match ($method) {
            'GET' => $this->getJson($url, $headers),
            'POST' => $this->postJson($url, [
                'sku_code' => 'TEST-AUTH-POST-' . Str::random(5),
                'sku_name' => 'Test Auth Product',
            ], $headers),
            'PATCH' => $this->patchJson($url, [], $headers),
            default => $this->call($method, $url, [], [], [], $headers),
        };
    }

    /**
     * Helper method to create test users with specific roles
     */
    private function createTestUser(string $username, string $roleCode): User
    {
        $role = Role::where('role_code', $roleCode)->firstOrFail();

        $user = User::where('username', $username)->first();

        if (! $user) {
            $user = User::create([
                'username' => $username,
                'display_name' => ucwords(str_replace('-', ' ', $username)),
                'email' => "{$username}@hoanam.com",
                'password_hash' => \Illuminate\Support\Facades\Hash::make('Cntt@1234'),
                'status' => 'ACTIVE',
                'version' => 1,
            ]);

            $user->roles()->attach($role->role_id, ['user_role_id' => (string) \Illuminate\Support\Str::uuid()]);
        }

        return $user;
    }
}