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

        Schema::dropIfExists('wwhn211_scoped_rows');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('stock_movements');

        Schema::create('wwhn211_scoped_rows', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('warehouse_id');
            $table->string('value')->nullable();
        });
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('warehouse_id');
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('warehouse_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wwhn211_scoped_rows');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('stock_movements');
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
        DB::table('stock_movements')->insert(['id' => 'mv-b', 'warehouse_id' => 'wh-b']);
        $this->authenticate(['wh-a']);
        $request = Request::create('/api/v1/stock-movements/mv-b', 'GET');

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json(['success' => true, 'data' => ['id' => 'mv-b']]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ACCESS_DENIED', $response->getData(true)['error']['code']);
    }

    public function test_direct_balance_subledger_outside_scope_returns_backend_403(): void
    {
        DB::table('inventory_balances')->insert(['id' => 'bal-b', 'warehouse_id' => 'wh-b']);
        $this->authenticate(['wh-a']);
        $request = Request::create('/api/v1/inventory/balances/bal-b/subledger', 'GET');

        $response = (new EnforceInventoryWarehouseScope)->handle(
            $request,
            fn () => response()->json(['success' => true, 'data' => ['balance_id' => 'bal-b']]),
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
