<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\StocktakeSession;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class StocktakeThreeLayerContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_create_is_draft_and_start_creates_snapshot_once_with_cas(): void
    {
        [$token, $warehouse, $product] = $this->fixture();
        $create = $this->postJson('/api/v1/stocktake-sessions', [
            'warehouse_id' => $warehouse->id,
            'session_no' => 'STK-CONTRACT-001',
        ], $this->headers($token));
        $create->assertCreated()
            ->assertJsonPath('data.status', StocktakeSession::STATUS_DRAFT)
            ->assertJsonPath('data.session_version', 1)
            ->assertJsonPath('data.stocktake_started_at', null);

        $sessionId = $create->json('data.session_id');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/start", [
            'session_version' => 1,
        ], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.session.status', StocktakeSession::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.session.session_version', 2)
            ->assertJsonPath('data.snapshot_rows.SKU', 1);

        $this->assertDatabaseHas('stocktake_snapshot', [
            'session_id' => $sessionId,
            'grain_type' => 'SKU',
            'grain_id' => $product->id,
            'grain_key' => "SKU|{$product->id}|AVAILABLE",
        ]);
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/start", [
            'session_version' => 1,
        ], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_VERSION_CONFLICT');
        $this->assertSame(1, DB::table('stocktake_snapshot')->where('session_id', $sessionId)->count());
    }

    public function test_count_grain_is_unique_and_must_exist_in_session_snapshot(): void
    {
        [$token, $warehouse, $product] = $this->fixture();
        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-COUNT-001');
        $line = $this->skuCountLine($product->id, 100);

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", ['lines' => [$line]], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.counts_created', 1);
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", ['lines' => [$line]], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DUPLICATE_COUNT_GRAIN');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", ['lines' => [[
            'grain_type' => 'SKU',
            'grain_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'actual_qty' => 1,
        ]]], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'COUNT_GRAIN_NOT_IN_SNAPSHOT');
    }

    public function test_signed_movement_convention_100_minus_10_actual_90_has_zero_variance_and_reconciles(): void
    {
        [$token, $warehouse, $product, $balance] = $this->fixture();
        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-SIGNED-001');
        $issue = $this->movement($warehouse, $product, '-10.000', '100.000', '90.000', now());
        $balance->update(['on_hand_qty' => 90, 'available_qty' => 90, 'version' => 2]);

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [$this->skuCountLine($product->id, 90)],
        ], $this->headers($token))->assertOk();
        $review = $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($token));
        $review->assertOk()
            ->assertJsonPath('data.variances.0.expected_qty', '100.000')
            ->assertJsonPath('data.variances.0.expected_at_count', '90.000')
            ->assertJsonPath('data.variances.0.actual_qty', '90.000')
            ->assertJsonPath('data.variances.0.variance_qty', '0.000')
            ->assertJsonPath('data.variances.0.inventory_effect_qty', '0.000')
            ->assertJsonPath('data.variances.0.detected_movement_ids.0', $issue->id);

        $varianceId = $review->json('data.variances.0.variance_id');
        $this->acknowledge($token, $sessionId, 3, $varianceId, [$issue->id]);
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 4,
            'variance_ids' => [$varianceId],
            'reason' => 'Đối soát signed movement',
        ], $this->headers($token, 'stk-signed-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', StocktakeSession::STATUS_APPLIED);

        $this->assertEquals(90.0, (float) InventoryBalance::query()->findOrFail($balance->id)->on_hand_qty);
        $this->assertEquals(90.0, (float) StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('qty_delta'));
    }

    public function test_apply_requires_exact_detected_movement_acknowledgement(): void
    {
        [$token, $warehouse, $product, $balance] = $this->fixture();
        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-ACK-001');
        $issue = $this->movement($warehouse, $product, '-5.000', '100.000', '95.000', now());
        $balance->update(['on_hand_qty' => 95, 'available_qty' => 95, 'version' => 2]);

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [$this->skuCountLine($product->id, 94)],
        ], $this->headers($token))->assertOk();
        $review = $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($token))->assertOk();
        $varianceId = $review->json('data.variances.0.variance_id');

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 3,
            'variance_ids' => [$varianceId],
            'reason' => 'Chưa ack',
        ], $this->headers($token, 'stk-ack-missing'))
            ->assertStatus(428)
            ->assertJsonPath('error_code', 'MOVEMENT_ACKNOWLEDGEMENT_REQUIRED');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/acknowledge-movements", [
            'session_version' => 3,
            'lines' => [[
                'variance_id' => $varianceId,
                'movement_ids' => [(string) Str::uuid()],
            ]],
        ], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'MOVEMENT_ACKNOWLEDGEMENT_MISMATCH');
        $this->acknowledge($token, $sessionId, 3, $varianceId, [$issue->id]);
    }

    public function test_apply_rejects_foreign_or_missing_variance_ids(): void
    {
        [$token, $warehouseA, $productA] = $this->fixture('KHO-STK-A', 'SP-STK-A');
        $warehouseB = $this->warehouse('KHO-STK-B');
        $productB = $this->product('SP-STK-B');
        $this->openingBalance($warehouseB, $productB, 50);
        $sessionA = $this->reviewSimpleSku($token, $warehouseA, $productA, 'STK-XSESSION-A', 100);
        $sessionB = $this->reviewSimpleSku($token, $warehouseB, $productB, 'STK-XSESSION-B', 50);

        $this->postJson("/api/v1/stocktake-sessions/{$sessionA['id']}/apply-adjustment", [
            'session_version' => 3,
            'variance_ids' => [$sessionB['variance_id']],
            'reason' => 'Foreign variance',
        ], $this->headers($token, 'stk-cross-session'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_VARIANCE_SELECTION')
            ->assertJsonPath('error.details.requested_count', 1)
            ->assertJsonPath('error.details.required_count', 1);
        $this->assertDatabaseHas('stocktake_session', [
            'session_id' => $sessionA['id'],
            'status' => StocktakeSession::STATUS_REVIEWED,
        ]);
    }

    public function test_apply_is_idempotent_replays_after_commit_and_rejects_different_payload(): void
    {
        [$token, $warehouse, $product] = $this->fixture();
        $reviewed = $this->reviewSimpleSku($token, $warehouse, $product, 'STK-IDEM-001', 98);
        $payload = [
            'session_version' => 3,
            'variance_ids' => [$reviewed['variance_id']],
            'reason' => 'Chốt kiểm kê',
        ];

        $first = $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['id']}/apply-adjustment",
            $payload,
            $this->headers($token, 'stk-timeout-after-commit')
        );
        $first->assertOk()->assertJsonPath('data.status', StocktakeSession::STATUS_APPLIED);
        $movementCount = StockMovement::query()->where('stocktake_variance_id', $reviewed['variance_id'])->count();

        $replay = $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['id']}/apply-adjustment",
            $payload,
            $this->headers($token, 'stk-timeout-after-commit')
        );
        $replay->assertOk()
            ->assertJsonPath('message', 'Replay kết quả stocktake adjustment đã commit')
            ->assertJsonPath('data.request_hash', $first->json('data.request_hash'));
        $this->assertSame($movementCount, StockMovement::query()
            ->where('stocktake_variance_id', $reviewed['variance_id'])->count());

        $differentPayload = array_merge($payload, ['reason' => 'Payload khác']);
        $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['id']}/apply-adjustment",
            $differentPayload,
            $this->headers($token, 'stk-timeout-after-commit')
        )->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_ERROR');
        $this->assertSame(1, DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$reviewed['id']}")
            ->where('idempotency_key', 'stk-timeout-after-commit')
            ->count());
    }

    public function test_apply_rolls_back_balance_movement_and_item_when_container_layer_fails(): void
    {
        [$token, $warehouse] = $this->fixtureWithoutProduct();
        $itemProduct = $this->product('SP-ITEM-ROLLBACK');
        $containerProduct = $this->product('SP-CONT-ROLLBACK');
        $itemBalance = $this->openingBalance($warehouse, $itemProduct, 1);
        $containerBalance = $this->openingBalance($warehouse, $containerProduct, 10);

        $itemId = (string) Str::uuid();
        DB::table('inventory_serials')->insert([
            'id' => $itemId,
            'product_id' => $itemProduct->id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => 'SERIAL-ROLLBACK-001',
            'stock_condition' => 'AVAILABLE',
            'condition' => 'GOOD',
            'item_condition' => 'NEW',
            'status' => 'IN_STOCK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $containerId = (string) Str::uuid();
        $containerItemId = (string) Str::uuid();
        DB::table('container')->insert([
            'container_id' => $containerId,
            'container_code' => 'CONT-ROLLBACK-001',
            'container_type' => 'BOX',
            'warehouse_id' => $warehouse->id,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('container_item')->insert([
            'container_item_id' => $containerItemId,
            'container_id' => $containerId,
            'product_id' => $containerProduct->id,
            'quantity' => 10,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-ROLLBACK-001');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", ['lines' => [
            [
                'grain_type' => 'ITEM',
                'grain_id' => $itemId,
                'product_id' => $itemProduct->id,
                'stock_status' => 'AVAILABLE',
                'actual_qty' => 0,
                'counted_at' => now()->subSecond()->toIso8601String(),
            ],
            [
                'grain_type' => 'CONTAINER',
                'grain_id' => $containerItemId,
                'product_id' => $containerProduct->id,
                'stock_status' => 'AVAILABLE',
                'actual_qty' => 8,
                'counted_at' => now()->toIso8601String(),
            ],
        ]], $this->headers($token))->assertOk();
        $review = $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($token))->assertOk();
        $varianceIds = collect($review->json('data.variances'))->pluck('variance_id')->all();

        DB::table('container_item')->where('container_item_id', $containerItemId)->delete();
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 3,
            'variance_ids' => $varianceIds,
            'reason' => 'Kiểm tra rollback ba lớp',
        ], $this->headers($token, 'stk-rollback-three-layer'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CONTAINER_GRAIN_INVALID');

        $this->assertDatabaseHas('inventory_serials', ['id' => $itemId, 'status' => 'IN_STOCK']);
        $this->assertEquals(1.0, (float) InventoryBalance::query()->findOrFail($itemBalance->id)->on_hand_qty);
        $this->assertEquals(10.0, (float) InventoryBalance::query()->findOrFail($containerBalance->id)->on_hand_qty);
        $this->assertSame(0, StockMovement::query()->whereIn('stocktake_variance_id', $varianceIds)->count());
        $this->assertDatabaseHas('stocktake_session', [
            'session_id' => $sessionId,
            'status' => StocktakeSession::STATUS_REVIEWED,
        ]);
    }

    public function test_warehouse_data_scope_blocks_create_read_and_legacy_list(): void
    {
        $warehouseA = $this->warehouse('KHO-SCOPE-A');
        $warehouseB = $this->warehouse('KHO-SCOPE-B');
        $product = $this->product('SP-SCOPE');
        $this->openingBalance($warehouseB, $product, 10);
        $adminToken = $this->authToken($this->userWithPermissions('admin-scope', ['inventory.view', 'stocktake.adjust']));
        $restrictedToken = $this->authToken($this->userWithPermissions(
            'restricted-scope',
            ['inventory.view', 'stocktake.adjust'],
            $warehouseA->id
        ));

        $this->postJson('/api/v1/stocktake-sessions', [
            'warehouse_id' => $warehouseB->id,
        ], $this->headers($restrictedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');
        $sessionId = $this->createAndStart($adminToken, $warehouseB->id, 'STK-SCOPE-001');
        $this->getJson("/api/v1/stocktake-sessions/{$sessionId}", $this->headers($restrictedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');
        $this->getJson('/api/v1/stocktake/sessions', $this->headers($restrictedToken))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @return array{0:string,1:Warehouse,2:Product,3:InventoryBalance} */
    private function fixture(string $warehouseCode = 'KHO-STK-TEST', string $productCode = 'SP-STK-TEST'): array
    {
        [$token, $warehouse] = $this->fixtureWithoutProduct($warehouseCode);
        $product = $this->product($productCode);
        $balance = $this->openingBalance($warehouse, $product, 100);

        return [$token, $warehouse, $product, $balance];
    }

    /** @return array{0:string,1:Warehouse} */
    private function fixtureWithoutProduct(string $warehouseCode = 'KHO-STK-TEST'): array
    {
        $user = $this->userWithPermissions('stocktake-manager-'.Str::lower(Str::random(5)), [
            'inventory.view', 'stocktake.adjust',
        ]);

        return [$this->authToken($user), $this->warehouse($warehouseCode)];
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create(['code' => $code, 'name' => $code, 'status' => 'ACTIVE']);
    }

    private function product(string $code): Product
    {
        return Product::query()->create(['code' => $code, 'name' => $code, 'status' => 'ACTIVE']);
    }

    private function openingBalance(Warehouse $warehouse, Product $product, float $qty): InventoryBalance
    {
        $balance = InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => $qty,
            'available_qty' => $qty,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        $formatted = number_format($qty, 3, '.', '');
        $this->movement($warehouse, $product, $formatted, '0.000', $formatted, now()->subMinutes(5), StockMovement::OPENING);

        return $balance;
    }

    private function movement(
        Warehouse $warehouse,
        Product $product,
        string $delta,
        string $before,
        string $after,
        $occurredAt,
        string $type = StockMovement::ISSUE_OUT
    ): StockMovement {
        return StockMovement::query()->create([
            'movement_no' => 'TEST-'.strtoupper(Str::random(10)),
            'business_key' => hash('sha256', Str::uuid()->toString()),
            'movement_type' => $type,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => $before,
            'qty_delta' => $delta,
            'qty_after' => $after,
            'created_by' => (string) User::query()->value('user_id'),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function createAndStart(string $token, string $warehouseId, string $sessionNo): string
    {
        $create = $this->postJson('/api/v1/stocktake-sessions', [
            'warehouse_id' => $warehouseId,
            'session_no' => $sessionNo,
        ], $this->headers($token))->assertCreated();
        $sessionId = (string) $create->json('data.session_id');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/start", [
            'session_version' => 1,
        ], $this->headers($token))->assertOk();

        return $sessionId;
    }

    /** @return array{id:string,variance_id:string} */
    private function reviewSimpleSku(
        string $token,
        Warehouse $warehouse,
        Product $product,
        string $sessionNo,
        float $actualQty
    ): array {
        $sessionId = $this->createAndStart($token, $warehouse->id, $sessionNo);
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [$this->skuCountLine($product->id, $actualQty)],
        ], $this->headers($token))->assertOk();
        $review = $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($token))->assertOk();

        return ['id' => $sessionId, 'variance_id' => $review->json('data.variances.0.variance_id')];
    }

    private function acknowledge(string $token, string $sessionId, int $version, string $varianceId, array $movementIds): void
    {
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/acknowledge-movements", [
            'session_version' => $version,
            'lines' => [[
                'variance_id' => $varianceId,
                'movement_ids' => $movementIds,
            ]],
        ], $this->headers($token))->assertOk();
    }

    private function skuCountLine(string $productId, float $actualQty): array
    {
        return [
            'grain_type' => 'SKU',
            'grain_id' => $productId,
            'product_id' => $productId,
            'stock_status' => 'AVAILABLE',
            'actual_qty' => $actualQty,
        ];
    }

    private function userWithPermissions(string $username, array $permissions, ?string $warehouseScope = null): User
    {
        $role = Role::query()->create([
            'role_code' => strtoupper(str_replace('-', '_', $username)),
            'role_name' => $username,
            'scope_type' => $warehouseScope === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user = User::query()->create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@stocktake.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseScope,
        ]);

        return $user;
    }

    private function authToken(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        return (string) $response->json('data.access_token');
    }

    private function headers(string $token, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idempotencyKey,
        ], fn ($value) => $value !== null);
    }
}
