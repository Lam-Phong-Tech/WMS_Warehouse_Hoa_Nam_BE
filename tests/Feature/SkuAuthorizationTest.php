<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Authorization regression coverage for the current SKU contract.
 *
 * Current contract:
 * - GET /api/v1/products and GET /api/v1/products/{id}: product.view
 * - POST /api/v1/products and PATCH /api/v1/products/{id}: product.manage
 * - SKU persistence model/table: Sku / sku
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

    public function test_products_list_requires_product_view_and_super_admin_bypasses(): void
    {
        $skuManager = $this->user('test-sku-manage@hoanam.com');
        $viewer = $this->user('staff01@gmail.com');
        $masterManager = $this->user('test-master-manage@hoanam.com');
        $noPermission = $this->user('test-no-permission@hoanam.com');
        $superAdmin = $this->user('test-super-admin@hoanam.com');

        $this->getJson('/api/v1/products', $this->auth($skuManager))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/products', $this->auth($viewer))
            ->assertOk()
            ->assertJsonPath('success', true);

        foreach ([$masterManager, $noPermission] as $user) {
            $this->getJson('/api/v1/products', $this->auth($user))
                ->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'ACCESS_DENIED')
                ->assertHeader('X-Request-ID');
        }

        $this->getJson('/api/v1/products', $this->auth($superAdmin))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_product_create_requires_product_manage(): void
    {
        $skuManager = $this->user('test-sku-manage@hoanam.com');
        $viewer = $this->user('staff01@gmail.com');
        $noPermission = $this->user('test-no-permission@hoanam.com');
        $superAdmin = $this->user('test-super-admin@hoanam.com');

        $this->postJson('/api/v1/products', [
            'sku_code' => 'TEST-PROD-001',
            'sku_name' => 'Test Product',
        ], $this->auth($skuManager))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku_code', 'TEST-PROD-001');

        foreach ([$viewer, $noPermission] as $user) {
            $this->postJson('/api/v1/products', [
                'sku_code' => 'DENIED-'.$user->username,
                'sku_name' => 'Denied Product',
            ], $this->auth($user))
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED')
                ->assertHeader('X-Request-ID');
        }

        $this->postJson('/api/v1/products', [
            'sku_code' => 'TEST-PROD-ADMIN',
            'sku_name' => 'Admin Product',
        ], $this->auth($superAdmin))
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_product_show_requires_product_view(): void
    {
        $sku = Sku::query()->create([
            'sku_code' => 'TEST-SHOW-001',
            'sku_name' => 'Test Show Product',
            'status' => 'ACTIVE',
        ]);

        $skuManager = $this->user('test-sku-manage@hoanam.com');
        $viewer = $this->user('staff01@gmail.com');
        $noPermission = $this->user('test-no-permission@hoanam.com');
        $superAdmin = $this->user('test-super-admin@hoanam.com');

        foreach ([$skuManager, $viewer, $superAdmin] as $user) {
            $this->getJson("/api/v1/products/{$sku->sku_id}", $this->auth($user))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.sku_code', 'TEST-SHOW-001');
        }

        $this->getJson("/api/v1/products/{$sku->sku_id}", $this->auth($noPermission))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertHeader('X-Request-ID');
    }

    public function test_product_update_requires_product_manage_and_replaces_legacy_enable_disable_routes(): void
    {
        $sku = Sku::query()->create([
            'sku_code' => 'TEST-UPDATE-001',
            'sku_name' => 'Before Update',
            'status' => 'INACTIVE',
        ]);

        $skuManager = $this->user('test-sku-manage@hoanam.com');
        $viewer = $this->user('staff01@gmail.com');
        $noPermission = $this->user('test-no-permission@hoanam.com');
        $superAdmin = $this->user('test-super-admin@hoanam.com');

        $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'sku_name' => 'After Update',
            'status' => 'ACTIVE',
        ], $this->auth($skuManager))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE');

        foreach ([$viewer, $noPermission] as $user) {
            $this->patchJson("/api/v1/products/{$sku->sku_id}", [
                'status' => 'INACTIVE',
            ], $this->auth($user))
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ACCESS_DENIED')
                ->assertHeader('X-Request-ID');
        }

        $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'status' => 'INACTIVE',
        ], $this->auth($superAdmin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'INACTIVE');

        $this->assertDatabaseHas('sku', [
            'sku_id' => $sku->sku_id,
            'sku_name' => 'After Update',
            'status' => 'INACTIVE',
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return array<string, string> */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }
}
