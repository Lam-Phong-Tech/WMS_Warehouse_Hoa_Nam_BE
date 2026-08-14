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
        $today = now()->toDateString();
        $this->createOutboundRequestImport('CONSUMED', 'batch-001.xlsx', 100, 95, 5);
        $this->createOutboundRequestImport('REJECTED', 'batch-002.xlsx', 50, 10, 40);

        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}");
        $response->assertOk()
            ->assertJsonPath('data.summary.total_batches', 2)
            ->assertJsonPath('data.summary.consumed', 1)
            ->assertJsonPath('data.summary.rejected', 1);
    }

    public function test_import_match_keyword_filter(): void
    {
        $today = now()->toDateString();
        $this->createOutboundRequestImport('CONSUMED', 'alpha-batch.xlsx', 10, 10, 0);
        $this->createOutboundRequestImport('CONSUMED', 'beta-batch.xlsx', 10, 10, 0);

        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}&keyword=alpha");
        $response->assertOk()->assertJsonPath('data.summary.total_batches', 1);
    }

    // ── 6. Packing Label (Label Log) ─────────────────────────

    public function test_label_log_returns_packing_label_rows(): void
    {
        $today = now()->toDateString();
        $docId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-LABEL-001');
        $lineId = $this->createDocumentLine($docId, $this->productA->id, 5);
        $matchId = $this->createOutboundItemMatch($lineId);

        $snapshotId = $this->createLabelSnapshot($this->productA->id, $matchId);
        $this->createLabelPrintEvent($snapshotId, 'PRINTED');

        $response = $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['summary']['total_labels']);
        $this->assertNotEmpty($data['rows']);
        $row = $data['rows'][0];
        $this->assertArrayHasKey('outbound_no', $row);
        $this->assertArrayHasKey('label_count', $row);
        $this->assertArrayHasKey('sku_count', $row);
        $this->assertArrayHasKey('sku_summary', $row);
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

    // ── Dashboard Quick ──────────────────────────────────────

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

    // ── Hub ──────────────────────────────────────────────────

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

    // ── Permission Guard ─────────────────────────────────────

    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/hub')->assertUnauthorized();
        $this->getJson('/api/v1/reports/inventory-balance')->assertUnauthorized();
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
            'status' => strtolower($status),
            'src_warehouse_id' => $docType === 'issue' ? $this->warehouse->id : null,
            'dst_warehouse_id' => $docType === 'receipt' ? $this->warehouse->id : null,
            'agency_id' => $agencyId,
            'posted_at' => $status === 'POSTED' ? now() : null,
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

    private function createOutboundItemMatch(string $lineId): string
    {
        $id = (string) Str::uuid();
        DB::table('outbound_item_matches')->insert([
            'id' => $id,
            'outbound_line_id' => $lineId,
            'item_id' => (string) Str::uuid(),
            'scanned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createLabelSnapshot(string $productId, ?string $matchId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $id,
            'sku_id' => $productId,
            'item_id' => (string) Str::uuid(),
            'outbound_item_match_id' => $matchId,
            'status' => 'PRINTED',
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

    private function createOutboundRequestImport(string $status, string $fileName, int $total, int $valid, int $errors): void
    {
        DB::table('outbound_request_imports')->insert([
            'id' => (string) Str::uuid(),
            'import_no' => 'IMP-'.Str::random(6),
            'source_file_name' => $fileName,
            'status' => $status,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => $errors,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
