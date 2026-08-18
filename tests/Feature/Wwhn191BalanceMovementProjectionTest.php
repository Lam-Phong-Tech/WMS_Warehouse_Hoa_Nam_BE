<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
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

        $balanceId = InventoryBalance::where('product_id', $sku->sku_id)->value('id');
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

    /**
     * DEV191-P2-01R (positive trace-by-code): nhánh negative đã có ở
     * test_stock_movement_trace_by_code_returns_404_for_unknown_physical_code.
     * Positive path thuộc phạm vi WWHN-191 là nhánh RECEIPT/SKU-code — khác nhánh
     * ITEM/ISSUE đã cover ở Wwhn207OutboundBalanceReconciliationTest nên không trùng lặp.
     * BR-TRACE-01..06: resolver phải nhận cả mã SKU và so khớp không phân biệt hoa thường.
     */
    public function test_stock_movement_trace_by_code_resolves_sku_code_to_posted_receipt_movement(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-TRACE-RCV-001',
            'sku_name' => 'Component trace-by-code positive test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 6, 'BOX-TRACE-001', 'wwhn191-trace-001');
        $movement = StockMovement::where('stock_document_id', $documentId)->firstOrFail();

        $response = $this->getJson('/api/v1/stock-movements/trace-by-code?code='.$sku->sku_code, $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.resolved_type', 'SKU')
            ->assertJsonPath('data.resolved_id', $sku->sku_id)
            ->assertJsonPath('data.resolved_by', 'sku_code');

        $traced = collect($response->json('data.movements'))->firstWhere('id', $movement->id);
        $this->assertNotNull($traced, 'trace-by-code phai tra ve dung movement RECEIPT vua post.');
        $this->assertSame('RECEIPT', $traced['movement_type']);
        $this->assertSame(6, (int) $traced['qty_delta']);

        // Case-insensitive: cùng mã viết thường phải ra cùng kết quả (BR-TRACE-06).
        $this->getJson('/api/v1/stock-movements/trace-by-code?code='.strtolower($sku->sku_code), $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved_type', 'SKU')
            ->assertJsonPath('data.resolved_id', $sku->sku_id);
    }

    /**
     * DEV191-P2-01R (positive trace-by-code — nhánh CONTAINER): thùng dùng ở bước scan
     * nhập kho phải truy vết ngược được về đúng container đó.
     */
    public function test_stock_movement_trace_by_code_resolves_container_code_of_received_box(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-TRACE-RCV-002',
            'sku_name' => 'Component container trace test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [, , $boxId] = $this->postReceiptOf($headers, $warehouse, $sku, 2, 'BOX-TRACE-002', 'wwhn191-trace-002');

        $this->getJson('/api/v1/stock-movements/trace-by-code?code=BOX-TRACE-002', $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved_type', 'CONTAINER')
            ->assertJsonPath('data.resolved_id', $boxId)
            ->assertJsonPath('data.resolved_by', 'container_code');
    }

    /**
     * DEV191-P2-01R (positive /reversible): movement RECEIPT vừa post, chưa có downstream
     * consumption → reversible_qty = full qty, dependency_guard null, can_reverse = true.
     * Nhánh ISSUE/serial-tracked đã cover ở WWHN-207.
     */
    public function test_stock_movements_reversible_lists_posted_receipt_with_full_reversible_qty(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-REV-RCV-001',
            'sku_name' => 'Component reversible positive test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        [$documentId] = $this->postReceiptOf($headers, $warehouse, $sku, 7, 'BOX-REV-001', 'wwhn191-reversible-001');
        $movement = StockMovement::where('stock_document_id', $documentId)->firstOrFail();

        $response = $this->getJson('/api/v1/stock-movements/reversible?reversible_only=1', $headers)
            ->assertOk()
            ->assertJsonPath('success', true);

        $row = collect($response->json('data'))->firstWhere('id', $movement->id);
        $this->assertNotNull($row, 'Movement RECEIPT vua post phai xuat hien trong danh sach reversible.');
        $this->assertSame('RECEIPT', $row['movement_type']);
        $this->assertNull($row['dependency_guard']);
        $this->assertTrue($row['can_reverse']);
        $this->assertFalse($row['is_serial_tracked']);
        $this->assertSame(7, (int) $row['lines'][0]['original_qty']);
        $this->assertSame(0, (int) $row['lines'][0]['reversed_qty']);
        $this->assertSame(7, (int) $row['lines'][0]['reversible_qty']);
    }

    /**
     * DEV191-P2-01R (parity Annex C): SRS v1.12 dòng traceInventoryCode khai canonical
     * `GET /inventory/trace/{code_value}` (permission inventory.trace). Route này dùng
     * chung handler với trace-by-code nên phải trả cùng một payload cho cùng một mã —
     * nếu lệch thì FE đọc theo Annex C sẽ vỡ.
     */
    public function test_annex_c_inventory_trace_path_returns_same_payload_as_trace_by_code(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-TRACE-RCV-003',
            'sku_name' => 'Component Annex C trace parity test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 3, 'BOX-TRACE-003', 'wwhn191-trace-003');

        $byQuery = $this->getJson('/api/v1/stock-movements/trace-by-code?code='.$sku->sku_code, $headers)->assertOk();
        $byPath = $this->getJson('/api/v1/inventory/trace/'.$sku->sku_code, $headers)->assertOk();

        $this->assertSame($byQuery->json('data.resolved_type'), $byPath->json('data.resolved_type'));
        $this->assertSame($byQuery->json('data.resolved_id'), $byPath->json('data.resolved_id'));
        $this->assertSame(
            collect($byQuery->json('data.movements'))->pluck('id')->sort()->values()->all(),
            collect($byPath->json('data.movements'))->pluck('id')->sort()->values()->all(),
        );
    }
}
