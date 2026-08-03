<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Product;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class Wwhn172SkuContainerTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        $this->user = User::where('email', 'test-super-admin@hoanam.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($this->user);
    }

    public function test_sku_aggregate_create_is_atomic_audited_and_replay_safe(): void
    {
        $component = Product::query()->create([
            'code' => 'COMP-001',
            'sku_code' => 'COMP-001',
            'name' => 'Component 001',
            'sku_type' => 'COMPONENT',
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $payload = [
            'code' => 'sku-kit-001',
            'name' => 'SKU kit 001',
            'sku_type' => 'PRODUCT',
            'components' => [[
                'component_id' => $component->id,
                'quantity' => 2.5,
            ]],
            'physical_codes' => [[
                'code_value' => 'hn-kit-001',
                'code_type' => 'BARCODE',
                'carrier_type' => 'UNIT',
            ]],
        ];

        $first = $this->postJson('/api/v1/skus', $payload, $this->headers('sku-create-001'));
        $first->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'SKU-KIT-001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(1, 'data.components')
            ->assertJsonCount(1, 'data.physical_codes')
            ->assertHeader('Idempotent-Replay', 'false');

        $skuId = (string) $first->json('data.id');

        $second = $this->postJson('/api/v1/skus', $payload, $this->headers('sku-create-001'));
        $second->assertCreated()
            ->assertJsonPath('data.id', $skuId)
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('sku_component', [
            'parent_product_id' => $skuId,
            'component_product_id' => $component->id,
        ]);
        $this->assertDatabaseHas('physical_code', [
            'product_id' => $skuId,
            'code_value' => 'HN-KIT-001',
            'assigned_object_type' => 'SKU',
            'status' => 'ACTIVE',
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_CREATE')->where('object_id', $skuId)->count());
    }

    public function test_same_idempotency_key_with_different_payload_returns_409_without_side_effect(): void
    {
        $headers = $this->headers('sku-create-conflict');

        $this->postJson('/api/v1/skus', [
            'code' => 'SKU-IDEM-001',
            'name' => 'Original',
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/skus', [
            'code' => 'SKU-IDEM-002',
            'name' => 'Different payload',
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_ERROR');

        $this->assertDatabaseMissing('products', ['code' => 'SKU-IDEM-002']);
    }

    public function test_component_cycle_rolls_back_edge_version_and_audit(): void
    {
        $a = $this->product('SKU-A');
        $b = $this->product('SKU-B');

        $this->patchJson("/api/v1/skus/{$a->id}", [
            'version' => 1,
            'components' => [[
                'component_id' => $b->id,
                'quantity' => 1,
            ]],
        ], $this->headers('sku-a-add-b'))
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/skus/{$b->id}", [
            'version' => 1,
            'components' => [[
                'component_id' => $a->id,
                'quantity' => 1,
            ]],
        ], $this->headers('sku-b-add-a'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_COMPONENT_CYCLE');

        $this->assertDatabaseMissing('sku_component', [
            'parent_product_id' => $b->id,
            'component_product_id' => $a->id,
        ]);
        $this->assertSame(1, (int) Product::query()->findOrFail($b->id)->version);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_UPDATE')->count());
    }

    public function test_stale_sku_version_is_rejected_before_mutation(): void
    {
        $sku = $this->product('SKU-VERSION');

        $this->patchJson("/api/v1/skus/{$sku->id}", [
            'version' => 1,
            'name' => 'Version 2 name',
        ], $this->headers('sku-version-1'))
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/skus/{$sku->id}", [
            'version' => 1,
            'name' => 'Stale overwrite',
        ], $this->headers('sku-version-stale'))
            ->assertConflict()
            ->assertJsonPath('error.code', 'SKU_VERSION_CONFLICT');

        $sku->refresh();
        $this->assertSame('Version 2 name', $sku->name);
        $this->assertSame(2, (int) $sku->version);
    }

    public function test_container_create_replay_seal_and_immutable_guard(): void
    {
        $product = $this->product('SKU-CONTAINER');
        $payload = [
            'container_code' => 'ctn-001',
            'container_type' => 'BOX',
            'primary_product_id' => $product->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
            ]],
        ];

        $first = $this->postJson('/api/v1/containers', $payload, $this->headers('container-create-001'));
        $first->assertCreated()
            ->assertJsonPath('data.container_code', 'CTN-001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(1, 'data.items');
        $containerId = (string) $first->json('data.container_id');

        $this->postJson('/api/v1/containers', $payload, $this->headers('container-create-001'))
            ->assertCreated()
            ->assertJsonPath('data.container_id', $containerId)
            ->assertHeader('Idempotent-Replay', 'true');

        $this->postJson(
            "/api/v1/containers/{$containerId}/seal",
            ['version' => 1],
            $this->headers('container-seal-001')
        )->assertOk()
            ->assertJsonPath('data.status', 'SEALED')
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/containers/{$containerId}", [
            'version' => 2,
            'location_hint' => 'Must not change',
        ], $this->headers('container-update-sealed'))
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONTAINER_IMMUTABLE');

        $this->assertDatabaseMissing('container', [
            'container_id' => $containerId,
            'location_hint' => 'Must not change',
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'CONTAINER_CREATE')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'CONTAINER_SEAL')->count());
    }

    public function test_failed_container_create_rolls_back_root_items_and_audit(): void
    {
        $product = $this->product('SKU-ROLLBACK');

        $this->postJson('/api/v1/containers', [
            'container_code' => 'CTN-ROLLBACK',
            'container_type' => 'BOX',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $this->headers('container-rollback'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DUPLICATE_CONTAINER_ITEM');

        $this->assertDatabaseMissing('container', ['container_code' => 'CTN-ROLLBACK']);
        $this->assertDatabaseMissing('audit_log', ['action' => 'CONTAINER_CREATE']);
    }

    private function product(string $code): Product
    {
        return Product::query()->create([
            'code' => $code,
            'sku_code' => $code,
            'name' => $code,
            'sku_type' => 'PRODUCT',
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    private function headers(string $idempotencyKey): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ];
    }
}
