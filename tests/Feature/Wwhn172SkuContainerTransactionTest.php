<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
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
        $component = Sku::query()->create([
            'sku_code' => 'COMP-001',
            'sku_name' => 'Component 001',
            'sku_type' => 'COMPONENT',
            'status' => 'ACTIVE',
            'version_code' => 'V1',
        ]);

        $payload = [
            'code' => 'wwhn172-kit-001',
            'name' => 'SKU kit 001',
            'sku_type' => 'PRODUCT',
            'components' => [[
                'component_id' => $component->sku_id,
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
            ->assertJsonPath('data.code', 'WWHN172-KIT-001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(1, 'data.components')
            ->assertJsonCount(1, 'data.physical_codes')
            ->assertHeader('Idempotent-Replay', 'false');

        $skuId = (string) $first->json('data.id');

        $this->postJson('/api/v1/skus', $payload, $this->headers('sku-create-001'))
            ->assertCreated()
            ->assertJsonPath('data.id', $skuId)
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertDatabaseCount('sku', 2);
        $this->assertDatabaseHas('sku_component', [
            'parent_product_id' => $skuId,
            'component_product_id' => $component->sku_id,
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

        $this->postJson('/api/v1/skus', ['code' => 'SKU-IDEM-001', 'name' => 'Original'], $headers)
            ->assertCreated();

        $this->postJson('/api/v1/skus', ['code' => 'SKU-IDEM-002', 'name' => 'Different payload'], $headers)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_ERROR');

        $this->assertDatabaseMissing('sku', ['sku_code' => 'SKU-IDEM-002']);
    }

    public function test_component_cycle_rolls_back_edge_version_and_audit(): void
    {
        $a = $this->sku('SKU-A');
        $b = $this->sku('SKU-B');

        $this->patchJson("/api/v1/skus/{$a->sku_id}", [
            'version' => 1,
            'components' => [['component_id' => $b->sku_id, 'quantity' => 1]],
        ], $this->headers('sku-a-add-b'))
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/skus/{$b->sku_id}", [
            'version' => 1,
            'components' => [['component_id' => $a->sku_id, 'quantity' => 1]],
        ], $this->headers('sku-b-add-a'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_COMPONENT_CYCLE');

        $this->assertDatabaseMissing('sku_component', [
            'parent_product_id' => $b->sku_id,
            'component_product_id' => $a->sku_id,
        ]);
        $this->assertSame('V1', Sku::query()->findOrFail($b->sku_id)->version_code);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_UPDATE')->count());
    }

    public function test_stale_sku_version_is_rejected_before_mutation(): void
    {
        $sku = $this->sku('SKU-VERSION');

        $this->patchJson("/api/v1/skus/{$sku->sku_id}", [
            'version' => 1,
            'name' => 'Version 2 name',
        ], $this->headers('sku-version-1'))
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/skus/{$sku->sku_id}", [
            'version' => 1,
            'name' => 'Stale overwrite',
        ], $this->headers('sku-version-stale'))
            ->assertConflict()
            ->assertJsonPath('error.code', 'SKU_VERSION_CONFLICT');

        $sku->refresh();
        $this->assertSame('Version 2 name', $sku->sku_name);
        $this->assertSame('V2', $sku->version_code);
    }

    public function test_container_contract_uses_canonical_sku_and_plural_tables(): void
    {
        $sku = $this->sku('SKU-CONTAINER');
        $code = PhysicalCode::query()->create([
            'code_value' => 'CTN-001',
            'code_type' => 'BOX',
            'carrier_type' => 'BARCODE',
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/containers', [
            'container_code' => 'CTN-001',
            'container_type' => 'BOX',
            'physical_code_id' => $code->physical_code_id,
            'primary_sku_id' => $sku->sku_id,
            'status' => 'ACTIVE',
        ], $this->authHeaders());

        $create->assertCreated()
            ->assertJsonPath('data.container_code', 'CTN-001')
            ->assertJsonPath('data.version', 1);

        $containerId = (string) $create->json('data.container_id');
        $this->assertDatabaseHas('containers', [
            'container_id' => $containerId,
            'primary_sku_id' => $sku->sku_id,
        ]);
    }

    public function test_invalid_box_container_create_rolls_back_canonical_root(): void
    {
        $code = PhysicalCode::query()->create([
            'code_value' => 'CTN-ROLLBACK',
            'code_type' => 'BOX',
            'carrier_type' => 'BARCODE',
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v1/containers', [
            'container_code' => 'CTN-ROLLBACK',
            'container_type' => 'BOX',
            'physical_code_id' => $code->physical_code_id,
            'status' => 'ACTIVE',
        ], $this->authHeaders())->assertStatus(422);

        $this->assertDatabaseMissing('containers', ['container_code' => 'CTN-ROLLBACK']);
    }

    private function sku(string $code): Sku
    {
        return Sku::query()->create([
            'sku_code' => $code,
            'sku_name' => $code,
            'sku_type' => 'PRODUCT',
            'status' => 'ACTIVE',
            'version_code' => 'V1',
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ];
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
