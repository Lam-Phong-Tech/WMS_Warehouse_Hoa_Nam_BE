<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * P0-1: Backend Authorization Test Suite for SKU/Product Endpoints
 * 
 * Covers 9 endpoints × 4 users = 36 test cases:
 * - User A: test-sku-manage@hoanam.com (sku.manage permission)
 * - User B: test-master-manage@hoanam.com (master.manage permission)
 * - User C: test-no-permission@hoanam.com (no permissions)
 * - User D: test-super-admin@hoanam.com (SUPER_ADMIN - bypass all)
 * 
 * Endpoints tested:
 * 1. GET /api/v1/products (list)
 * 2. POST /api/v1/products (create)
 * 3. GET /api/v1/products/{id} (show)
 * 4. PATCH /api/v1/products/{id}/enable
 * 5. PATCH /api/v1/products/{id}/disable
 * 6. GET /api/v1/skus (list alias)
 * 7. POST /api/v1/skus (create alias)
 * 8. GET /api/v1/skus/{id} (show alias)
 * 9. GET /api/v1/product-categories (master data - requires master.manage)
 */
class SkuAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
    }

    // ========================================
    // Test Users Setup (from AuthDatabaseSeeder)
    // ========================================

    /**
     * User A: test-sku-manage@hoanam.com - has sku.manage permission
     */
    private function getSkuManageUser(): User
    {
        return User::where('email', 'test-sku-manage@hoanam.com')->firstOrFail();
    }

    /**
     * User B: test-master-manage@hoanam.com - has master.manage permission
     */
    private function getMasterManageUser(): User
    {
        return User::where('email', 'test-master-manage@hoanam.com')->firstOrFail();
    }

    /**
     * User C: test-no-permission@hoanam.com - no permissions
     */
    private function getNoPermissionUser(): User
    {
        return User::where('email', 'test-no-permission@hoanam.com')->firstOrFail();
    }

    /**
     * User D: test-super-admin@hoanam.com - SUPER_ADMIN role
     */
    private function getSuperAdminUser(): User
    {
        return User::where('email', 'test-super-admin@hoanam.com')->firstOrFail();
    }

    // ========================================
    // 9 Endpoints × 4 Users = 36 Test Cases
    // ========================================

    /**
     * Test 1: GET /api/v1/products (list)
     * Permission required: sku.manage
     */
    public function test_get_products_list_authorization(): void
    {
        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true);

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 2: POST /api/v1/products (create)
     * Permission required: sku.manage
     */
    public function test_post_products_create_authorization(): void
    {
        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->postJson('/api/v1/products', [
            'code' => 'TEST-PROD-001',
            'name' => 'Test Product',
        ], ['Authorization' => "Bearer {$tokenA}"])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->postJson('/api/v1/products', [
            'code' => 'TEST-PROD-002',
            'name' => 'Test Product 2',
        ], ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->postJson('/api/v1/products', [
            'code' => 'TEST-PROD-003',
            'name' => 'Test Product 3',
        ], ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->postJson('/api/v1/products', [
            'code' => 'TEST-PROD-004',
            'name' => 'Test Product 4',
        ], ['Authorization' => "Bearer {$tokenD}"])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * Test 3: GET /api/v1/products/{id} (show)
     * Permission required: sku.manage
     */
    public function test_get_products_show_authorization(): void
    {
        // Create a product first
        $product = \Modules\Warehouse\Models\Product::create([
            'code' => 'TEST-SHOW-001',
            'name' => 'Test Show Product',
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->getJson("/api/v1/products/{$product->id}", ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'TEST-SHOW-001');

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->getJson("/api/v1/products/{$product->id}", ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->getJson("/api/v1/products/{$product->id}", ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->getJson("/api/v1/products/{$product->id}", ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 4: PATCH /api/v1/products/{id}/enable
     * Permission required: sku.manage
     */
    public function test_patch_products_enable_authorization(): void
    {
        // Create a product first
        $product = \Modules\Warehouse\Models\Product::create([
            'code' => 'TEST-ENABLE-001',
            'name' => 'Test Enable Product',
            'status' => 'INACTIVE',
            'version' => 1,
        ]);

        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->patchJson("/api/v1/products/{$product->id}/enable", [], ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE');

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->patchJson("/api/v1/products/{$product->id}/disable", [], ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->patchJson("/api/v1/products/{$product->id}/enable", [], ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->patchJson("/api/v1/products/{$product->id}/disable", [], ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 5: PATCH /api/v1/products/{id}/disable
     * Permission required: sku.manage
     */
    public function test_patch_products_disable_authorization(): void
    {
        // Create a product first
        $product = \Modules\Warehouse\Models\Product::create([
            'code' => 'TEST-DISABLE-001',
            'name' => 'Test Disable Product',
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->patchJson("/api/v1/products/{$product->id}/disable", [], ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'INACTIVE');

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->patchJson("/api/v1/products/{$product->id}/enable", [], ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->patchJson("/api/v1/products/{$product->id}/disable", [], ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->patchJson("/api/v1/products/{$product->id}/enable", [], ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 6: GET /api/v1/skus (list alias)
     * Permission required: sku.manage
     */
    public function test_get_skus_list_authorization(): void
    {
        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->getJson('/api/v1/skus', ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true);

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->getJson('/api/v1/skus', ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->getJson('/api/v1/skus', ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->getJson('/api/v1/skus', ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 7: POST /api/v1/skus (create alias)
     * Permission required: sku.manage
     */
    public function test_post_skus_create_authorization(): void
    {
        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->postJson('/api/v1/skus', [
            'code' => 'TEST-SKU-001',
            'name' => 'Test SKU',
        ], ['Authorization' => "Bearer {$tokenA}"])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->postJson('/api/v1/skus', [
            'code' => 'TEST-SKU-002',
            'name' => 'Test SKU 2',
        ], ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->postJson('/api/v1/skus', [
            'code' => 'TEST-SKU-003',
            'name' => 'Test SKU 3',
        ], ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->postJson('/api/v1/skus', [
            'code' => 'TEST-SKU-004',
            'name' => 'Test SKU 4',
        ], ['Authorization' => "Bearer {$tokenD}"])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * Test 8: GET /api/v1/skus/{id} (show alias)
     * Permission required: sku.manage
     */
    public function test_get_skus_show_authorization(): void
    {
        // Create a product first
        $product = \Modules\Warehouse\Models\Product::create([
            'code' => 'TEST-SKU-SHOW-001',
            'name' => 'Test SKU Show',
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        // User A (sku.manage) - should succeed
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->getJson("/api/v1/skus/{$product->id}", ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'TEST-SKU-SHOW-001');

        // User B (master.manage only) - should fail with 403
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->getJson("/api/v1/skus/{$product->id}", ['Authorization' => "Bearer {$tokenB}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->getJson("/api/v1/skus/{$product->id}", ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->getJson("/api/v1/skus/{$product->id}", ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Test 9: GET /api/v1/product-categories (master data)
     * Permission required: master.manage
     */
    public function test_get_product_categories_authorization(): void
    {
        // User A (sku.manage only) - should fail with 403
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$tokenA}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User B (master.manage) - should succeed
        $userB = $this->getMasterManageUser();
        $tokenB = JWTAuth::fromUser($userB);
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$tokenB}"])
            ->assertOk()
            ->assertJsonPath('success', true);

        // User C (no permissions) - should fail with 403
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$tokenC}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');

        // User D (SUPER_ADMIN) - should succeed (bypass)
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$tokenD}"])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ========================================
    // 403 Response Format Validation
    // ========================================

    /**
     * Test 403 response structure matches standard API envelope
     */
    public function test_403_response_format_is_standard(): void
    {
        $userC = $this->getNoPermissionUser();
        $tokenC = JWTAuth::fromUser($userC);

        $response = $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenC}"]);

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

        // Verify error_code matches error.code
        $this->assertEquals(
            $response->json('error_code'),
            $response->json('error.code')
        );
    }

    /**
     * Test 401 response for unauthenticated requests
     */
    public function test_401_for_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/products')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED')
            ->assertHeader('X-Request-ID');
    }

    /**
     * Test SUPER_ADMIN bypasses all permission checks
     */
    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $userD = $this->getSuperAdminUser();
        $tokenD = JWTAuth::fromUser($userD);

        // All these endpoints require different permissions, but SUPER_ADMIN should access all
        $endpoints = [
            '/api/v1/products',
            '/api/v1/skus',
            '/api/v1/product-categories',
            '/api/v1/uoms',
            '/api/v1/suppliers',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint, ['Authorization' => "Bearer {$tokenD}"])
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    /**
     * Test permission check is exact match (no wildcard)
     */
    public function test_permission_check_is_exact_match(): void
    {
        $userA = $this->getSkuManageUser();
        $tokenA = JWTAuth::fromUser($userA);

        // Has sku.manage - can access products
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk();

        // Does NOT have master.manage - cannot access product-categories
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$tokenA}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');
    }
}
