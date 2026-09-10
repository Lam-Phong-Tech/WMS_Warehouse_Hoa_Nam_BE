<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\DongChengAnchorSkus;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

class FoundationMasterDataEpicTest extends TestCase
{
    use RefreshDatabase;

    public function test_pl01_03_seeded_auth_rbac_and_master_data_contract_are_available(): void
    {
        $this->seed(DatabaseSeeder::class);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@gmail.com',
            'password' => TestCredentials::PASSWORD,
        ]);

        $login->assertOk()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.roles.0.role_code', 'SUPER_ADMIN')
            ->assertJsonStructure([
                'data' => ['access_token', 'expires_in', 'user' => ['roles', 'permissions']],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertContains('audit_log.view', $login->json('data.user.permissions'));

        $headers = ['Authorization' => 'Bearer '.$login->json('data.access_token')];

        $this->getJson('/api/v1/warehouses?keyword=KHO-TONG-HN', $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', 'KHO-TONG-HN')
            ->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'timestamp']]);

        $this->getJson('/api/v1/products?keyword='.DongChengAnchorSkus::BASELINE_PRODUCT, $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', DongChengAnchorSkus::BASELINE_PRODUCT)
            ->assertJsonPath('data.0.sku_code', DongChengAnchorSkus::BASELINE_PRODUCT)
            ->assertJsonPath('data.0.published_to_app', true);
    }

    public function test_master_data_create_requires_permission_and_records_audit_evidence(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->seedRoleWithPermissions('MASTER_DATA_CREATOR', [
            'warehouse.view',
            'warehouse.manage',
            'sku.view',
            'sku.manage',
            'audit_log.view',
        ]);
        $this->seedRoleWithPermissions('MASTER_DATA_VIEWER', [
            'warehouse.view',
            'sku.view',
        ]);

        $creator = $this->createUser('md-creator', 'MASTER_DATA_CREATOR');
        $viewer = $this->createUser('md-viewer', 'MASTER_DATA_VIEWER');

        $viewerToken = JWTAuth::fromUser($viewer);

        $warehouse = Warehouse::where('code', 'KHO-TONG-HN')->firstOrFail();

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Kho khong co quyen sua',
        ], ['Authorization' => "Bearer {$viewerToken}"])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        auth('api')->forgetUser();
        $this->flushHeaders();

        $creatorToken = JWTAuth::fromUser($creator);
        $requestId = 'PL01-03-EVIDENCE-REQ-001';

        $warehouseResponse = $this->patchJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Kho PL01-03',
            'address' => 'DEV/UAT baseline evidence',
        ], [
            'Authorization' => "Bearer {$creatorToken}",
            'X-Request-ID' => $requestId,
        ]);

        $warehouseResponse->assertOk()
            ->assertHeader('X-Request-ID', $requestId)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'KHO-TONG-HN')
            ->assertJsonPath('meta.request_id', $requestId);

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $creator->user_id,
            'action' => 'UPDATE',
            'object_type' => 'warehouse',
            'object_id' => $warehouseResponse->json('data.id'),
            'request_id' => $requestId,
        ]);

        // SEC-09 (PvPublishGuard): tạo SKU kèm `published_to_app=true` là đưa nó lên App PV,
        // việc đó cần `pv.publish` — `sku.manage` thôi thì bị từ chối và không để lại SKU nào.
        $productPayload = [
            'code' => 'HN-PL01-03-SKU',
            'name' => 'SKU PL01-03',
            'unit' => 'cai',
            'sku_type' => 'PRODUCT',
            'published_to_app' => true,
            'is_serial_tracked' => true,
        ];

        $this->postJson('/api/v1/products', $productPayload, [
            'Authorization' => "Bearer {$creatorToken}",
            'X-Request-ID' => 'PL01-03-EVIDENCE-REQ-002-DENIED',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertSame(0, Product::where('code', 'HN-PL01-03-SKU')->count());

        $this->seedRoleWithPermissions('MASTER_DATA_PUBLISHER', [
            'sku.view',
            'sku.manage',
            'pv.publish',
        ]);
        $publisher = $this->createUser('md-publisher', 'MASTER_DATA_PUBLISHER');

        auth('api')->forgetUser();
        $this->flushHeaders();

        $product = $this->postJson('/api/v1/products', $productPayload, [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($publisher),
            'X-Request-ID' => 'PL01-03-EVIDENCE-REQ-002',
        ]);

        $product->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'HN-PL01-03-SKU')
            ->assertJsonPath('data.sku_code', 'HN-PL01-03-SKU');

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $publisher->user_id,
            'action' => 'CREATE',
            'object_type' => 'sku',
            'object_id' => $product->json('data.id'),
            'request_id' => 'PL01-03-EVIDENCE-REQ-002',
        ]);
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

    private function createUser(string $username, string $roleCode): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $role = Role::where('role_code', $roleCode)->firstOrFail();
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user;
    }
}
