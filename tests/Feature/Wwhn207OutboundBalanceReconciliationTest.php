<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-207 (BE2): verifies the movement/balance projection endpoints that support
 * reconciliation — GET /api/v1/inventory/balances, .../subledger, /api/v1/stock-movements*,
 * /api/v1/reports/inventory-reconciliation and `inventory:reconcile` — actually reflect the
 * authoritative stock_movements ledger after a real post-issue (outbound) HTTP flow.
 * Mirrors Wwhn191BalanceMovementProjectionTest (BE2, inbound/post-receipt side) but drives
 * the outbound/ISSUE side, which had no HTTP-level balance/reconciliation coverage before.
 *
 * `PreparesOutboundFixtures::seedAvailableBalance()` writes an opening `inventory_balances`
 * row directly (no matching `stock_movements` row) — that is fine for tests that only need
 * "some stock exists", but it makes the row permanently ledger-unbacked, so
 * `reconciliation_status` will legitimately read MISMATCH for that dimension (the ledger sum
 * is short exactly the un-ledgered opening balance). Tests that assert MATCH use
 * `seedLedgerBackedBalance()` below instead, which inserts a RECEIPT movement alongside the
 * balance so on_hand_qty and SUM(qty_delta) agree, matching how production balances are
 * always created (via post-receipt, never a raw insert).
 */
class Wwhn207OutboundBalanceReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use PreparesOutboundFixtures;

    private function seedLedgerBackedBalance(string $warehouseId, string $skuId, int $qty, string $actorId): void
    {
        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => 'AVAILABLE',
            'quantity' => $qty,
            'on_hand_qty' => $qty,
            'available_qty' => $qty,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'movement_no' => 'MV-SEED-'.Str::upper(Str::random(8)),
            'business_key' => 'seed-opening-'.Str::uuid(),
            'movement_type' => 'RECEIPT',
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => 'AVAILABLE',
            'quantity' => $qty,
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => $qty,
            'source_type' => 'OPENING_BALANCE_SEED',
            'source_id' => (string) Str::uuid(),
            'created_by' => $actorId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Same setup as PreparesOutboundFixtures::readyToPostIssueDocument(), but ledger-backed. */
    private function readyToPostIssueDocumentLedgerBacked(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedLedgerBackedBalance($warehouse->id, $sku->sku_id, 1, $headers['__user_id']);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->markLabelsPrinted($documentId);

        return ['document_id' => $documentId, 'sku_id' => $sku->sku_id, 'item_id' => $itemId, 'warehouse_id' => $warehouse->id];
    }

    public function test_inventory_balances_list_reflects_ledger_after_post_issue_and_matches(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'COMP-BAL-ISSUE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-balance-001',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $response = $this->getJson('/api/v1/inventory/balances?product_id='.$doc['sku_id'], $headers)->assertOk();
        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('product_id', $doc['sku_id']);

        $this->assertNotNull($row, 'Expected a balance row for the posted SKU.');
        $this->assertSame(0.0, (float) $row['on_hand_qty']);
        $this->assertSame(0.0, (float) $row['available_qty']);
        $this->assertSame('MATCH', $row['reconciliation_status']);
        $this->assertNull($row['mismatch_reason']);
        $this->assertSame(0.0, (float) $row['variance']);
        $this->assertNull($row['incident_id']);

        $movement = DB::table('stock_movements')->where('stock_document_id', $documentId)->first();
        $this->assertNotNull($movement);
        $this->assertSame('ISSUE', $movement->movement_type);
        $this->assertSame(-1, (int) $movement->qty_delta);
    }

    public function test_stock_movements_list_show_and_trace_source_reflect_posted_issue(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-MV-ISSUE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-movement-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')->where('stock_document_id', $documentId)->where('movement_type', 'ISSUE')->first();
        $this->assertNotNull($movement);

        $list = $this->getJson(
            "/api/v1/stock-movements?warehouse_id={$doc['warehouse_id']}&product_id={$doc['sku_id']}&movement_type=ISSUE",
            $headers
        )->assertOk();
        $listIds = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($movement->id, $listIds);

        $this->getJson("/api/v1/stock-movements/{$movement->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $movement->id)
            ->assertJsonPath('data.movement_type', 'ISSUE')
            ->assertJsonPath('data.qty_delta', -1);

        $this->getJson("/api/v1/stock-movements/{$movement->id}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.movement.id', $movement->id)
            ->assertJsonPath('data.document.id', $documentId)
            ->assertJsonPath('data.original_movement', null)
            ->assertJsonPath('data.reversal_movement', null);
    }

    /**
     * Regression test for a real bug found while writing this coverage: stock_movements is
     * posted per document-line (aggregate qty) and mutateBalance() never set item_id on the
     * row, so trace-by-code's direct `stock_movements.item_id = ?` lookup always returned
     * empty for a real issued item. Fixed in StockMovementController::traceByPhysicalCode()
     * to fall back to resolving the item's scanned/matched line and the movement(s) posted
     * for that line (outbound_item_matches -> stock_document_lines -> stock_movements).
     */
    public function test_stock_movement_trace_by_code_resolves_issued_item_and_its_movement(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-TRACE-ISSUE-001');
        $documentId = $doc['document_id'];
        $itemId = $doc['item_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-trace-001',
        ])->assertOk();

        $codeValue = DB::table('physical_codes')->where('assigned_object_id', $itemId)->value('code_value');
        $this->assertNotNull($codeValue, 'Fixture must have seeded an RFID physical_code for the issued item.');

        $response = $this->getJson('/api/v1/stock-movements/trace-by-code?code='.$codeValue, $headers)->assertOk();

        $response->assertJsonPath('data.resolved_type', 'ITEM')
            ->assertJsonPath('data.resolved_id', $itemId);

        $movementIds = collect($response->json('data.movements'))->pluck('id')->all();
        $movementId = DB::table('stock_movements')->where('stock_document_id', $documentId)->where('movement_type', 'ISSUE')->value('id');
        $this->assertContains($movementId, $movementIds, 'trace-by-code must surface the ISSUE movement for the physical code owner item.');

        $this->assertDatabaseHas('item_instances', ['item_id' => $itemId, 'item_status' => 'ISSUED']);
    }

    /**
     * Regression test for a real bug found while writing this coverage:
     * buildItemDependencyMap() queried item_instances.status/`.id`, columns that only exist
     * as one-time-backfilled pgsql compatibility aliases (never kept in sync going forward —
     * see migration 2026_08_10_000760's backfillSameTableAliases(), pgsql-only). Every other
     * call site in the codebase uses the canonical item_status/item_id. Fixed to match.
     */
    public function test_stock_movements_reversible_lists_posted_issue_with_dependency_guard(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-REV-ISSUE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-reversible-001',
        ])->assertOk();

        $movementId = DB::table('stock_movements')->where('stock_document_id', $documentId)->where('movement_type', 'ISSUE')->value('id');

        $response = $this->getJson('/api/v1/stock-movements/reversible?reversible_only=1', $headers)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $movementId);

        $this->assertNotNull($row, 'Posted ISSUE movement must appear in the reversible listing.');
        $this->assertSame('ISSUE', $row['movement_type']);
        $this->assertTrue($row['can_reverse']);
        $this->assertSame(1, $row['lines'][0]['reversible_qty']);
    }

    public function test_reconciliation_report_and_command_show_match_after_post_issue(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'COMP-RECON-ISSUE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-recon-001',
        ])->assertOk();

        $this->getJson('/api/v1/reports/inventory-reconciliation?product_id='.$doc['sku_id'], $headers)
            ->assertOk();

        $this->artisan('inventory:reconcile', ['--dry-run' => false])->assertExitCode(0);

        $this->assertDatabaseMissing('reconciliation_incidents', [
            'sku_id' => $doc['sku_id'],
            'warehouse_id' => $doc['warehouse_id'],
            'status' => 'OPEN',
        ]);
    }

    public function test_reverse_after_post_issue_is_reflected_in_balances_and_movement_trace(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'COMP-REVERSE-ISSUE-001');
        $documentId = $doc['document_id'];
        $itemId = $doc['item_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-reverse-001',
        ])->assertOk();

        $issueMovementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'ISSUE')
            ->value('id');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Khách trả hàng WWHN-207',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => true,
            'lines' => [
                ['movement_id' => $issueMovementId, 'quantity' => 1, 'item_id' => $itemId],
            ],
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn207-reverse-doc-001',
        ])->assertOk()->assertJsonPath('data.original_document_status', 'POSTED');

        $response = $this->getJson('/api/v1/inventory/balances?product_id='.$doc['sku_id'], $headers)->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_id', $doc['sku_id']);
        $this->assertSame(1.0, (float) $row['on_hand_qty']);
        $this->assertSame('MATCH', $row['reconciliation_status']);

        $reversalMovementId = DB::table('stock_movements')->where('original_movement_id', $issueMovementId)->value('id');
        $this->assertNotNull($reversalMovementId, 'Reverse must append a new movement referencing the original ISSUE movement.');

        $this->getJson("/api/v1/stock-movements/{$issueMovementId}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.reversal_movement.id', $reversalMovementId);
    }

    public function test_inventory_balance_subledger_excludes_issued_item_after_post(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('COMP-SUB-ISSUE-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-SUB-ISSUE-001');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $issuedItemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($issuedItemId);
        $remainingItemId = $this->seedItemInStock($sku->sku_id);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $issuedItemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->markLabelsPrinted($documentId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-subledger-001',
        ])->assertOk();

        $balanceId = DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('id');
        $this->assertNotNull($balanceId);

        $response = $this->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)->assertOk();
        $itemIds = collect($response->json('data.items'))->pluck('id')->all();

        $this->assertContains($remainingItemId, $itemIds, 'Item still IN_STOCK must remain in the subledger.');
        $this->assertNotContains($issuedItemId, $itemIds, 'Issued item must be excluded from the available subledger.');
    }

    public function test_stock_movements_endpoints_require_inventory_permissions_for_outbound_data(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-PERM-ISSUE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn207-perm-001',
        ])->assertOk();

        $role = Role::create([
            'role_code' => 'WWHN207_NO_INVENTORY',
            'role_name' => 'No inventory access (WWHN-207)',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'wwhn207-noaccess-'.Str::lower(Str::random(6)),
            'display_name' => 'No Access Tester',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);
        $restrictedHeaders = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/inventory/balances', $restrictedHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');

        $this->getJson('/api/v1/stock-movements', $restrictedHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');
    }
}
