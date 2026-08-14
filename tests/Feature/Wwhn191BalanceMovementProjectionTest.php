<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-191 (BE2): verifies the movement/balance projection endpoints that support
 * reconciliation — GET /api/v1/inventory/balances, .../subledger, and
 * /api/v1/stock-movements* — actually reflect the authoritative stock_movements
 * ledger after a real post-receipt HTTP flow (not just static source assertions).
 */
class Wwhn191BalanceMovementProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'wwhn191-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-191 Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", '__user_id' => $user->user_id];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hòa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    /** Drives a full post-receipt HTTP flow and returns [document_id, line_id, sku, warehouse]. */
    private function postReceiptOf(array $headers, Warehouse $warehouse, Sku $sku, int $qty, string $boxCode, string $idemKey): array
    {
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Nha cung cap WWHN-191',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => $qty,
            ]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => $boxCode,
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => $idemKey,
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        return [$documentId, $lineId, $boxId];
    }

    public function test_inventory_balances_list_reflects_ledger_after_post_receipt_and_matches(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-BAL-001',
            'sku_name' => 'Component balance projection test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 5, 'BOX-BAL-001', 'wwhn191-balance-001');

        $response = $this->getJson('/api/v1/inventory/balances?product_id='.$sku->sku_id, $headers)
            ->assertOk();

        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('product_id', $sku->sku_id);

        $this->assertNotNull($row, 'Expected a balance row for the posted SKU.');
        $this->assertSame(5.0, (float) $row['on_hand_qty']);
        $this->assertSame(5.0, (float) $row['available_qty']);
        $this->assertSame(5.0, (float) $row['ledger_total']);
        $this->assertSame('MATCH', $row['reconciliation_status']);
        $this->assertNull($row['mismatch_reason']);
        $this->assertSame(0.0, (float) $row['variance']);
        $this->assertNull($row['incident_id']);
        $this->assertArrayHasKey('data_as_of', $row);
        $this->assertArrayHasKey('item_subledger_count', $row);
        $this->assertArrayHasKey('container_subledger_count', $row);

        // Sanity: the document actually posted and its movement is the source of the ledger total.
        $movement = StockMovement::where('stock_document_id', $documentId)->first();
        $this->assertNotNull($movement);
        $this->assertSame(5, (int) $movement->qty_delta);
    }

    public function test_inventory_balance_subledger_lists_item_instances_and_containers(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-BAL-002',
            'sku_name' => 'Component subledger test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 3, 'BOX-BAL-002', 'wwhn191-balance-002');

        $balanceId = \Modules\Warehouse\Models\InventoryBalance::where('product_id', $sku->sku_id)->value('id');
        $this->assertNotNull($balanceId, 'Expected a materialized inventory_balances row for the posted SKU.');

        $this->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)
            ->assertOk()
            ->assertJsonPath('data.balance_id', $balanceId)
            ->assertJsonPath('data.product_id', $sku->sku_id);
    }

    public function test_inventory_balance_subledger_returns_404_for_unknown_id(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];

        $this->getJson('/api/v1/inventory/balances/'.Str::uuid().'/subledger', $headers)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_stock_movements_list_show_and_trace_source_reflect_posted_receipt(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-MV-001',
            'sku_name' => 'Component movement test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 4, 'BOX-MV-001', 'wwhn191-movement-001');
        $movement = StockMovement::where('stock_document_id', $documentId)->firstOrFail();

        // List, filtered by warehouse/product/movement_type — matches the posted movement.
        $list = $this->getJson(
            "/api/v1/stock-movements?warehouse_id={$warehouse->id}&product_id={$sku->sku_id}&movement_type=RECEIPT",
            $headers
        )->assertOk();
        $listIds = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($movement->id, $listIds);

        // Detail.
        $this->getJson("/api/v1/stock-movements/{$movement->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $movement->id)
            ->assertJsonPath('data.movement_type', 'RECEIPT')
            ->assertJsonPath('data.qty_delta', 4);

        // Trace-source — must resolve back to the originating stock_document.
        $this->getJson("/api/v1/stock-movements/{$movement->id}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.movement.id', $movement->id)
            ->assertJsonPath('data.document.id', $documentId)
            ->assertJsonPath('data.original_movement', null)
            ->assertJsonPath('data.reversal_movement', null);
    }

    public function test_stock_movement_show_returns_404_for_unknown_id(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];

        $this->getJson('/api/v1/stock-movements/'.Str::uuid(), $headers)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_stock_movement_trace_by_code_returns_404_for_unknown_physical_code(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];

        $this->getJson('/api/v1/stock-movements/trace-by-code?code=DOES-NOT-EXIST', $headers)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_stock_movements_endpoints_require_inventory_permissions(): void
    {
        $role = Role::create([
            'role_code' => 'NO_INVENTORY_ACCESS',
            'role_name' => 'No inventory access',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'wwhn191-noaccess-'.Str::lower(Str::random(6)),
            'display_name' => 'No Access Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        foreach ([
            ['GET', '/api/v1/inventory/balances'],
            ['GET', '/api/v1/stock-movements'],
        ] as [$method, $url]) {
            $this->getJson($url, $headers)
                ->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error_code', 'ACCESS_DENIED');
        }
    }

    public function test_stock_movements_endpoint_requires_authentication(): void
    {
        // No Authorization header and no prior auth('api')->login() call in this test —
        // isolated from other tests' JWT guard state.
        $this->getJson('/api/v1/stock-movements')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_stock_movement_is_append_only_and_rejects_update_and_delete(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-MV-002',
            'sku_name' => 'Component append-only test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 2, 'BOX-MV-002', 'wwhn191-movement-002');
        $movement = StockMovement::where('stock_document_id', $documentId)->firstOrFail();

        $this->expectException(\LogicException::class);
        $movement->update(['qty_delta' => 999]);
    }

    public function test_stock_movement_delete_is_rejected(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-MV-003',
            'sku_name' => 'Component append-only delete test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 2, 'BOX-MV-003', 'wwhn191-movement-003');
        $movement = StockMovement::where('stock_document_id', $documentId)->firstOrFail();

        $this->expectException(\LogicException::class);
        $movement->delete();
    }
}
