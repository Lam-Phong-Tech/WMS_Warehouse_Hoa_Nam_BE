<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Flow 06 integration tests — real DB, real HTTP, no mocks.
 * Covers all 8 MVP reports + dashboard-quick + reconciliation.
 *
 * v2: actor names, RBAC 403, reconciliation evidence, by_agency contract,
 *     label aggregate, filter parity, keyword LIKE compatibility.
 */
class ReportFlow06IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Warehouse $warehouse;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user, $this->token] = $this->createSuperAdmin();
        $this->warehouse = Warehouse::create(['code' => 'KHO-RPT', 'name' => 'Kho test report', 'status' => 'ACTIVE']);
        $this->productA = Product::create(['code' => 'SP-RPT-A', 'name' => 'Sản phẩm A', 'status' => 'ACTIVE']);
        $this->productB = Product::create(['code' => 'SP-RPT-B', 'name' => 'Sản phẩm B', 'status' => 'ACTIVE']);
    }

    // ── 1. Inventory Balance ─────────────────────────────────

    public function test_inventory_balance_returns_rows_with_reconciliation(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 10, 'available_qty' => 8,
            'reserved_qty' => 2, 'version' => 1,
        ]);
        $this->createMovement('RECEIPT', $this->productA, 10);

        $response = $this->api("/api/v1/reports/inventory-balance?warehouse_id={$this->warehouse->id}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total_rows', 1)
            ->assertJsonPath('data.rows.0.warehouse_code', 'KHO-RPT')
            ->assertJsonPath('data.rows.0.product_code', 'SP-RPT-A')
            ->assertJsonPath('data.rows.0.on_hand_qty', 10)
            ->assertJsonPath('data.rows.0.reconciliation_status', 'MATCH');
    }

    public function test_inventory_balance_keyword_filter(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 5, 'available_qty' => 5,
            'reserved_qty' => 0, 'version' => 1,
        ]);
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productB->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 3, 'available_qty' => 3,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api('/api/v1/reports/inventory-balance?keyword=SP-RPT-A');
        $response->assertOk()->assertJsonPath('data.summary.total_rows', 1);
    }

    public function test_inventory_balance_keyword_case_insensitive(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 5, 'available_qty' => 5,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api('/api/v1/reports/inventory-balance?keyword=sp-rpt-a');
        $response->assertOk()->assertJsonPath('data.summary.total_rows', 1);
    }

    public function test_inventory_balance_export_returns_xlsx(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 1, 'available_qty' => 1,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api('/api/v1/reports/inventory-balance/export-excel');
        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── 2. XNT (Nhập-Xuất-Tồn) ──────────────────────────────

    public function test_xnt_computes_opening_receipt_issue_closing(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->createMovement('OPENING', $this->productA, 20, $yesterday);
        $this->createMovement('RECEIPT', $this->productA, 5, $today);
        $this->createMovement('ISSUE', $this->productA, -3, $today);

        $response = $this->api("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.formula', 'Closing = Opening + Receipt - Issue - Component Issue + Reversal');
        $lines = $response->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertEquals('SP-RPT-A', $lines[0]['product_code']);
        $this->assertEquals(20, $lines[0]['opening']);
        $this->assertEquals(5, $lines[0]['receipt']);
        $this->assertEquals(3, $lines[0]['issue']);
        $this->assertEquals(22, $lines[0]['closing']);
    }

    public function test_xnt_keyword_filters_by_product(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 10, $today);
        $this->createMovement('RECEIPT', $this->productB, 5, $today);

        $response = $this->api("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}&keyword=RPT-A");
        $response->assertOk();
        $lines = $response->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertEquals('SP-RPT-A', $lines[0]['product_code']);
    }

    public function test_xnt_enhanced_same_as_xnt(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 7, $today);

        $xnt = $this->api("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}")->json('data.lines');
        $enhanced = $this->api("/api/v1/reports/inventory-xnt-enhanced?from_date={$today}&to_date={$today}")->json('data.lines');
        $this->assertEquals($xnt, $enhanced);
    }

    public function test_xnt_export_returns_xlsx(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 1, $today);

        $this->api("/api/v1/reports/inventory-xnt/export-excel?from_date={$today}&to_date={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── 3. Document History ──────────────────────────────────

    public function test_document_history_returns_inbound_and_outbound(): void
    {
        $today = now()->toDateString();
        $this->createStockDocument('receipt', 'POSTED', $today, 'IN-RPT-001');
        $this->createStockDocument('issue', 'POSTED', $today, 'OUT-RPT-001');

        $response = $this->api("/api/v1/reports/document-history?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.posted', 2);
    }

    public function test_document_history_filters_by_doc_type(): void
    {
        $today = now()->toDateString();
        $this->createStockDocument('receipt', 'POSTED', $today, 'IN-RPT-002');
        $this->createStockDocument('issue', 'POSTED', $today, 'OUT-RPT-002');

        $response = $this->api("/api/v1/reports/document-history?from_date={$today}&to_date={$today}&doc_type=inbound");
        $response->assertOk()->assertJsonPath('data.summary.total', 1);
        $this->assertEquals('INBOUND', $response->json('data.rows.0.doc_type'));
    }

    public function test_document_history_includes_actor_names(): void
    {
        $today = now()->toDateString();
        $this->createStockDocument('receipt', 'POSTED', $today, 'IN-RPT-ACTOR');

        $response = $this->api("/api/v1/reports/document-history?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $row = $response->json('data.rows.0');
        $this->assertArrayHasKey('created_by_name', $row);
        $this->assertArrayHasKey('posted_by_name', $row);
        $this->assertEquals('Report Admin', $row['created_by_name']);
    }

    public function test_document_history_export_returns_xlsx(): void
    {
        $today = now()->toDateString();
        $this->createStockDocument('receipt', 'POSTED', $today, 'IN-RPT-EXPORT');

        $this->api("/api/v1/reports/document-history/export-excel?from_date={$today}&to_date={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── 4. Movement Trace ────────────────────────────────────

    public function test_movement_trace_returns_movements_with_summary(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 10, $today);
        $this->createMovement('ISSUE', $this->productA, -3, $today);

        $response = $this->api("/api/v1/reports/movement-trace?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total_movements', 2)
            ->assertJsonPath('data.summary.net_effect', 7);
    }

    public function test_movement_trace_filters_by_movement_type(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 10, $today);
        $this->createMovement('ISSUE', $this->productA, -3, $today);
        $this->createMovement('REVERSAL', $this->productA, 3, $today);

        $response = $this->api("/api/v1/reports/movement-trace?from_date={$today}&to_date={$today}&movement_type=REVERSAL");
        $response->assertOk()->assertJsonPath('data.summary.total_movements', 1);
    }

    // ── 5. Import Match ──────────────────────────────────────

    public function test_import_match_returns_batches_with_summary(): void
    {
        $this->createOutboundRequestImport('CONSUMED', 'batch-001.xlsx', 100, 95, 5);
        $this->createOutboundRequestImport('REJECTED', 'batch-002.xlsx', 50, 10, 40);

        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total_batches', 2)
            ->assertJsonPath('data.summary.consumed', 1)
            ->assertJsonPath('data.summary.rejected', 1);
    }

    public function test_import_match_keyword_filter(): void
    {
        $this->createOutboundRequestImport('CONSUMED', 'alpha-batch.xlsx', 10, 10, 0);
        $this->createOutboundRequestImport('CONSUMED', 'beta-batch.xlsx', 10, 10, 0);

        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}&keyword=alpha");
        $response->assertOk()->assertJsonPath('data.summary.total_batches', 1);
    }

    public function test_import_match_error_status_filter(): void
    {
        $this->createOutboundRequestImport('CONSUMED', 'clean.xlsx', 10, 10, 0);
        $dirtyImportId = $this->createOutboundRequestImport('VALIDATED', 'dirty.xlsx', 10, 5, 5);
        DB::table('outbound_request_import_rows')->insert([
            'id' => (string) Str::uuid(),
            'import_id' => $dirtyImportId,
            'row_no' => 1,
            'validation_status' => 'ERROR',
            'created_at' => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}&error_status=ERROR");
        $response->assertOk()->assertJsonPath('data.summary.total_batches', 1);
    }

    public function test_import_match_includes_actor_names(): void
    {
        $this->createOutboundRequestImport('CONSUMED', 'actor-test.xlsx', 10, 10, 0);

        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $row = $response->json('data.rows.0');
        $this->assertArrayHasKey('uploaded_by_name', $row);
        $this->assertArrayHasKey('consumed_by_name', $row);
    }

    // ── 6. Packing Label (Label Log) ─────────────────────────

    public function test_label_log_returns_packing_label_rows(): void
    {
        $today = now()->toDateString();
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-LABEL-001');
        $lineId = $this->createDocumentLine($docId, $this->productA->id, 5);
        $this->createOutboundItemMatch($lineId);

        $snapshotId = $this->createLabelSnapshot($docId);
        $this->createLabelPrintEvent($snapshotId, 'PRINTED');

        $response = $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['summary']['total_labels']);
        $this->assertNotEmpty($data['rows']);
        $row = $data['rows'][0];
        $this->assertArrayHasKey('outbound_no', $row);
        $this->assertArrayHasKey('outbound_date', $row);
        $this->assertArrayHasKey('recipient', $row);
        $this->assertArrayHasKey('label_count', $row);
        $this->assertArrayHasKey('item_count', $row);
        $this->assertArrayHasKey('sku_count', $row);
        $this->assertArrayHasKey('sku_summary', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('total_attempts', $row);
        $this->assertArrayHasKey('failed_count', $row);
        $this->assertArrayHasKey('reprint_count', $row);
        $this->assertEquals('OUT-LABEL-001', $row['outbound_no']);
    }

    /** Label là document-level (1 label/document); payload gộp item_count/sku_count cho mọi match của lô. */
    public function test_label_log_aggregates_by_outbound_document(): void
    {
        $today = now()->toDateString();
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-AGG-001');
        $lineId = $this->createDocumentLine($docId, $this->productA->id, 5);
        $this->createOutboundItemMatch($lineId);
        $this->createOutboundItemMatch($lineId, true);

        $this->createLabelSnapshot($docId, ['item_count' => 2, 'sku_count' => 2]);

        $response = $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $rows = $response->json('data.rows');
        $docRows = collect($rows)->where('outbound_no', 'OUT-AGG-001');
        $this->assertCount(1, $docRows);
        $this->assertEquals(1, $docRows->first()['label_count']);
        $this->assertEquals(2, $docRows->first()['item_count']);
        $this->assertEquals(2, $docRows->first()['sku_count']);
    }

    public function test_label_log_export_returns_xlsx(): void
    {
        $today = now()->toDateString();
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-LABEL-EXP');
        $lineId = $this->createDocumentLine($docId, $this->productA->id, 1);
        $this->createOutboundItemMatch($lineId);
        $this->createLabelSnapshot($docId);

        $this->api("/api/v1/reports/label-log/export-excel?from_date={$today}&to_date={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── 7. Outbound by Agency ────────────────────────────────

    public function test_outbound_by_agency_returns_agency_summary(): void
    {
        $today = now()->toDateString();
        $agency = Agency::create([
            'agency_code' => 'DL-RPT-01', 'agency_name' => 'Đại lý test', 'status' => 'ACTIVE',
        ]);
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-AG-001', $agency->agency_id);
        $this->createDocumentLine($docId, $this->productA->id, 10, 8);

        $response = $this->api("/api/v1/reports/outbound-by-agency?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total_agencies', 1)
            ->assertJsonPath('data.summary.total_documents', 1);
        $this->assertEquals('DL-RPT-01', $response->json('data.rows.0.agency_code'));
    }

    public function test_outbound_by_agency_by_agency_at_top_level(): void
    {
        $today = now()->toDateString();
        $agency = Agency::create([
            'agency_code' => 'DL-TOP', 'agency_name' => 'Top Level Test', 'status' => 'ACTIVE',
        ]);
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-TOP-001', $agency->agency_id);
        $this->createDocumentLine($docId, $this->productA->id, 5, 5);

        $response = $this->api("/api/v1/reports/outbound-by-agency?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('by_agency', $data);
        $this->assertArrayNotHasKey('by_agency', $data['summary']);
        $this->assertCount(1, $data['by_agency']);
        $this->assertEquals('DL-TOP', $data['by_agency'][0]['agency_code']);
    }

    public function test_outbound_by_agency_filters_by_agency(): void
    {
        $today = now()->toDateString();
        $agencyA = Agency::create(['agency_code' => 'DL-A', 'agency_name' => 'Agency A', 'status' => 'ACTIVE']);
        $agencyB = Agency::create(['agency_code' => 'DL-B', 'agency_name' => 'Agency B', 'status' => 'ACTIVE']);
        $docA = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DLA-001', $agencyA->agency_id);
        $this->createDocumentLine($docA, $this->productA->id, 5, 5);
        $docB = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DLB-001', $agencyB->agency_id);
        $this->createDocumentLine($docB, $this->productA->id, 3, 3);

        $response = $this->api("/api/v1/reports/outbound-by-agency?from_date={$today}&to_date={$today}&agency_id={$agencyA->agency_id}");
        $response->assertOk()->assertJsonPath('data.summary.total_documents', 1);
        $this->assertEquals('DL-A', $response->json('data.rows.0.agency_code'));
    }

    public function test_outbound_by_agency_keyword_filter(): void
    {
        $today = now()->toDateString();
        $agency = Agency::create(['agency_code' => 'DL-KW', 'agency_name' => 'Đại lý keyword', 'status' => 'ACTIVE']);
        $doc = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-KW-001', $agency->agency_id);
        $this->createDocumentLine($doc, $this->productA->id, 2, 2);

        $response = $this->api("/api/v1/reports/outbound-by-agency?from_date={$today}&to_date={$today}&keyword=RPT-A");
        $response->assertOk()->assertJsonPath('data.summary.total_documents', 1);
    }

    // ── 8. Warranty Defects ──────────────────────────────────

    public function test_warranty_defects_returns_cases_with_defect_breakdown(): void
    {
        $defect = DefectCatalog::create([
            'defect_code' => 'LH-01', 'defect_name' => 'Lỗi hỏng', 'status' => 'ACTIVE',
        ]);
        $this->createWarrantyCase('WC-RPT-001', 'RECEIVED', $defect->defect_id);
        $this->createWarrantyCase('WC-RPT-002', 'INSPECTING', $defect->defect_id);

        $today = now()->toDateString();
        $from = now()->subDays(1)->toDateString();
        $response = $this->api("/api/v1/reports/warranty-defects?from_date={$from}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.pii_included', false);
        $this->assertNotEmpty($response->json('data.by_defect'));
    }

    public function test_warranty_defects_filters_by_status(): void
    {
        $defect = DefectCatalog::create([
            'defect_code' => 'LH-FLT', 'defect_name' => 'Filter test', 'status' => 'ACTIVE',
        ]);
        $this->createWarrantyCase('WC-FLT-001', 'RECEIVED', $defect->defect_id);
        $this->createWarrantyCase('WC-FLT-002', 'RETURNED', $defect->defect_id);

        $today = now()->toDateString();
        $from = now()->subDays(1)->toDateString();
        $response = $this->api("/api/v1/reports/warranty-defects?from_date={$from}&to_date={$today}&status=RECEIVED");
        $response->assertOk()->assertJsonPath('data.summary.total', 1);
    }

    public function test_warranty_defects_export_returns_xlsx(): void
    {
        $defect = DefectCatalog::create([
            'defect_code' => 'LH-EXP', 'defect_name' => 'Export test', 'status' => 'ACTIVE',
        ]);
        $this->createWarrantyCase('WC-EXP-001', 'RECEIVED', $defect->defect_id);

        $today = now()->toDateString();
        $from = now()->subDays(1)->toDateString();
        $this->api("/api/v1/reports/warranty-defects/export-excel?from_date={$from}&to_date={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── 9. Reconciliation Evidence ──────────────────────────

    public function test_reconciliation_opening_plus_receipt_minus_issue_equals_closing(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->createMovement('OPENING', $this->productA, 100, $yesterday);
        $this->createMovement('RECEIPT', $this->productA, 30, $today);
        $this->createMovement('ISSUE', $this->productA, -20, $today);
        $this->createMovement('COMPONENT_ISSUE', $this->productA, -5, $today);
        $this->createMovement('REVERSAL', $this->productA, 10, $today);

        $response = $this->api("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $line = $response->json('data.lines.0');

        $this->assertEquals(100, $line['opening']);
        $this->assertEquals(30, $line['receipt']);
        $this->assertEquals(20, $line['issue']);
        $this->assertEquals(5, $line['component_issue']);
        $this->assertEquals(10, $line['reversal_net']);
        $expected = 100 + 30 - 20 - 5 + 10;
        $this->assertEquals($expected, $line['closing']);
    }

    public function test_reconciliation_balance_vs_ledger_match(): void
    {
        $this->createMovement('RECEIPT', $this->productA, 50);
        $this->createMovement('ISSUE', $this->productA, -15);
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 35, 'available_qty' => 35,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api('/api/v1/reports/inventory-reconciliation');
        $response->assertOk()
            ->assertJsonPath('data.summary.status', 'PASS')
            ->assertJsonPath('data.summary.mismatch_count', 0);
    }

    public function test_reconciliation_detects_mismatch(): void
    {
        $this->createMovement('RECEIPT', $this->productA, 50);
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 99, 'available_qty' => 99,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api('/api/v1/reports/inventory-reconciliation?only_mismatch=1');
        $response->assertOk()
            ->assertJsonPath('data.summary.status', 'FAIL')
            ->assertJsonPath('data.summary.mismatch_count', 1);
        $this->assertNotEmpty($response->json('data.mismatches'));
    }

    // ── 10. Dashboard Quick ──────────────────────────────────

    public function test_dashboard_quick_returns_aggregated_summary(): void
    {
        $today = now()->toDateString();
        $this->createMovement('RECEIPT', $this->productA, 15, $today);
        $this->createMovement('ISSUE', $this->productA, -4, $today);
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 11, 'available_qty' => 11,
            'reserved_qty' => 0, 'version' => 1,
        ]);

        $response = $this->api("/api/v1/reports/dashboard-quick?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.receipt_qty', 15)
            ->assertJsonPath('data.issue_qty', 4)
            ->assertJsonPath('data.available_qty', 11);
    }

    // ── 11. Hub ──────────────────────────────────────────────

    public function test_hub_returns_eight_mvp_reports(): void
    {
        $response = $this->api('/api/v1/reports/hub');
        $response->assertOk();
        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertContains('inventory-balance', $keys);
        $this->assertContains('inventory-xnt', $keys);
        $this->assertContains('document-history', $keys);
        $this->assertContains('movement-trace', $keys);
        $this->assertContains('import-match', $keys);
        $this->assertContains('label-log', $keys);
        $this->assertContains('warranty-defects', $keys);
        $this->assertContains('outbound-by-agency', $keys);
    }

    // ── 12. Server-side Pagination ─────────────────────────

    public function test_pagination_returns_subset_when_per_page_specified(): void
    {
        for ($i = 0; $i < 5; $i++) {
            InventoryBalance::create([
                'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
                'stock_status' => "STATUS_{$i}", 'on_hand_qty' => $i + 1, 'available_qty' => $i + 1,
                'reserved_qty' => 0, 'version' => 1,
            ]);
        }

        $response = $this->api('/api/v1/reports/inventory-balance?per_page=2&page=1');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data['rows']);
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(2, $data['pagination']['per_page']);
        $this->assertEquals(5, $data['pagination']['total']);
        $this->assertEquals(3, $data['pagination']['last_page']);
    }

    public function test_pagination_page_2_returns_correct_slice(): void
    {
        for ($i = 0; $i < 5; $i++) {
            InventoryBalance::create([
                'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
                'stock_status' => "STATUS_{$i}", 'on_hand_qty' => $i + 1, 'available_qty' => $i + 1,
                'reserved_qty' => 0, 'version' => 1,
            ]);
        }

        $response = $this->api('/api/v1/reports/inventory-balance?per_page=2&page=3');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data['rows']);
        $this->assertEquals(3, $data['pagination']['current_page']);
    }

    public function test_no_pagination_when_per_page_not_sent(): void
    {
        for ($i = 0; $i < 3; $i++) {
            InventoryBalance::create([
                'warehouse_id' => $this->warehouse->id, 'product_id' => $this->productA->id,
                'stock_status' => "STATUS_{$i}", 'on_hand_qty' => 1, 'available_qty' => 1,
                'reserved_qty' => 0, 'version' => 1,
            ]);
        }

        $response = $this->api('/api/v1/reports/inventory-balance');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data['rows']);
        $this->assertArrayNotHasKey('pagination', $data);
    }

    // ── 13. RBAC ─────────────────────────────────────────────

    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/hub')->assertUnauthorized();
        $this->getJson('/api/v1/reports/inventory-balance')->assertUnauthorized();
    }

    public function test_export_requires_report_export_permission(): void
    {
        $viewerRole = Role::create([
            'role_code' => 'REPORT_VIEWER', 'role_name' => 'Chỉ xem báo cáo',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $viewer = User::create([
            'username' => 'viewer-only', 'display_name' => 'Viewer',
            'email' => 'viewer@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $viewer->roles()->attach($viewerRole->role_id, ['user_role_id' => (string) Str::uuid()]);

        if (DB::getSchemaBuilder()->hasTable('role_permission')) {
            $viewPermission = DB::table('permission')->where('permission_key', 'report.view')->first();
            if ($viewPermission) {
                DB::table('role_permission')->insert([
                    'role_permission_id' => (string) Str::uuid(),
                    'role_id' => $viewerRole->role_id,
                    'permission_id' => $viewPermission->permission_id,
                ]);
            }
        }

        $viewerToken = JWTAuth::fromUser($viewer);
        $this->getJson('/api/v1/reports/inventory-balance/export-excel', [
            'Authorization' => "Bearer {$viewerToken}",
        ])->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function api(string $url)
    {
        return $this->getJson($url, ['Authorization' => "Bearer {$this->token}"]);
    }

    private function createSuperAdmin(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'report-test-admin', 'display_name' => 'Report Admin',
            'email' => 'report-test@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createMovement(string $type, Product $product, float $qty, ?string $date = null): void
    {
        $date ??= now()->toDateString();
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:rpt:'.Str::random(6),
            'movement_type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => abs($qty),
            'created_by' => $this->user->user_id,
            'occurred_at' => "{$date} 12:00:00",
            'created_at' => "{$date} 12:00:00",
        ]);
    }

    private function createStockDocument(string $docType, string $status, string $date, string $docNo, ?string $agencyId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $id,
            'doc_type' => $docType,
            'doc_no' => $docNo,
            'doc_date' => $date,
            'status' => $status,
            'src_warehouse_id' => $docType === 'issue' ? $this->warehouse->id : null,
            'dst_warehouse_id' => $docType === 'receipt' ? $this->warehouse->id : null,
            'agency_id' => $agencyId,
            'posted_at' => $status === 'POSTED' ? now() : null,
            'posted_by' => $status === 'POSTED' ? $this->user->user_id : null,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createDocumentLine(string $docId, string $productId, int $planned, int $scanned = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $id,
            'stock_document_id' => $docId,
            'line_no' => 1,
            'product_id' => $productId,
            'qty_planned' => $planned,
            'qty_actual' => $scanned,
            'scanned_qty' => $scanned,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createOutboundItemMatch(string $lineId, bool $newItem = false): string
    {
        $id = (string) Str::uuid();
        DB::table('outbound_item_matches')->insert([
            'id' => $id,
            'outbound_line_id' => $lineId,
            'item_id' => (string) Str::uuid(),
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Từ migration 2026_08_15_140000 (flow06), label là document-level:
     * đúng một label_snapshots cho mỗi outbound_document
     * (uk_label_snapshots_outbound_document); outbound_item_match_id/sku_id
     * chỉ giữ lại cho snapshot lịch sử.
     */
    private function createLabelSnapshot(string $documentId, array $payload = []): string
    {
        $id = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $id,
            'outbound_document_id' => $documentId,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode($payload + ['test' => true]),
            'status' => 'PRINTED',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createLabelPrintEvent(string $snapshotId, string $status): void
    {
        DB::table('label_print_events')->insert([
            'id' => (string) Str::uuid(),
            'label_snapshot_id' => $snapshotId,
            'status' => $status,
            'created_at' => now(),
        ]);
    }

    private function createOutboundRequestImport(string $status, string $fileName, int $total, int $valid, int $errors): string
    {
        $id = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $id,
            'import_no' => 'IMP-'.Str::random(6),
            'source_file_name' => $fileName,
            'source_checksum' => md5($fileName.Str::random(8)),
            'status' => $status,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => $errors,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createWarrantyCase(string $code, string $status, ?string $defectId = null): void
    {
        DB::table('warranty_case')->insert([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'identification_status' => 'IDENTIFIED',
            'status' => $status,
            'defect_id' => $defectId,
            'version' => 1,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
