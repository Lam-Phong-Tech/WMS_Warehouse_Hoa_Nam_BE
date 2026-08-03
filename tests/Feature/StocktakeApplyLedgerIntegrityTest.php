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

class StocktakeApplyLedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_zero_effect_apply_does_not_create_empty_movement_or_increment_balance_version(): void
    {
        [$token, $user, $warehouse, $product, $balance] = $this->fixture(100);
        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-ZERO-EFFECT-001');
        $issue = $this->movement(
            $user,
            $warehouse,
            $product,
            '-10.000',
            '100.000',
            '90.000',
            now(),
            StockMovement::ISSUE_POSTED
        );
        $balance->update(['on_hand_qty' => 90, 'available_qty' => 90, 'version' => 2]);

        $this->recordSkuCount($token, $sessionId, $product->id, 90);
        $review = $this->review($token, $sessionId, 2);
        $varianceId = (string) $review->json('data.variances.0.variance_id');
        $this->acknowledge($token, $sessionId, 3, $varianceId, [$issue->id]);

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 4,
            'variance_ids' => [$varianceId],
            'reason' => 'Variance bằng 0 không được sinh movement rỗng',
        ], $this->headers($token, 'stk-zero-effect'))
            ->assertOk()
            ->assertJsonPath('data.status', StocktakeSession::STATUS_APPLIED)
            ->assertJsonCount(0, 'data.movement_ids')
            ->assertJsonPath('data.zero_effect_dimensions.0', "{$product->id}|AVAILABLE");

        $freshBalance = InventoryBalance::query()->findOrFail($balance->id);
        $this->assertEquals(90.0, (float) $freshBalance->on_hand_qty);
        $this->assertSame(2, (int) $freshBalance->version);
        $this->assertSame(0, StockMovement::query()
            ->where('stocktake_variance_id', $varianceId)
            ->count());
        $this->assertDatabaseHas('stocktake_variance', [
            'variance_id' => $varianceId,
            'status' => 'APPLIED',
            'applied_movement_id' => null,
        ]);
    }

    public function test_apply_rejects_ledger_balance_mismatch_and_rolls_back_operation(): void
    {
        [$token, $user, $warehouse, $product, $balance] = $this->fixture(100);
        $sessionId = $this->createAndStart($token, $warehouse->id, 'STK-LEDGER-MISMATCH-001');

        // Mô phỏng balance bị sửa ngoài append-only ledger trước lúc review.
        $balance->update(['on_hand_qty' => 99, 'available_qty' => 99, 'version' => 2]);
        $this->recordSkuCount($token, $sessionId, $product->id, 99);
        $review = $this->review($token, $sessionId, 2);
        $varianceId = (string) $review->json('data.variances.0.variance_id');

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 3,
            'variance_ids' => [$varianceId],
            'reason' => 'Không apply khi ledger lệch balance',
        ], $this->headers($token, 'stk-ledger-mismatch'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'LEDGER_BALANCE_MISMATCH')
            ->assertJsonPath('error.details.ledger_qty', '100.000')
            ->assertJsonPath('error.details.balance_qty', '99.000')
            ->assertJsonPath('error.details.formula', 'SUM(stock_movements.qty_delta)');

        $this->assertDatabaseHas('stocktake_session', [
            'session_id' => $sessionId,
            'status' => StocktakeSession::STATUS_REVIEWED,
            'session_version' => 3,
        ]);
        $this->assertDatabaseHas('stocktake_variance', [
            'variance_id' => $varianceId,
            'status' => 'APPROVED',
            'applied_movement_id' => null,
        ]);
        $this->assertSame(0, StockMovement::query()
            ->where('stocktake_variance_id', $varianceId)
            ->count());
        $this->assertSame(0, DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$sessionId}")
            ->count());

        $freshBalance = InventoryBalance::query()->findOrFail($balance->id);
        $this->assertEquals(99.0, (float) $freshBalance->on_hand_qty);
        $this->assertSame(2, (int) $freshBalance->version);
        $this->assertSame($user->user_id, User::query()->findOrFail($user->user_id)->user_id);
    }

    /** @return array{0:string,1:User,2:Warehouse,3:Product,4:InventoryBalance} */
    private function fixture(float $openingQty): array
    {
        $user = $this->userWithPermissions([
            'inventory.view',
            'stocktake.adjust',
        ]);
        $token = $this->authToken($user);
        $warehouse = Warehouse::query()->create([
            'code' => 'KHO-LEDGER-'.strtoupper(Str::random(6)),
            'name' => 'Kho kiểm thử ledger',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'code' => 'SP-LEDGER-'.strtoupper(Str::random(6)),
            'name' => 'Sản phẩm kiểm thử ledger',
            'status' => 'ACTIVE',
        ]);
        $balance = InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => $openingQty,
            'available_qty' => $openingQty,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        $formatted = number_format($openingQty, 3, '.', '');
        $this->movement(
            $user,
            $warehouse,
            $product,
            $formatted,
            '0.000',
            $formatted,
            now()->subMinutes(5),
            StockMovement::OPENING
        );

        return [$token, $user, $warehouse, $product, $balance];
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

    private function recordSkuCount(string $token, string $sessionId, string $productId, float $actualQty): void
    {
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [[
                'grain_type' => 'SKU',
                'grain_id' => $productId,
                'product_id' => $productId,
                'stock_status' => 'AVAILABLE',
                'actual_qty' => $actualQty,
            ]],
        ], $this->headers($token))->assertOk();
    }

    private function review(string $token, string $sessionId, int $sessionVersion)
    {
        return $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => $sessionVersion,
        ], $this->headers($token))->assertOk();
    }

    /** @param array<int, string> $movementIds */
    private function acknowledge(
        string $token,
        string $sessionId,
        int $sessionVersion,
        string $varianceId,
        array $movementIds
    ): void {
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/acknowledge-movements", [
            'session_version' => $sessionVersion,
            'lines' => [[
                'variance_id' => $varianceId,
                'movement_ids' => $movementIds,
            ]],
        ], $this->headers($token))->assertOk();
    }

    private function movement(
        User $user,
        Warehouse $warehouse,
        Product $product,
        string $delta,
        string $before,
        string $after,
        $occurredAt,
        string $type
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
            'created_by' => $user->user_id,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $suffix = strtoupper(Str::random(8));
        $role = Role::query()->create([
            'role_code' => "STK_LEDGER_{$suffix}",
            'role_name' => "Stocktake ledger {$suffix}",
            'scope_type' => 'SYSTEM',
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
            'username' => "stocktake-ledger-{$suffix}",
            'display_name' => "Stocktake ledger {$suffix}",
            'email' => strtolower("stocktake-ledger-{$suffix}@example.test"),
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => null,
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

    /** @return array<string, string> */
    private function headers(string $token, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idempotencyKey,
        ], fn ($value) => $value !== null);
    }
}
