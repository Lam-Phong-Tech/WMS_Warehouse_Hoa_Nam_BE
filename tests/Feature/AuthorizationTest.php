<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * P0-1: Backend Authorization Test Suite.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
    }

    public function test_sku_manage_endpoints_with_different_users(): void
    {
        $skuManager = $this->createTestUser('test-sku-manage', 'SKU_MANAGER');
        $masterManager = $this->createTestUser('test-master-manage', 'MASTER_MANAGER');
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');

        $skuManagerToken = JWTAuth::fromUser($skuManager);
        $masterManagerToken = JWTAuth::fromUser($masterManager);
        $noPermissionToken = JWTAuth::fromUser($noPermission);
        $superAdminToken = JWTAuth::fromUser($superAdmin);

        $product = Sku::create([
            'code' => 'AUTH-SKU-PRODUCT',
            'name' => 'Authorization SKU Product',
            'unit' => 'cai',
            'sku_type' => 'PRODUCT',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $skuEndpoints = [
            ['GET', '/api/v1/products', [], 200],
            ['POST', '/api/v1/products', ['code' => 'AUTH-PRODUCT-CREATE', 'name' => 'Authorization Product Create'], 201],
            ['GET', "/api/v1/products/{$product->id}", [], 200],
            ['PATCH', "/api/v1/products/{$product->id}/enable", [], 200],
            ['PATCH', "/api/v1/products/{$product->id}/disable", [], 200],
            ['GET', '/api/v1/skus', [], 200],
            ['POST', '/api/v1/skus', ['code' => 'AUTH-SKU-CREATE', 'name' => 'Authorization SKU Create'], 201],
            ['GET', "/api/v1/skus/{$product->id}", [], 200],
        ];

        foreach ($skuEndpoints as [$method, $url, $payload, $successStatus]) {
            $this->makeRequest($method, $url, $skuManagerToken, $payload)
                ->assertStatus($successStatus);

            $this->makeRequest($method, $url, $masterManagerToken, $payload)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            $this->makeRequest($method, $url, $noPermissionToken, $payload)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            $superAdminPayload = $method === 'POST'
                ? array_replace($payload, ['code' => $payload['code'].'-SA'])
                : $payload;

            $this->makeRequest($method, $url, $superAdminToken, $superAdminPayload)
                ->assertStatus($successStatus);
        }
    }

    public function test_master_view_endpoints_follow_catalog_view_permission(): void
    {
        $skuManager = $this->createTestUser('test-sku-manage', 'SKU_MANAGER');
        $masterManager = $this->createTestUser('test-master-manage', 'MASTER_MANAGER');
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');

        $skuManagerToken = JWTAuth::fromUser($skuManager);
        $masterManagerToken = JWTAuth::fromUser($masterManager);
        $noPermissionToken = JWTAuth::fromUser($noPermission);
        $superAdminToken = JWTAuth::fromUser($superAdmin);

        // Keep this list aligned with the active current-baseline master routes.
        // UOM/supplier endpoints are superseded and must not be reintroduced to
        // make a legacy authorization test pass.
        $masterEndpoints = [
            ['GET', '/api/v1/brands'],
            ['GET', '/api/v1/product-categories'],
            ['GET', '/api/v1/power-sources'],
            ['GET', '/api/v1/product-models'],
            ['GET', '/api/v1/package-variants'],
        ];

        foreach ($masterEndpoints as [$method, $url]) {
            // Both test roles intentionally carry catalog.view. Read endpoints
            // must therefore succeed for both; only catalog.manage may mutate.
            $this->makeRequest($method, $url, $skuManagerToken)
                ->assertStatus(200);

            $this->makeRequest($method, $url, $masterManagerToken)
                ->assertStatus(200);

            $this->makeRequest($method, $url, $noPermissionToken)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');

            $this->makeRequest($method, $url, $superAdminToken)
                ->assertStatus(200);
        }
    }

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

    public function test_super_admin_bypasses_sku_and_master_permissions(): void
    {
        $superAdmin = $this->createTestUser('test-super-admin', 'SUPER_ADMIN');
        $token = JWTAuth::fromUser($superAdmin);

        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);
        $this->getJson('/api/v1/product-categories', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);
    }

    public function test_user_with_no_permission_gets_403_on_all_endpoints(): void
    {
        $noPermission = $this->createTestUser('test-no-permission', 'NO_PERMISSION');
        $token = JWTAuth::fromUser($noPermission);

        foreach ([
            '/api/v1/products',
            '/api/v1/skus',
            '/api/v1/brands',
            '/api/v1/product-categories',
            '/api/v1/power-sources',
            '/api/v1/product-models',
            '/api/v1/package-variants',
        ] as $url) {
            $this->getJson($url, ['Authorization' => "Bearer {$token}"])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED');
        }
    }

    private function makeRequest(string $method, string $url, string $token, array $payload = [])
    {
        $headers = ['Authorization' => "Bearer {$token}"];
        if ($method === 'POST') {
            // POST /skus is intentionally idempotent. Use a deterministic valid
            // key so authorization is tested after precondition validation.
            $headers['Idempotency-Key'] = 'auth-'.substr(hash(
                'sha256',
                $url.'|'.$token.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE)
            ), 0, 32);
        }

        return match ($method) {
            'GET' => $this->getJson($url, $headers),
            'POST' => $this->postJson($url, $payload, $headers),
            'PATCH' => $this->patchJson($url, $payload, $headers),
            default => $this->call($method, $url, [], [], [], $headers),
        };
    }

    private function createTestUser(string $username, string $roleCode): User
    {
        $role = Role::where('role_code', $roleCode)->firstOrFail();

        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'display_name' => ucwords(str_replace('-', ' ', $username)),
                'email' => "{$username}@hoanam.com",
                'password_hash' => Hash::make('Cntt@1234'),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );

        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }
}
