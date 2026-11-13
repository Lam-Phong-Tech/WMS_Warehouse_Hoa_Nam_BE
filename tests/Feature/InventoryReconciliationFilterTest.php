<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryReconciliationFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    private User $user;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $this->user = User::create([
            'username' => 'reconciliation-filter-admin',
            'display_name' => 'Reconciliation Filter Admin',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->headers = ['Authorization' => 'Bearer '.auth('api')->login($this->user)];
        $this->warehouse = $this->ensureActiveWarehouse('RECONCILIATION-FILTER');
    }

    public static function endpoints(): array
    {
        return [
            'inventory' => ['/api/v1/inventory'],
            'balances alias' => ['/api/v1/inventory/balances'],
        ];
    }

    public function test_keyword_matches_warehouse_name_before_pagination_and_keeps_other_filters(): void
    {
        $this->warehouse->update(['name' => 'Search warehouse Delta']);
        $first = $this->balance(1, 1);
        $second = $this->balance(2, 1);
        $this->balance(3, 1, 'DEFECTIVE');
        foreach ([$first, $second] as $offset => $sku) {
            $this->getJson('/api/v1/inventory?'.http_build_query([
                'keyword' => 'warehouse Delta', 'stock_status' => 'AVAILABLE',
                'per_page' => 1, 'page' => $offset + 1,
            ]), $this->headers)->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->assertJsonPath('data.0.sku_id', $sku->sku_id);
        }
    }

    public function test_warehouse_keyword_or_branch_preserves_scope_and_scope_specific_cache(): void
    {
        $this->warehouse->update(['name' => 'Shared search warehouse']);
        $sku = $this->balance(1, 1);
        $other = Warehouse::create(['code' => 'OTHER-SEARCH', 'name' => 'Shared search warehouse', 'status' => 'INACTIVE']);
        InventoryBalance::create([
            'warehouse_id' => $other->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 9, 'available_qty' => 9,
            'reserved_qty' => 0, 'version' => 1,
        ]);
        foreach ([$this->warehouse->id, $other->id, $this->warehouse->id] as $warehouseId) {
            $actor = new InventoryKeywordScopeUser;
            $actor->setRawAttributes($this->user->getAttributes());
            $actor->searchWarehouseIds = [$warehouseId];
            auth('api')->setUser($actor);
            $request = Request::create('/api/v1/inventory', 'GET', ['keyword' => 'Shared search warehouse']);
            $request->setUserResolver(fn () => $actor);
            $response = app(InventoryBalanceController::class)->index($request)->getData(true);
            $this->assertSame(1, $response['meta']['total']);
            $this->assertSame($warehouseId, $response['data'][0]['warehouse_id']);
        }
    }

    public static function paginatedFilters(): array
    {
        $cases = [];
        foreach (self::endpoints() as $name => [$endpoint]) {
            $cases[$name.' match'] = [$endpoint, 'MATCH', 2, [1, 2]];
            $cases[$name.' mismatch'] = [$endpoint, 'MISMATCH', 3, [3, 4, 5]];
        }

        return $cases;
    }

    #[DataProvider('paginatedFilters')]
    public function test_reconciliation_filter_selects_rows_before_pagination_with_exact_totals(
        string $endpoint,
        string $status,
        int $total,
        array $ordinals
    ): void {
        $skuIds = [];
        foreach ([1, 2, 3, 4, 5] as $ordinal) {
            $sku = $this->balance($ordinal, 7);
            $skuIds[$ordinal] = $sku->sku_id;
            $this->movement($sku, $ordinal <= 2 ? 7 : 6);
        }

        // Mismatches start beyond the original first page, so filtering a page
        // in memory cannot satisfy either the rows or the exact total.
        foreach ($ordinals as $offset => $ordinal) {
            $this->getJson($endpoint.'?'.http_build_query([
                'reconciliation_status' => $status,
                'per_page' => 1,
                'page' => $offset + 1,
            ]), $this->headers)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.sku_id', $skuIds[$ordinal])
                ->assertJsonPath('data.0.reconciliation_status', $status)
                ->assertJsonPath('meta.total', $total)
                ->assertJsonPath('meta.last_page', $total)
                ->assertJsonPath('meta.current_page', $offset + 1);
        }
    }

    #[DataProvider('endpoints')]
    public function test_reconciliation_combines_with_stock_status_and_sku_type_using_signed_ledger_totals(string $endpoint): void
    {
        $component = $this->balance(1, 7);
        $this->movement($component, 10);
        $issue = $this->movement($component, -4, 'AVAILABLE', StockMovement::ISSUE_POSTED);
        $this->movement($component, 1, 'AVAILABLE', StockMovement::REVERSAL, $issue->id);
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $component->sku_id,
            'stock_status' => 'DEFECTIVE',
            'on_hand_qty' => 3,
            'available_qty' => 0,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        $this->movement($component, 2, 'DEFECTIVE');
        $product = $this->balance(2, 5, 'AVAILABLE', 'PRODUCT');
        $this->movement($product, 4);
        $otherComponent = $this->balance(3, 4);
        $this->movement($otherComponent, 3);

        $this->getJson($endpoint.'?'.http_build_query([
            'reconciliation_status' => 'MATCH',
            'stock_status' => 'AVAILABLE',
            'sku_type' => 'COMPONENT',
        ]), $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku_id', $component->sku_id)
            ->assertJsonPath('data.0.ledger_total', 7)
            ->assertJsonPath('data.0.variance', 0);

        $this->getJson($endpoint.'?'.http_build_query([
            'reconciliation_status' => 'MISMATCH',
            'stock_status' => 'DEFECTIVE',
            'sku_type' => 'COMPONENT',
            'sku_id' => $component->sku_id,
            'warehouse_id' => $this->warehouse->id,
            'keyword' => 'FILTER-001',
        ]), $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku_id', $component->sku_id)
            ->assertJsonPath('data.0.stock_status', 'DEFECTIVE')
            ->assertJsonPath('data.0.ledger_total', 2)
            ->assertJsonPath('data.0.variance', 1);
    }

    #[DataProvider('endpoints')]
    public function test_missing_ledger_is_zero_for_matching_and_mismatching_balances(string $endpoint): void
    {
        $zero = $this->balance(1, 0);
        $nonzero = $this->balance(2, 2);

        foreach ([['MATCH', $zero->sku_id, 0], ['MISMATCH', $nonzero->sku_id, 2]] as [$status, $skuId, $variance]) {
            $this->getJson($endpoint.'?reconciliation_status='.$status, $this->headers)
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.sku_id', $skuId)
                ->assertJsonPath('data.0.reconciliation_status', $status)
                ->assertJsonPath('data.0.ledger_total', 0)
                ->assertJsonPath('data.0.variance', $variance);
        }
    }

    #[DataProvider('endpoints')]
    public function test_reconciliation_cache_entries_are_isolated_and_empty_filter_returns_all(string $endpoint): void
    {
        $matching = $this->balance(1, 0);
        $mismatching = $this->balance(2, 1);

        // Warm all three variants and read them again in a different order.
        foreach (['', 'MATCH', 'MISMATCH', 'MATCH', '', 'MISMATCH'] as $status) {
            $response = $this->getJson($endpoint.($status === '' ? '' : '?reconciliation_status='.$status), $this->headers)
                ->assertOk();
            if ($status === '') {
                $response->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
            } else {
                $response->assertJsonPath('meta.total', 1)
                    ->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.sku_id', $status === 'MATCH' ? $matching->sku_id : $mismatching->sku_id);
            }
        }

        $this->getJson($endpoint.'?reconciliation_status=', $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
    }

    #[DataProvider('endpoints')]
    public function test_invalid_reconciliation_status_returns_validation_error(string $endpoint): void
    {
        foreach (['ALL', 'match', 'UNKNOWN', ['MATCH']] as $status) {
            $this->getJson($endpoint.'?'.http_build_query(['reconciliation_status' => $status]), $this->headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reconciliation_status');
        }
    }

    private function balance(int $ordinal, int $onHand, string $stockStatus = 'AVAILABLE', string $skuType = 'COMPONENT'): Sku
    {
        $sku = new Sku([
            'sku_code' => 'FILTER-'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT),
            'sku_name' => 'Reconciliation filter fixture '.$ordinal,
            'sku_type' => $skuType,
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $sku->sku_id = '00000000-0000-4000-8000-'.str_pad((string) $ordinal, 12, '0', STR_PAD_LEFT);
        $sku->save();
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $sku->sku_id,
            'stock_status' => $stockStatus,
            'on_hand_qty' => $onHand,
            'available_qty' => $onHand,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        return $sku;
    }

    private function movement(
        Sku $sku,
        int $delta,
        string $stockStatus = 'AVAILABLE',
        string $type = StockMovement::RECEIPT_POSTED,
        ?string $originalMovementId = null
    ): StockMovement {
        return StockMovement::create([
            'movement_no' => 'MV-FILTER-'.Str::upper(Str::random(10)),
            'business_key' => (string) Str::uuid(),
            'movement_type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $sku->sku_id,
            'stock_status' => $stockStatus,
            'qty_before' => $delta < 0 ? abs($delta) : 0,
            'qty_delta' => $delta,
            'qty_after' => max(0, $delta),
            'quantity' => abs($delta),
            'original_movement_id' => $originalMovementId,
            'created_by' => $this->user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}

class InventoryKeywordScopeUser extends User
{
    public array $searchWarehouseIds = [];

    public function warehouseScopeIds(): ?array
    {
        return $this->searchWarehouseIds;
    }
}
