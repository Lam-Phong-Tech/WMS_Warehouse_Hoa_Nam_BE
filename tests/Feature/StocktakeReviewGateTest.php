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

class StocktakeReviewGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_committed_replay_checks_warehouse_scope_before_returning_snapshot(): void
    {
        $warehouseA = $this->warehouse('KHO-REPLAY-A');
        $warehouseB = $this->warehouse('KHO-REPLAY-B');
        $product = $this->product('SP-REPLAY');
        $admin = $this->user('replay-admin', null);
        $restricted = $this->user('replay-restricted', $warehouseA->id);
        $adminToken = $this->token($admin);
        $restrictedToken = $this->token($restricted);
        $this->openingBalance($admin, $warehouseB, $product, 100);

        $reviewed = $this->reviewedSku($adminToken, $warehouseB, $product, 'STK-REPLAY-SCOPE', 98);
        $payload = [
            'session_version' => 3,
            'variance_ids' => [$reviewed['variance_id']],
            'reason' => 'Exact replay scope gate',
        ];
        $key = 'stk-review-replay-scope';

        $first = $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['session_id']}/apply-adjustment",
            $payload,
            $this->headers($adminToken, $key)
        );
        $first->assertOk()->assertJsonPath('data.status', StocktakeSession::STATUS_APPLIED);

        $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['session_id']}/apply-adjustment",
            $payload,
            $this->headers($restrictedToken, $key)
        )
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED')
            ->assertJsonMissing(['request_hash' => $first->json('data.request_hash')]);

        $this->assertSame(1, DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$reviewed['session_id']}")
            ->where('idempotency_key', $key)
            ->count());
    }

    public function test_different_key_cannot_apply_same_reviewed_session_twice(): void
    {
        $warehouse = $this->warehouse('KHO-DOUBLE-APPLY');
        $product = $this->product('SP-DOUBLE-APPLY');
        $user = $this->user('double-apply-admin', null);
        $token = $this->token($user);
        $balance = $this->openingBalance($user, $warehouse, $product, 100);
        $reviewed = $this->reviewedSku($token, $warehouse, $product, 'STK-DOUBLE-APPLY', 98);
        $payload = [
            'session_version' => 3,
            'variance_ids' => [$reviewed['variance_id']],
            'reason' => 'Chỉ được apply một lần',
        ];

        $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['session_id']}/apply-adjustment",
            $payload,
            $this->headers($token, 'stk-double-apply-first')
        )->assertOk();

        $movementCount = StockMovement::query()
            ->where('stocktake_variance_id', $reviewed['variance_id'])
            ->count();
        $eventCount = DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$reviewed['session_id']}")
            ->count();

        $this->postJson(
            "/api/v1/stocktake-sessions/{$reviewed['session_id']}/apply-adjustment",
            $payload,
            $this->headers($token, 'stk-double-apply-second')
        )
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_VERSION_CONFLICT');

        $this->assertSame($movementCount, StockMovement::query()
            ->where('stocktake_variance_id', $reviewed['variance_id'])
            ->count());
        $this->assertSame($eventCount, DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$reviewed['session_id']}")
            ->count());
        $this->assertEquals(98.0, (float) InventoryBalance::query()->findOrFail($balance->id)->on_hand_qty);
        $this->assertDatabaseHas('stocktake_session', [
            'session_id' => $reviewed['session_id'],
            'status' => StocktakeSession::STATUS_APPLIED,
            'session_version' => 4,
        ]);
    }

    public function test_all_write_lifecycle_endpoints_reject_cross_warehouse_actor_without_state_change(): void
    {
        $warehouseA = $this->warehouse('KHO-LIFECYCLE-A');
        $warehouseB = $this->warehouse('KHO-LIFECYCLE-B');
        $product = $this->product('SP-LIFECYCLE');
        $admin = $this->user('lifecycle-admin', null);
        $restricted = $this->user('lifecycle-restricted', $warehouseA->id);
        $adminToken = $this->token($admin);
        $restrictedToken = $this->token($restricted);
        $this->openingBalance($admin, $warehouseB, $product, 10);

        $create = $this->postJson('/api/v1/stocktake-sessions', [
            'warehouse_id' => $warehouseB->id,
            'session_no' => 'STK-LIFECYCLE-SCOPE',
        ], $this->headers($adminToken))->assertCreated();
        $sessionId = (string) $create->json('data.session_id');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/start", [
            'session_version' => 1,
        ], $this->headers($adminToken))->assertOk();

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [[
                'grain_type' => 'SKU',
                'grain_id' => $product->id,
                'product_id' => $product->id,
                'stock_status' => 'AVAILABLE',
                'actual_qty' => 10,
            ]],
        ], $this->headers($restrictedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($restrictedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/acknowledge-movements", [
            'session_version' => 2,
            'lines' => [[
                'variance_id' => (string) Str::uuid(),
                'movement_ids' => [],
            ]],
        ], $this->headers($restrictedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $this->getJson(
            "/api/v1/stocktake-sessions/{$sessionId}/variances",
            $this->headers($restrictedToken)
        )
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/apply-adjustment", [
            'session_version' => 2,
            'variance_ids' => [(string) Str::uuid()],
            'reason' => 'Cross warehouse lifecycle negative',
        ], $this->headers($restrictedToken, 'stk-lifecycle-scope'))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $this->assertDatabaseHas('stocktake_session', [
            'session_id' => $sessionId,
            'status' => StocktakeSession::STATUS_IN_PROGRESS,
            'session_version' => 2,
        ]);
        $this->assertSame(0, DB::table('stocktake_count')->where('session_id', $sessionId)->count());
        $this->assertSame(0, DB::table('stocktake_variance')->where('session_id', $sessionId)->count());
        $this->assertSame(0, DB::table('posting_event')
            ->where('operation', "stocktake_apply_adjustment_{$sessionId}")
            ->count());
    }

    /** @return array{session_id:string,variance_id:string} */
    private function reviewedSku(
        string $token,
        Warehouse $warehouse,
        Product $product,
        string $sessionNo,
        float $actualQty
    ): array {
        $create = $this->postJson('/api/v1/stocktake-sessions', [
            'warehouse_id' => $warehouse->id,
            'session_no' => $sessionNo,
        ], $this->headers($token))->assertCreated();
        $sessionId = (string) $create->json('data.session_id');
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/start", [
            'session_version' => 1,
        ], $this->headers($token))->assertOk();
        $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/counts", [
            'lines' => [[
                'grain_type' => 'SKU',
                'grain_id' => $product->id,
                'product_id' => $product->id,
                'stock_status' => 'AVAILABLE',
                'actual_qty' => $actualQty,
            ]],
        ], $this->headers($token))->assertOk();
        $review = $this->postJson("/api/v1/stocktake-sessions/{$sessionId}/review", [
            'session_version' => 2,
        ], $this->headers($token))->assertOk();

        return [
            'session_id' => $sessionId,
            'variance_id' => (string) $review->json('data.variances.0.variance_id'),
        ];
    }

    private function openingBalance(
        User $actor,
        Warehouse $warehouse,
        Product $product,
        float $qty
    ): InventoryBalance {
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
        StockMovement::query()->create([
            'movement_no' => 'OPEN-'.strtoupper(Str::random(10)),
            'business_key' => hash('sha256', Str::uuid()->toString()),
            'movement_type' => StockMovement::OPENING,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => '0.000',
            'qty_delta' => $formatted,
            'qty_after' => $formatted,
            'created_by' => $actor->user_id,
            'occurred_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        return $balance;
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code,
            'status' => 'ACTIVE',
        ]);
    }

    private function product(string $code): Product
    {
        return Product::query()->create([
            'code' => $code,
            'name' => $code,
            'status' => 'ACTIVE',
        ]);
    }

    private function user(string $username, ?string $warehouseScope): User
    {
        $role = Role::query()->create([
            'role_code' => strtoupper(str_replace('-', '_', $username)),
            'role_name' => $username,
            'scope_type' => $warehouseScope === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);
        foreach (['inventory.view', 'stocktake.adjust'] as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_key' => $permissionKey],
                [
                    'permission_name' => $permissionKey,
                    'scope_type' => 'FUNCTION',
                    'status' => 'ACTIVE',
                ]
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user = User::query()->create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@stocktake-review.test",
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

    private function token(User $user): string
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
        ], static fn ($value): bool => $value !== null);
    }
}
