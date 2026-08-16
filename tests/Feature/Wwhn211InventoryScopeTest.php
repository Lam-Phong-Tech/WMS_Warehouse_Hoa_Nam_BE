<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
use Modules\Warehouse\Models\Concerns\ScopesWarehouseData;
use Tests\TestCase;

class Wwhn211InventoryScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep this test isolated without dropping/recreating canonical runtime
        // tables. On PostgreSQL, stock_movements and inventory_balances have
        // compatibility views/triggers that legitimately depend on them; tearing
        // those tables down makes the test fixture diverge from the migrated
        // production schema and fails before RBAC is exercised.
        Schema::dropIfExists('wwhn211_scoped_rows');
        Schema::create('wwhn211_scoped_rows', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('warehouse_id');
            $table->string('value')->nullable();
        });

        $this->ensureCanonicalInventoryEvidenceRows();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wwhn211_scoped_rows');
        parent::tearDown();
    }

    public function test_model_global_scope_only_returns_effective_warehouse_scope(): void
    {
        DB::table('wwhn211_scoped_rows')->insert([
            ['id' => 'row-a', 'warehouse_id' => 'wh-a', 'value' => 'allowed'],
            ['id' => 'row-b', 'warehouse_id' => 'wh-b', 'value' => 'forbidden'],
        ]);
        $this->authenticate(['wh-a']);

        $rows = Wwhn211ScopedRow::query()->orderBy('id')->get();

        $this->assertSame(['row-a'], $rows->pluck('id')->all());
    }

    public function test_empty_effective_scope_fails_closed(): void
    {
        DB::table('wwhn211_scoped_rows')->insert([
            ['id' => 'row-a', 'warehouse_id' => 'wh-a', 'value' => 'hidden'],
        ]);
        $this->authenticate([]);

        $this->assertSame(0, Wwhn211ScopedRow::query()->count());
    }

    public function test_system_scope_remains_unrestricted(): void
    {
        DB::table('wwhn211_scoped_rows')->insert([
            ['id' => 'row-a', 'warehouse_id' => 'wh-a'],
            ['id' => 'row-b', 'warehouse_id' => 'wh-b'],
        ]);
        $this->authenticate(null);

        $this->assertSame(2, Wwhn211ScopedRow::query()->count());
    }

    public function test_explicit_forbidden_warehouse_returns_real_backend_403(): void
    {
        $this->authenticate(['wh-a']);
        $request = Request::create('/api/v1/stock-movements?warehouse_id=wh-b', 'GET', [
            'warehouse_id' => 'wh-b',
        ]);

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json(['success' => true, 'data' => []]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ACCESS_DENIED', $response->getData(true)['error_code']);
    }

    public function test_direct_movement_id_outside_scope_returns_backend_403(): void
    {
        $movement = DB::table('stock_movements')->select(['id', 'warehouse_id'])->first();
        $this->assertNotNull($movement, 'Canonical evidence fixture must contain at least one stock movement.');

        $this->authenticate(['warehouse-outside-seeded-scope']);
        $request = Request::create('/api/v1/stock-movements/'.$movement->id, 'GET');

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json(['success' => true, 'data' => ['id' => $movement->id]]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ACCESS_DENIED', $response->getData(true)['error']['code']);
    }

    public function test_direct_balance_subledger_outside_scope_returns_backend_403(): void
    {
        $balance = DB::table('inventory_balances')->select(['id', 'warehouse_id'])->first();
        $this->assertNotNull($balance, 'Canonical evidence fixture must contain at least one inventory balance.');

        $this->authenticate(['warehouse-outside-seeded-scope']);
        $request = Request::create('/api/v1/inventory/balances/'.$balance->id.'/subledger', 'GET');

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json(['success' => true, 'data' => ['balance_id' => $balance->id]]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ACCESS_DENIED', $response->getData(true)['error_code']);
    }

    public function test_physical_code_trace_filters_movements_outside_scope(): void
    {
        $this->authenticate(['wh-a']);
        $request = Request::create('/api/v1/stock-movements/trace-by-code?code=QR-1', 'GET', ['code' => 'QR-1']);

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json([
                'success' => true,
                'data' => [
                    'physical_code' => ['code_value' => 'QR-1'],
                    'resolved_type' => 'ITEM',
                    'resolved_id' => 'item-1',
                    'resolved_object' => ['sku_code' => 'SKU-1'],
                    'movements' => [
                        ['id' => 'mv-a', 'warehouse_id' => 'wh-a'],
                        ['id' => 'mv-b', 'warehouse_id' => 'wh-b'],
                    ],
                    'container_items' => [],
                ],
            ]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $movements = $response->getData(true)['data']['movements'];
        $this->assertCount(1, $movements);
        $this->assertSame('wh-a', $movements[0]['warehouse_id']);
    }

    public function test_physical_code_trace_with_only_out_of_scope_history_returns_403(): void
    {
        $this->authenticate(['wh-a']);
        $request = Request::create('/api/v1/stock-movements/trace-by-code?code=QR-2', 'GET', ['code' => 'QR-2']);

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json([
                'success' => true,
                'data' => [
                    'physical_code' => ['code_value' => 'QR-2'],
                    'resolved_type' => 'ITEM',
                    'resolved_id' => 'item-2',
                    'resolved_object' => ['sku_code' => 'SKU-2'],
                    'movements' => [['id' => 'mv-b', 'warehouse_id' => 'wh-b']],
                    'container_items' => [],
                ],
            ]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ACCESS_DENIED', $response->getData(true)['error_code']);
    }

    /**
     * Build the two physical rows needed by direct-ID data-scope tests without
     * depending on business seeders having already Posted a stock document.
     * The rows use existing canonical master/user IDs and insert only; no
     * canonical table is dropped, recreated, updated, or deleted.
     */
    private function ensureCanonicalInventoryEvidenceRows(): void
    {
        if (DB::table('inventory_balances')->exists() && DB::table('stock_movements')->exists()) {
            return;
        }

        $warehouseId = DB::table('warehouses')->value('id');
        $skuId = Schema::hasTable('skus')
            ? DB::table('skus')->value('id')
            : DB::table('sku')->value('sku_id');
        $userId = Schema::hasTable('app_users')
            ? DB::table('app_users')->value('id')
            : DB::table('app_user')->value('user_id');

        if (! is_string($warehouseId) || ! is_string($skuId) || ! is_string($userId)) {
            throw new \RuntimeException('WWHN-211 evidence fixture requires seeded warehouse, SKU and user masters.');
        }

        $now = now();
        $balanceId = '21100000-0000-4000-8000-000000000001';
        $movementId = '21100000-0000-4000-8000-000000000002';

        if (! DB::table('inventory_balances')->exists()) {
            $balance = [
                'id' => $balanceId,
                'warehouse_id' => $warehouseId,
                'product_id' => $skuId,
                'stock_status' => 'AVAILABLE',
                'on_hand_qty' => 1,
                'available_qty' => 1,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('inventory_balances', 'sku_id')) {
                $balance['sku_id'] = $skuId;
            }
            if (Schema::hasColumn('inventory_balances', 'quantity')) {
                $balance['quantity'] = 1;
            }
            DB::table('inventory_balances')->insertOrIgnore($balance);
        }

        if (! DB::table('stock_movements')->exists()) {
            $movement = [
                'id' => $movementId,
                'movement_no' => 'MV-WWHN211-SCOPE',
                'business_key' => 'wwhn211-scope-evidence',
                'movement_type' => 'RECEIPT',
                'warehouse_id' => $warehouseId,
                'product_id' => $skuId,
                'stock_status' => 'AVAILABLE',
                'qty_before' => 0,
                'qty_delta' => 1,
                'qty_after' => 1,
                'created_by' => $userId,
                'request_id' => 'WWHN-211-SCOPE-EVIDENCE',
                'occurred_at' => $now,
                'created_at' => $now,
            ];
            if (Schema::hasColumn('stock_movements', 'sku_id')) {
                $movement['sku_id'] = $skuId;
            }
            if (Schema::hasColumn('stock_movements', 'quantity')) {
                $movement['quantity'] = 1;
            }
            if (Schema::hasColumn('stock_movements', 'source_type')) {
                $movement['source_type'] = 'STOCK_DOCUMENT';
            }
            DB::table('stock_movements')->insertOrIgnore($movement);
        }

        if (! DB::table('inventory_balances')->exists() || ! DB::table('stock_movements')->exists()) {
            throw new \RuntimeException('WWHN-211 canonical evidence rows could not be created.');
        }
    }

    private function authenticate(?array $warehouseScopeIds): void
    {
        $user = new Wwhn211ScopeUser;
        $user->testWarehouseScopeIds = $warehouseScopeIds;
        $user->testPermissions = ['inventory.view', 'inventory.trace'];
        $user->forceFill(['user_id' => 'wwhn-211-user', 'version' => 1]);
        auth('api')->setUser($user);
    }
}

class Wwhn211ScopeUser extends User
{
    /** @var list<string>|null */
    public ?array $testWarehouseScopeIds = [];

    /** @var list<string> */
    public array $testPermissions = [];

    public function warehouseScopeIds(): ?array
    {
        return $this->testWarehouseScopeIds;
    }

    public function hasPermission(string $permissionKey): bool
    {
        return in_array($permissionKey, $this->testPermissions, true);
    }
}

class Wwhn211ScopedRow extends Model
{
    use ScopesWarehouseData;

    protected $table = 'wwhn211_scoped_rows';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
