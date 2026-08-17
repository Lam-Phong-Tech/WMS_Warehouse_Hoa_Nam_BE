<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Flow 06 · Báo cáo — khoá các điểm hợp đồng đã đối chiếu lại với SRS/FRD v1.12
 * ngày 17/08/2026 (§13.8 Privacy Contract, §13.10 Report Contract, §5.6 BR-EXP,
 * Annex C/D). Test này bổ sung cho ReportFlow06IntegrationTest chứ không thay thế.
 */
class ReportFlow06DocsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Warehouse $warehouse;

    private Sku $product;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user, $this->token] = $this->createSuperAdmin();
        $this->warehouse = $this->ensureActiveWarehouse('KHO-DOC', 'Kho test docs');
        $this->product = Sku::create([
            'code' => 'SP-DOC-A', 'name' => 'Sản phẩm docs', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);
    }

    // ── §13.10 RPT-INV-01: as_of inclusive ───────────────────

    /**
     * as_of ở quá khứ cắt ledger tới mốc đó nhưng inventory_balances vẫn là hiện
     * tại; hiệu hai vế KHÔNG phải divergence nên không được báo MISMATCH.
     */
    public function test_as_of_returns_ledger_snapshot_without_false_mismatch(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 30, 'available_qty' => 30,
            'reserved_qty' => 0, 'version' => 1,
        ]);
        $this->createMovement('OPENING', 10, now()->subDays(5)->toDateString());
        $this->createMovement('RECEIPT', 20, now()->toDateString());

        $asOf = now()->subDays(2)->toDateString();
        $response = $this->api("/api/v1/reports/inventory-balance?as_of={$asOf}");

        $response->assertOk()
            ->assertJsonPath('data.as_of', $asOf)
            ->assertJsonPath('data.as_of_snapshot', true)
            ->assertJsonPath('data.summary.mismatch_count', 0)
            ->assertJsonPath('data.rows.0.reconciliation_status', 'NOT_APPLICABLE')
            // Tồn hiển thị là số dư theo ledger tại mốc, không phải tồn hiện tại.
            ->assertJsonPath('data.rows.0.on_hand_qty', 10)
            ->assertJsonPath('data.rows.0.current_on_hand_qty', 30);
    }

    public function test_without_as_of_the_ledger_balance_check_still_reports_mismatch(): void
    {
        InventoryBalance::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE', 'on_hand_qty' => 30, 'available_qty' => 30,
            'reserved_qty' => 0, 'version' => 1,
        ]);
        $this->createMovement('RECEIPT', 20);

        $this->api('/api/v1/reports/inventory-balance')
            ->assertOk()
            ->assertJsonPath('data.as_of_snapshot', false)
            ->assertJsonPath('data.summary.mismatch_count', 1)
            ->assertJsonPath('data.rows.0.reconciliation_status', 'MISMATCH');
    }

    // ── §13.10 RPT-OUT-MATCH-01: tầng dòng ───────────────────

    public function test_import_match_reports_request_lines_with_required_matched_scanned(): void
    {
        $this->seedOutboundRequestChain();

        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}");
        $response->assertOk();

        $row = $response->json('data.rows.0');
        $this->assertSame('TOA-DOC-001', $row['source_request_no']);
        $this->assertSame(3, $row['source_version']);
        $this->assertSame('SP-DOC-A', $row['sku_code']);
        $this->assertSame('Sản phẩm docs', $row['sku_name']);
        // JSON hoá float nguyên thành số nguyên nên so sánh theo giá trị.
        $this->assertEquals(10, $row['required_qty']);
        $this->assertEquals(10, $row['matched_qty']);
        $this->assertEquals(10, $row['scanned_qty']);
        $this->assertSame('VALID', $row['validation_status']);
        $this->assertSame('OUT-DOC-001', $row['outbound_no']);
        $this->assertTrue($row['is_fully_matched']);
        // Batch-level evidence không bị mất khi báo cáo chuyển sang tầng dòng.
        $this->assertSame(1, $response->json('data.summary.total_batches'));
        $this->assertNotEmpty($response->json('data.batches'));
    }

    public function test_import_match_filters_lines_by_sku_code(): void
    {
        $this->seedOutboundRequestChain();

        $today = now()->toDateString();
        $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}&sku_code=SP-DOC-A")
            ->assertOk()
            ->assertJsonPath('data.summary.total_lines', 1);

        $this->api("/api/v1/reports/import-match?from_date={$today}&to_date={$today}&sku_code=SP-KHONG-CO")
            ->assertOk()
            ->assertJsonPath('data.summary.total_lines', 0);
    }

    // ── §13.8 Privacy Contract ───────────────────────────────

    public function test_warranty_report_masks_customer_columns_by_default(): void
    {
        $defect = DefectCatalog::create(['defect_code' => 'DOC-01', 'defect_name' => 'Lỗi docs', 'status' => 'ACTIVE']);
        $this->createWarrantyCase('WC-DOC-001', 'RECEIVED', $defect->defect_id);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $response = $this->api("/api/v1/reports/warranty-defects?from_date={$from}&to_date={$to}");

        $response->assertOk()
            ->assertJsonPath('data.pii_included', false)
            ->assertJsonPath('data.pii_masked', true);

        $row = $response->json('data.rows.0');
        // §13.8: N*** H*** cho tên, giữ 3 số cuối cho SĐT — không bao giờ giá trị thô.
        $this->assertSame('K*** D***', $row['customer_name']);
        $this->assertSame('*******005', $row['customer_phone']);
        $this->assertStringNotContainsString('0900000005', json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    public function test_pii_export_requires_reason_and_records_it_in_audit(): void
    {
        $defect = DefectCatalog::create(['defect_code' => 'DOC-02', 'defect_name' => 'Lỗi docs 2', 'status' => 'ACTIVE']);
        $this->createWarrantyCase('WC-DOC-002', 'RECEIVED', $defect->defect_id);
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        // Không có lý do ⇒ file vẫn xuất được nhưng ở dạng mask, audit ghi rõ.
        $this->api("/api/v1/reports/warranty-defects/export-excel?from_date={$from}&to_date={$to}")->assertOk();
        $maskedPayloads = $this->warrantyExportAuditPayloads();
        $this->assertCount(1, $maskedPayloads);
        $this->assertFalse((bool) $maskedPayloads[0]['pii_included']);
        $this->assertNull($maskedPayloads[0]['pii_reason']);

        // Có quyền + lý do ⇒ PII được xuất và lý do là evidence bắt buộc trong audit.
        $reason = 'Doi soat khieu nai khach hang 8891';
        $this->api("/api/v1/reports/warranty-defects/export-excel?from_date={$from}&to_date={$to}&reason=".urlencode($reason))
            ->assertOk();
        $payloads = $this->warrantyExportAuditPayloads();
        $this->assertCount(2, $payloads);
        $withPii = collect($payloads)->firstWhere('pii_included', true);
        $this->assertNotNull($withPii, 'Không tìm thấy audit của lần export có PII.');
        $this->assertSame($reason, $withPii['pii_reason']);
        // §13.8 "Never log raw phone": lý do được lưu, bộ lọc không mang PII.
        $this->assertArrayNotHasKey('reason', $withPii['filters']);
    }

    public function test_pii_export_reason_is_rejected_when_too_short(): void
    {
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $this->api("/api/v1/reports/warranty-defects/export-excel?from_date={$from}&to_date={$to}&reason=abc")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PII_EXPORT_REASON_INVALID');
    }

    // ── §5.6 BR-EXP-03 ───────────────────────────────────────

    /** Tên file phải mang dấu thời gian để hai lần xuất không ghi đè nhau. */
    public function test_every_export_file_name_carries_a_timestamp(): void
    {
        $today = now()->toDateString();
        $stamp = '/-\d{8}-\d{6}\.(xlsx|pdf)"?$/';

        foreach ([
            "/api/v1/reports/inventory-balance/export-excel",
            "/api/v1/reports/inventory-xnt/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/document-history/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/movement-trace/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/outbound-by-agency/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/import-match/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/label-log/export-excel?from_date={$today}&to_date={$today}",
            "/api/v1/reports/warranty-defects/export-excel?from_date={$today}&to_date={$today}",
        ] as $url) {
            $disposition = (string) $this->api($url)->assertOk()->headers->get('content-disposition');
            $this->assertMatchesRegularExpression($stamp, $disposition, "Thiếu timestamp trong tên file của {$url}");
        }
    }

    // ── §13.10 RPT-LABEL-01: printer + actor ─────────────────

    public function test_label_log_exposes_printer_and_actor_and_filters_by_actor(): void
    {
        $today = now()->toDateString();
        $documentId = $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DOC-LBL');
        $snapshotId = $this->createLabelSnapshot($documentId);
        $printerId = $this->createPrinterConfig('PRN-DOC-01', 'Máy in kho docs');
        $this->createLabelPrintEvent($snapshotId, 'PRINTED', $printerId, (string) $this->user->user_id);

        $response = $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $row = $response->json('data.rows.0');
        $this->assertSame('PRN-DOC-01', $row['printer_code']);
        $this->assertSame('Máy in kho docs', $row['printer_name']);
        $this->assertSame('Report Admin', $row['printed_by_name']);

        $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}&actor_id={$this->user->user_id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_labels', 1);
        $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}&actor_id=".Str::uuid())
            ->assertOk()
            ->assertJsonPath('data.summary.total_labels', 0);
    }

    // ── §13.10 RPT-IO-01: filter agency recipient ────────────

    public function test_document_history_filters_by_agency_recipient(): void
    {
        $today = now()->toDateString();
        $agency = Agency::create(['agency_code' => 'DL-DOC-01', 'agency_name' => 'Đại lý docs', 'status' => 'ACTIVE']);
        $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DOC-AGY', $agency->agency_id);
        $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DOC-DIRECT');

        $this->api("/api/v1/reports/document-history?from_date={$today}&to_date={$today}")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 2);
        $this->api("/api/v1/reports/document-history?from_date={$today}&to_date={$today}&agency_id={$agency->agency_id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.rows.0.document_no', 'OUT-DOC-AGY');
    }

    // ── Annex C createReportExport ───────────────────────────

    /**
     * BR-EXP-01 "export dùng cùng filter/scope với màn hình": filter nằm trong body
     * nên job phải áp dụng chúng khi đếm dòng và phải mang chúng vào download_url.
     */
    public function test_export_job_applies_filters_from_request_body(): void
    {
        $today = now()->toDateString();
        $this->createStockDocument('issue', 'POSTED', $today, 'OUT-DOC-EXP-1');
        $this->createStockDocument('receipt', 'POSTED', $today, 'IN-DOC-EXP-1');

        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'document-history',
            'format' => 'xlsx',
            'filters' => ['from_date' => $today, 'to_date' => $today, 'doc_type' => 'outbound'],
        ], [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.filters.doc_type', 'outbound');
        $this->assertStringContainsString('doc_type=outbound', (string) $response->json('data.download_url'));
    }

    public function test_export_job_requires_idempotency_key(): void
    {
        $this->postJson('/api/v1/reports/export', [
            'report_code' => 'inventory-balance',
        ], ['Authorization' => "Bearer {$this->token}"])->assertStatus(428);
    }

    // ── §8.4 / Annex C: contract phải công bố filter ─────────

    /**
     * Regression guard cho lỗi cấu hình: nếu controller báo cáo bị loại khỏi
     * `l5-swagger.annotations` thì Swagger vẫn sinh path nhưng mất sạch query
     * parameter — client không còn biết bộ lọc §13.10 nào tồn tại.
     */
    public function test_swagger_publishes_report_filters_and_export_request_body(): void
    {
        Artisan::call('l5-swagger:generate');
        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

        $missing = [];
        foreach ($swagger['paths'] as $path => $operations) {
            if (! str_contains($path, '/reports/') || $path === '/api/v1/reports/hub') {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                if (($operation['parameters'] ?? []) === [] && ! isset($operation['requestBody'])) {
                    $missing[] = strtoupper($method).' '.$path;
                }
            }
        }
        $this->assertSame([], $missing, 'Endpoint báo cáo bị publish mà không có filter nào.');

        $exportBody = $swagger['paths']['/api/v1/reports/export']['post'] ?? [];
        $this->assertSame('createReportExport', $exportBody['operationId'] ?? null);
        $this->assertArrayHasKey('requestBody', $exportBody);
        $this->assertSame(
            'getWarrantyDefectReport',
            $swagger['paths']['/api/v1/reports/warranty-defects']['get']['operationId'] ?? null
        );
        $this->assertSame(
            'getInventoryLedgerReport',
            $swagger['paths']['/api/v1/reports/inventory-ledger']['get']['operationId'] ?? null
        );
        $this->assertSame(
            'getReportByCode',
            $swagger['paths']['/api/v1/reports/{report_code}']['get']['operationId'] ?? null
        );
    }

    /** Handler báo cáo legacy đã bị gỡ; không được để lại route/dead code song song. */
    public function test_legacy_report_handlers_are_gone(): void
    {
        $this->assertFileDoesNotExist(base_path('Modules/Warehouse/app/Http/Controllers/ReportController.php'));
        $this->assertFileDoesNotExist(base_path('Modules/Warehouse/app/Services/ReportService.php'));

        $controller = file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'));
        foreach (['function importMatch', 'function labelLog', 'buildImportMatch', 'buildLabelLog'] as $dead) {
            $this->assertStringNotContainsString($dead, $controller, "Còn dead code: {$dead}");
        }
    }

    // ══════════════════════════════════════════════════════════
    //  FIXTURES
    // ══════════════════════════════════════════════════════════

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
            'username' => 'docs-test-admin', 'display_name' => 'Report Admin',
            'email' => 'docs-test@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createMovement(string $type, float $qty, ?string $date = null): void
    {
        $date ??= now()->toDateString();
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:docs:'.Str::random(6),
            'movement_type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => abs($qty),
            'quantity' => abs($qty),
            'created_by' => $this->user->user_id,
            'occurred_at' => "{$date} 12:00:00",
            'created_at' => "{$date} 12:00:00",
        ]);
    }

    private function createStockDocument(string $docType, string $status, string $date, string $docNo, ?string $agencyId = null, ?string $requestId = null): string
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
            'outbound_request_id' => $requestId,
            'recipient_name' => $docType === 'issue' ? 'Người nhận docs' : null,
            'recipient_address' => $docType === 'issue' ? 'Địa chỉ docs' : null,
            'posted_at' => $status === 'POSTED' ? now() : null,
            'posted_by' => $status === 'POSTED' ? $this->user->user_id : null,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Dựng đủ chuỗi Excel → toa canonical → phiếu xuất → match cho RPT-OUT-MATCH-01. */
    private function seedOutboundRequestChain(): void
    {
        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => 'IMP-DOC-001',
            'source_file_name' => 'toa-docs.xlsx',
            'source_checksum' => md5('toa-docs'),
            'status' => 'CONSUMED',
            'total_rows' => 1,
            'valid_rows' => 1,
            'error_rows' => 0,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbound_request_import_rows')->insert([
            'id' => (string) Str::uuid(),
            'import_id' => $importId,
            'row_no' => 1,
            'validation_status' => 'VALID',
            'normalized_payload' => json_encode([
                'request_no' => 'TOA-DOC-001',
                'sku_id' => (string) $this->product->id,
                'sku_code' => 'SP-DOC-A',
                'required_qty' => 10,
                'recipient_name' => 'Người nhận docs',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => 'TOA-DOC-001',
            'import_id' => $importId,
            'recipient_name' => 'Người nhận docs',
            'recipient_address' => 'Địa chỉ docs',
            'request_date' => now()->toDateString(),
            'status' => 'CONSUMED',
            'version' => 3,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentId = $this->createStockDocument('issue', 'POSTED', now()->toDateString(), 'OUT-DOC-001', null, $requestId);
        $lineId = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'qty_planned' => 10,
            'qty_actual' => 10,
            'scanned_qty' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (string) Str::uuid();
        $itemRow = [
            'item_id' => $itemId,
            'sku_id' => $this->product->id,
            'serial_number' => 'SN-DOC-'.Str::upper(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('item_instances', 'status')) {
            $itemRow['status'] = 'IN_STOCK';
        }
        DB::table('item_instances')->insert($itemRow);

        DB::table('outbound_item_matches')->insert([
            'id' => (string) Str::uuid(),
            'outbound_line_id' => $lineId,
            'item_id' => $itemId,
            'quantity' => 10,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Audit payload của mọi lần xuất báo cáo bảo hành trong test hiện tại.
     *
     * @return list<array<string, mixed>>
     */
    private function warrantyExportAuditPayloads(): array
    {
        return DB::table('audit_log')
            ->where('action', 'WARRANTY_REPORT_EXPORTED')
            ->orderBy('created_at')
            ->pluck('after_data')
            ->map(fn ($payload): array => json_decode((string) $payload, true) ?: [])
            ->values()
            ->all();
    }

    private function createPrinterConfig(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            'printer_code' => $code,
            'printer_name' => $name,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        // ERD bảng 43 khai khổ nhãn NOT NULL; cột chỉ tồn tại trên nhánh PostgreSQL.
        foreach (['label_width_mm' => 50, 'label_height_mm' => 30] as $column => $value) {
            if (Schema::hasColumn('printer_configs', $column)) {
                $row[$column] = $value;
            }
        }
        DB::table('printer_configs')->insert($row);

        return $id;
    }

    private function createLabelSnapshot(string $documentId): string
    {
        $id = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $id,
            'outbound_document_id' => $documentId,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['label_code' => 'PKL-DOC-001', 'item_count' => 1, 'sku_count' => 1]),
            'status' => 'PRINTED',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createLabelPrintEvent(string $snapshotId, string $status, ?string $printerId, ?string $actorId): void
    {
        DB::table('label_print_events')->insert([
            'id' => (string) Str::uuid(),
            'label_snapshot_id' => $snapshotId,
            'printer_config_id' => $printerId,
            'status' => $status,
            'printed_at' => $status === 'PRINTED' ? now() : null,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createWarrantyCase(string $code, string $status, ?string $defectId = null): void
    {
        DB::table('warranty_case')->insert([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-'.Str::upper(Str::random(8)),
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan mô tả thủ công',
            'customer_name' => 'Khách Docs',
            'customer_phone' => '0900000005',
            'status' => $status,
            'defect_id' => $defectId,
            'version' => 1,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
