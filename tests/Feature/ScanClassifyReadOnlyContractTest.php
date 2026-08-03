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
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class ScanClassifyReadOnlyContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_scan_classify_and_same_key_replay_do_not_write_stock_or_idempotency_tables(): void
    {
        [$token, $warehouse, $product, $serial, $balance] = $this->fixture();
        $before = $this->readOnlyFingerprint();
        $payload = [
            'raw_code' => $serial->serial_no,
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouse->id,
        ];
        $headers = $this->headers($token, 'WWHN-150-SAME-KEY-001');

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/scan/classify', $payload, $headers)
                ->assertOk()
                ->assertJsonPath('data.classification', 'ITEM')
                ->assertJsonPath('data.item_id', $serial->id)
                ->assertJsonPath('data.sku_id', $product->id)
                ->assertJsonPath('data.stock_effect', 'NONE')
                ->assertJsonPath('data.movement_created', false)
                ->assertJsonPath('data.posting_event_created', false)
                ->assertJsonPath('data.idempotency_cache_written', false)
                ->assertJsonPath('data.current_location.warehouse_id', $warehouse->id);

            $this->assertSame($before, $this->readOnlyFingerprint(), "Attempt {$attempt} đã làm thay đổi read-only fingerprint.");
        }

        $balance->refresh();
        $this->assertEquals(25.0, (float) $balance->on_hand_qty);
        $this->assertSame(1, (int) $balance->version);
    }

    public function test_physical_code_resolves_real_item_without_mock_ids_or_database_writes(): void
    {
        [$token, $warehouse, $product, $serial] = $this->fixture();
        $physicalCodeId = (string) Str::uuid();
        DB::table('physical_code')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => 'QR-WWHN-150-ITEM-001',
            'code_type' => 'QR',
            'carrier_type' => 'LABEL',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $serial->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->readOnlyFingerprint();

        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => 'QR-WWHN-150-ITEM-001',
            'context' => 'INBOUND',
            'warehouse_id' => $warehouse->id,
        ], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.resolved_source', 'physical_code')
            ->assertJsonPath('data.item_id', $serial->id)
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->assertSame($before, $this->readOnlyFingerprint());
    }

    public function test_scan_enforces_permission_and_warehouse_data_scope(): void
    {
        $warehouseA = $this->warehouse('KHO-SCAN-A');
        $warehouseB = $this->warehouse('KHO-SCAN-B');
        $product = $this->product('SKU-SCAN-SCOPE');
        $serial = $this->serial($warehouseB, $product, 'SERIAL-SCAN-SCOPE');

        $scopedUser = $this->userWithPermissions('scan-scope-user', ['scan.classify'], $warehouseA->id);
        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => $serial->serial_no,
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouseB->id,
        ], $this->headers($this->authToken($scopedUser)))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'WAREHOUSE_SCOPE_DENIED');

        $noPermissionUser = $this->userWithPermissions('scan-no-permission', []);
        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => $serial->serial_no,
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouseB->id,
        ], $this->headers($this->authToken($noPermissionUser)))
            ->assertStatus(403);
    }

    public function test_scan_returns_explicit_404_409_and_422_without_side_effects(): void
    {
        [$token, $warehouse, $product, $serial] = $this->fixture();
        $before = $this->readOnlyFingerprint();

        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => 'UNKNOWN-WWHN-150',
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouse->id,
        ], $this->headers($token))
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'SCAN_CODE_NOT_FOUND');
        $this->assertSame($before, $this->readOnlyFingerprint());

        $serial->update(['status' => 'ISSUED']);
        $afterFixtureChange = $this->readOnlyFingerprint();
        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => $serial->serial_no,
            'context' => 'OUTBOUND',
            'warehouse_id' => $warehouse->id,
        ], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SCAN_CONTEXT_CONFLICT');
        $this->assertSame($afterFixtureChange, $this->readOnlyFingerprint());

        $this->postJson('/api/v1/scan/classify', [
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouse->id,
        ], $this->headers($token))->assertStatus(422);
        $this->assertSame($afterFixtureChange, $this->readOnlyFingerprint());

        $warehouse->update(['status' => 'INACTIVE']);
        $afterWarehouseChange = $this->readOnlyFingerprint();
        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => $serial->serial_no,
            'context' => 'STOCKTAKE',
            'warehouse_id' => $warehouse->id,
        ], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'WAREHOUSE_INACTIVE');
        $this->assertSame($afterWarehouseChange, $this->readOnlyFingerprint());
    }

    /**
     * @return array{0:string,1:Warehouse,2:Product,3:InventorySerial,4:InventoryBalance}
     */
    private function fixture(): array
    {
        $warehouse = $this->warehouse('KHO-WWHN-150');
        $product = $this->product('SKU-WWHN-150');
        $serial = $this->serial($warehouse, $product, 'SERIAL-WWHN-150-001');
        $balance = InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 25,
            'available_qty' => 25,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        $user = $this->userWithPermissions('scan-readonly-user', ['scan.classify', 'warehouse.view']);

        return [$this->authToken($user), $warehouse, $product, $serial, $balance];
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

    private function serial(Warehouse $warehouse, Product $product, string $serialNo): InventorySerial
    {
        return InventorySerial::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => $serialNo,
            'condition' => 'GOOD',
            'stock_condition' => 'AVAILABLE',
            'item_condition' => 'NEW',
            'status' => 'IN_STOCK',
        ]);
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
            'email' => "{$username}@wwhn150.test",
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
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');
    }

    private function headers(string $token, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idempotencyKey,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function readOnlyFingerprint(): array
    {
        return [
            'inventory_balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'stock_movements' => DB::table('stock_movements')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'posting_event' => DB::table('posting_event')->orderBy('posting_event_id')->get()->map(fn ($row) => (array) $row)->all(),
            'idempotency_keys' => DB::table('idempotency_keys')->orderBy('business_key')->get()->map(fn ($row) => (array) $row)->all(),
            'scan_event' => DB::table('scan_event')->orderBy('scan_event_id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
