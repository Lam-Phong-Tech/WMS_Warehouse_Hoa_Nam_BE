<?php

namespace Tests\Feature;

use App\Support\Security\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyController as WarrantyContract;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Khóa các điểm luồng 04 · Bảo hành & linh kiện vừa được đưa về khớp docs:
 *
 *  - Annex C listWarrantyCases dùng `warranty.view` (LEADERSHIP/APPROVER đọc được, không ghi được).
 *  - Wireframe WAR-01: keyword tìm được cả serial item và Physical_Code.
 *  - §6: RETURNED/CANCELLED là terminal, mọi cập nhật sau đó 409.
 *  - Annex D SCR-WAR-01: defect/PII/purchase_date sửa được sau tiếp nhận, PII cần warranty.pii.view.
 *  - §13.8: retention_until chốt khi đóng case; ảnh phải qua content-type/malware check.
 *  - §13.10 RPT-WAR-01: đủ purchase_date/returned/turnaround + filter model, file export có timestamp.
 */
class WarrantyFlow04DocsAlignmentTest extends TestCase
{
    use OpensWarrantyCaseForComponentIssue;
    use RefreshDatabase;

    // ── ① permission đọc/ghi ───────────────────────────────────────────────

    public function test_warranty_view_role_can_read_but_cannot_mutate_cases(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W4');
        $caseId = $this->createTempOnlyCase($admin);

        $reader = $this->authHeaders('WARRANTY_READER', ['warranty.view']);

        $this->getJson('/api/v1/warranty-cases', $reader)->assertOk();
        $this->getJson("/api/v1/warranty-cases/{$caseId}", $reader)->assertOk();
        $this->getJson("/api/v1/warranty-cases/{$caseId}/events", $reader)->assertOk();
        $this->getJson("/api/v1/warranty-cases/{$caseId}/attachments", $reader)->assertOk();

        // Ghi vẫn phải là warranty.manage.
        $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khach Reader',
            'customer_phone' => '0900000009',
            'description' => 'Thu tao bang quyen view',
            'missing_code_reason' => 'Mat tem',
            'manual_product_description' => 'May khoan',
        ], $reader)->assertStatus(403);

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], $reader + ['If-Match' => '1'])->assertStatus(403);
    }

    public function test_reader_without_pii_permission_gets_masked_list_and_detail(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W4B');
        $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Nguyen Van Bao Mat',
            'customer_phone' => '0912349922',
            'customer_address' => '99 Lang Ha, Ha Noi',
            'description' => 'Khong len nguon',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May mai mau do',
        ], $admin)->assertCreated();

        $reader = $this->authHeaders('WARRANTY_READER_NO_PII', ['warranty.view']);
        $list = $this->getJson('/api/v1/warranty-cases', $reader)->assertOk();

        $row = $list->json('data.0');
        $this->assertNotSame('Nguyen Van Bao Mat', $row['customer_name']);
        $this->assertNotSame('0912349922', $row['customer_phone']);

        // §13.8: mask phải đi kèm chặn LIKE trên cột PII — tìm theo tên thật không ra.
        $probe = $this->getJson('/api/v1/warranty-cases?keyword=Bao+Mat', $reader)->assertOk();
        $this->assertCount(0, $probe->json('data'));
    }

    // ── ④ keyword theo mã máy ──────────────────────────────────────────────

    public function test_keyword_finds_case_by_item_serial_and_physical_code(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4C');
        $sku = Sku::create([
            'sku_code' => 'PROD-W4-KEY',
            'sku_name' => 'May khoan keyword',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-W4-KEYWORD-001',
            'item_status' => 'ISSUED',
        ]);
        PhysicalCode::create([
            'code_value' => 'PHY-W4-KEYWORD-001',
            'code_type' => 'ITEM',
            'carrier_type' => 'BARCODE',
            'status' => 'ACTIVE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $item->item_id,
        ]);

        $create = $this->postJson('/api/v1/warranty-cases', [
            'item_code' => 'SER-W4-KEYWORD-001',
            'customer_name' => 'Khach Keyword',
            'customer_phone' => '0900000011',
            'description' => 'Khong chay motor',
        ], $headers);
        $create->assertCreated();
        $caseId = (string) $create->json('data.id');

        foreach (['SER-W4-KEYWORD-001', 'PHY-W4-KEYWORD-001'] as $keyword) {
            $found = $this->getJson('/api/v1/warranty-cases?keyword='.$keyword, $headers)->assertOk();
            $this->assertCount(1, $found->json('data'), "Không tìm được case theo {$keyword}");
            $this->assertSame($caseId, (string) $found->json('data.0.warranty_case_id'));
        }

        // Keyword không được vô hiệu filter khác (OR phải nằm trong nhóm riêng).
        $filtered = $this->getJson(
            '/api/v1/warranty-cases?keyword=SER-W4-KEYWORD-001&identification_status=TEMP_ONLY',
            $headers
        )->assertOk();
        $this->assertCount(0, $filtered->json('data'));
    }

    /**
     * Ô tìm kiếm WAR-01 phải khớp đúng thứ người dùng đang NHÌN trên bảng: mã
     * phiếu ở cột "Phiếu" và mã/tên SKU ở cột "Sản phẩm".
     *
     * Nhánh mã phiếu từng chết IM LẶNG trên PostgreSQL — `warranty_case` là VIEW
     * nên `SchemaCache::hasColumn()` trả false cho mọi cột và quét sạch danh sách
     * cột keyword. sqlite (bộ test) coi `warranty_case` là bảng thật nên lớp test
     * KHÔNG tự phát hiện được lớp lỗi đó; phần view do
     * `SchemaCacheTest::test_relation_columns_also_sees_columns_of_a_view` giữ.
     * Test này giữ phần còn lại: nhánh SKU và việc keyword không đụng vào enum.
     */
    public function test_keyword_finds_case_by_case_code_and_product_sku(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4KW');
        $sku = Sku::create([
            'sku_code' => 'PROD-W4-SEARCH',
            'sku_name' => 'May cat gach tim kiem',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-W4-SEARCH-001',
            'item_status' => 'ISSUED',
        ]);

        $create = $this->postJson('/api/v1/warranty-cases', [
            'warranty_case_code' => 'WC-W4-SEARCH-01',
            'item_code' => 'SER-W4-SEARCH-001',
            'customer_name' => 'Khach Tim Kiem',
            'customer_phone' => '0900000022',
            'description' => 'Khong len nguon',
        ], $headers);
        $create->assertCreated();
        $caseId = (string) $create->json('data.id');

        // mã phiếu · mã SKU · một mảnh TÊN SKU (người dùng nhớ mặt hàng, không nhớ mã).
        foreach (['WC-W4-SEARCH-01', 'PROD-W4-SEARCH', 'cat gach'] as $keyword) {
            $found = $this->getJson('/api/v1/warranty-cases?keyword='.urlencode($keyword), $headers)->assertOk();
            $this->assertCount(1, $found->json('data'), "Không tìm được case theo {$keyword}");
            $this->assertSame($caseId, (string) $found->json('data.0.warranty_case_id'));
        }

        // Nhánh SKU phải nằm TRONG nhóm OR của keyword, không được vô hiệu filter khác.
        $filtered = $this->getJson(
            '/api/v1/warranty-cases?keyword=PROD-W4-SEARCH&identification_status=TEMP_ONLY',
            $headers
        )->assertOk();
        $this->assertCount(0, $filtered->json('data'));

        // `status` KHÔNG được nằm trong cột keyword của warranty_case: nếu không,
        // gõ tên hàng có chứa "re"/"can" sẽ kéo về toàn bộ hồ sơ RECEIVED/CANCELLED.
        $byStatus = $this->getJson('/api/v1/warranty-cases?keyword=RECEIVED', $headers)->assertOk();
        $this->assertCount(0, $byStatus->json('data'), 'Keyword không được khớp vào enum trạng thái.');
    }

    // ── ⑦⑧ sửa defect/PII + terminal immutability ─────────────────────────

    public function test_defect_and_pii_are_correctable_after_intake(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4D');
        $defect = DB::table('defect_catalogs')->insertGetId([
            'defect_id' => $defectId = (string) Str::uuid(),
            'defect_code' => 'DF-W4-MOTOR',
            'defect_name' => 'Khong chay motor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'defect_id');
        $this->assertNotNull($defect);

        $caseId = $this->createTempOnlyCase($headers);

        $updated = $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'defect_id' => $defectId,
            'customer_phone' => '0987654321',
            'customer_address' => '1 Nguyen Trai',
            'purchase_date' => now()->subYear()->toDateString(),
        ], $headers + ['If-Match' => '1']);
        $updated->assertOk()
            ->assertJsonPath('data.defect_code', 'DF-W4-MOTOR')
            ->assertJsonPath('data.customer_phone', '0987654321')
            ->assertJsonPath('data.stock_effect', 'NONE');

        // purchase_date tương lai vẫn bị chặn ở đường sửa.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'purchase_date' => now()->addDay()->toDateString(),
        ], $headers + ['If-Match' => '2'])->assertStatus(422);

        // §13.8: audit không được chứa giá trị PII thật. Cột định danh entity khác tên
        // giữa hai driver (object_id trên sqlite vs entity_id trên pgsql) nên chỉ lọc
        // theo action rồi soi toàn bộ payload.
        $auditRows = DB::table('audit_logs')
            ->where('action', 'WARRANTY_STATUS_CHANGE')
            ->get();
        $this->assertNotEmpty($auditRows, 'Cập nhật case phải ghi audit.');
        $auditDump = json_encode($auditRows->map(fn (object $row): array => (array) $row)->all());
        $this->assertStringNotContainsString('0987654321', (string) $auditDump);
        $this->assertStringNotContainsString('Nguyen Trai', (string) $auditDump);
        // Thay vào đó audit ghi metadata field nào đã đổi.
        $this->assertStringContainsString('customer_phone', (string) $auditDump);
    }

    public function test_pii_edit_requires_pii_view_permission(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W4E');
        $caseId = $this->createTempOnlyCase($admin);

        $manager = $this->authHeaders('WARRANTY_NO_PII', ['warranty.view', 'warranty.manage']);

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'customer_name' => 'Ten moi',
        ], $manager + ['If-Match' => '1'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        // Field không phải PII vẫn sửa được với warranty.manage.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'confirmed_defect' => 'Chet mach nguon',
        ], $manager + ['If-Match' => '1'])->assertOk();
    }

    public function test_terminal_case_rejects_any_further_update(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4F');
        $caseId = $this->createTempOnlyCase($headers);

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CANCELLED',
            'cancel_reason' => 'Khach xin nhan lai may',
        ], $headers + ['If-Match' => '1'])->assertOk();

        // Không kèm status: trước đây vẫn ghi được lên case đã đóng.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'confirmed_defect' => 'Sua sau khi da huy',
        ], $headers + ['If-Match' => '2'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    // ── ⑩ retention + purge ────────────────────────────────────────────────

    public function test_closing_case_stamps_retention_deadline_and_purge_is_idempotent(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4G');
        $caseId = $this->createTempOnlyCase($headers);

        $closed = $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CANCELLED',
            'cancel_reason' => 'Khach doi y',
        ], $headers + ['If-Match' => '1'])->assertOk();
        $this->assertNotNull($closed->json('data.retention_until'));

        // Chưa tới hạn -> purge không đụng gì.
        $this->artisan('warranty:purge-pii')->assertExitCode(0);
        // WWHN-SEC-06: `customer_name` không còn là cột — so trên BLIND INDEX,
        // giá trị tất định nên vẫn nhận ra được "đã anonymize hay chưa" mà không
        // phải giải mã.
        $this->assertDatabaseMissing('warranty_case', [
            'warranty_case_id' => $caseId,
            'customer_name_lookup' => BlindIndex::nameTokens('[ANONYMIZED]'),
        ]);

        DB::table('warranty_case')->where('warranty_case_id', $caseId)->update(WarrantyCasePii::encode([
            'retention_until' => now()->subDay(),
        ]));

        // dry-run báo cáo nhưng không ghi.
        $this->artisan('warranty:purge-pii --dry-run')->assertExitCode(0);
        // WWHN-SEC-06: `customer_name` không còn là cột — so trên BLIND INDEX,
        // giá trị tất định nên vẫn nhận ra được "đã anonymize hay chưa" mà không
        // phải giải mã.
        $this->assertDatabaseMissing('warranty_case', [
            'warranty_case_id' => $caseId,
            'customer_name_lookup' => BlindIndex::nameTokens('[ANONYMIZED]'),
        ]);

        $movementsBefore = DB::table('stock_movements')->count();
        $this->artisan('warranty:purge-pii')->assertExitCode(0);
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'customer_name_lookup' => BlindIndex::nameTokens('[ANONYMIZED]'),
        ]);
        // Và giá trị THẬT sau giải mã cũng phải là [ANONYMIZED]: chỉ kiểm vân tay
        // thôi thì một bug ghi đúng blind index nhưng sai ciphertext sẽ lọt.
        $purged = DB::table('warranty_case')->where('warranty_case_id', $caseId)->first();
        WarrantyCasePii::decodeRow($purged);
        $this->assertSame('[ANONYMIZED]', $purged->customer_name);
        $this->assertNull($purged->customer_address);
        // §13.8: không được xóa Inventory_Movement/business evidence.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('warranty_case', ['warranty_case_id' => $caseId, 'status' => 'CANCELLED']);
        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'WARRANTY_PII_PURGED')->exists(),
        );

        // Chạy lại: idempotent, không sinh audit lần hai.
        $auditCount = DB::table('audit_logs')->where('action', 'WARRANTY_PII_PURGED')->count();
        $this->artisan('warranty:purge-pii')->assertExitCode(0);
        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->where('action', 'WARRANTY_PII_PURGED')->count(),
        );
    }

    public function test_legal_hold_case_is_excluded_from_purge(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4H');
        $caseId = $this->createTempOnlyCase($headers);

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CANCELLED',
            'cancel_reason' => 'Tranh chap phap ly',
        ], $headers + ['If-Match' => '1'])->assertOk();

        DB::table('warranty_case')->where('warranty_case_id', $caseId)->update(WarrantyCasePii::encode([
            'retention_until' => now()->subYear(),
            'legal_hold_flag' => true,
        ]));

        $this->artisan('warranty:purge-pii')->assertExitCode(0);
        // WWHN-SEC-06: `customer_name` không còn là cột — so trên BLIND INDEX,
        // giá trị tất định nên vẫn nhận ra được "đã anonymize hay chưa" mà không
        // phải giải mã.
        $this->assertDatabaseMissing('warranty_case', [
            'warranty_case_id' => $caseId,
            'customer_name_lookup' => BlindIndex::nameTokens('[ANONYMIZED]'),
        ]);
    }

    public function test_retention_years_contract_matches_privacy_baseline(): void
    {
        // §13.8 "5 years after case close" — hằng số dùng chung cho controller + command.
        $this->assertSame(5, WarrantyContract::PII_RETENTION_YEARS);
        $this->assertSame(
            ['customer_name', 'customer_phone', 'customer_address'],
            WarrantyContract::PII_COLUMNS,
        );
    }

    // ── ⑪⑫ attachment content-type/malware ───────────────────────────────

    public function test_attachment_rejects_non_baseline_mime_and_extension_mismatch(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders('SUPER_ADMIN_W4I');
        $caseId = $this->createTempOnlyCase($headers);

        // Annex D chỉ cho jpg/png/webp — pdf/heic không còn được nhận.
        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => UploadedFile::fake()->create('phieu.pdf', 40, 'application/pdf'),
        ], $headers)->assertStatus(422);

        // Đổi tên .jpg nhưng nội dung thật không phải ảnh -> chặn bằng magic bytes.
        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;'),
        ], $headers)->assertStatus(422);

        $this->assertCount(
            0,
            $this->getJson("/api/v1/warranty-cases/{$caseId}/attachments", $headers)->json('data'),
        );

        // Ảnh hợp lệ vẫn qua và báo rõ kết quả scan.
        $ok = $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => UploadedFile::fake()->image('mat-truoc.jpg'),
            'attachment_type' => 'INTAKE',
        ], $headers);
        $ok->assertCreated()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertContains($ok->json('data.scan_result'), ['CLEAN', 'UNSCANNED']);
    }

    public function test_infected_attachment_is_quarantined_and_not_stored(): void
    {
        Storage::fake('local');
        // Scanner giả luôn báo nhiễm (exit code 1 theo quy ước clamscan).
        config(['warranty.attachment_scan_command' => PHP_BINARY.' -r "exit(1);" --']);

        $headers = $this->authHeaders('SUPER_ADMIN_W4J');
        $caseId = $this->createTempOnlyCase($headers);

        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => UploadedFile::fake()->image('nghi-nhiem.jpg'),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTACHMENT_MALWARE_DETECTED');

        $quarantined = DB::table('warranty_attachments')
            ->where('warranty_case_id', $caseId)
            ->where('status', 'QUARANTINED')
            ->first();
        $this->assertNotNull($quarantined, 'Tệp nhiễm phải để lại bản ghi QUARANTINED làm bằng chứng.');

        // Không có nội dung trên storage và không tải về được.
        $this->assertSame([], Storage::disk('local')->allFiles("warranty-attachments/{$caseId}"));
        $this->get("/api/v1/warranty-attachments/{$quarantined->id}/download", $headers)
            ->assertStatus(404);
        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'WARRANTY_ATTACHMENT_QUARANTINED')->exists(),
        );
    }

    // ── ⑤⑥ báo cáo RPT-WAR-01 ────────────────────────────────────────────

    public function test_warranty_report_exposes_returned_turnaround_purchase_date_and_model_filter(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4K');
        $sku = Sku::create([
            'sku_code' => 'PROD-W4-RPT',
            'sku_name' => 'May mai bao cao',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-W4-RPT-001',
            'item_status' => 'ISSUED',
        ]);

        $create = $this->postJson('/api/v1/warranty-cases', [
            'item_code' => $item->serial_number,
            'customer_name' => 'Khach Bao Cao',
            'customer_phone' => '0900000022',
            'purchase_date' => now()->subMonths(6)->toDateString(),
            'description' => 'Khong chay',
        ], $headers);
        $create->assertCreated();
        $caseId = (string) $create->json('data.id');

        // DEC-WAR-STATE-001: rời CHECKING bắt buộc có confirmed_defect, COMPLETED bắt buộc
        // ghi kết quả xử lý và RETURNED bắt buộc ghi biên bản bàn giao (đều vào `note`).
        // Fixture cũ chỉ gửi `status` — hợp lệ trước khi guard tồn tại, nay chặn 422 và
        // làm hỏng chính happy path mà test này cần dựng để đo turnaround.
        $version = 1;
        foreach ([
            ['status' => 'CHECKING'],
            ['status' => 'REPAIRING', 'confirmed_defect' => 'Chay cuon day motor'],
            ['status' => 'COMPLETED', 'note' => 'Da thay cuon day, chay thu dat'],
            ['status' => 'RETURNED', 'note' => 'Ban giao khach, ky nhan 09:15'],
        ] as $payload) {
            $this->patchJson("/api/v1/warranty-cases/{$caseId}", $payload,
                $headers + ['If-Match' => (string) $version])->assertOk();
            $version++;
        }

        $report = $this->getJson('/api/v1/reports/warranty-defects', $headers)->assertOk();
        $row = collect($report->json('data.rows'))->firstWhere('warranty_case_id', $caseId);
        $this->assertNotNull($row);
        $this->assertNotNull($row['purchase_date']);
        $this->assertNotNull($row['returned_at']);
        $this->assertSame(0, $row['turnaround_days']);
        $this->assertSame(1, $report->json('data.summary.returned'));

        // Filter model khớp sku_code.
        $matched = $this->getJson('/api/v1/reports/warranty-defects?model=PROD-W4-RPT', $headers)->assertOk();
        $this->assertCount(1, $matched->json('data.rows'));
        $missed = $this->getJson('/api/v1/reports/warranty-defects?model=KHONG-TON-TAI', $headers)->assertOk();
        $this->assertCount(0, $missed->json('data.rows'));

        // BR-EXP-03: tên file export có timestamp.
        $export = $this->get('/api/v1/reports/warranty-defects/export-excel', $headers);
        $export->assertOk();
        $this->assertMatchesRegularExpression(
            '/bao-cao-bao-hanh-\d{4}-\d{2}-\d{2}-den-\d{4}-\d{2}-\d{2}-\d{8}-\d{6}\.xlsx/',
            (string) $export->headers->get('content-disposition'),
        );
    }

    // ── ⑱ document_date không phải field bắt buộc ─────────────────────────

    public function test_component_issue_create_does_not_require_non_erd_document_date(): void
    {
        $headers = $this->authHeaders('SUPER_ADMIN_W4L');
        $warehouse = DB::table('warehouses')->insertGetId([
            'id' => $warehouseId = (string) Str::uuid(),
            'code' => 'KHO-W4',
            'name' => 'Kho luong 04',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');
        $this->assertNotNull($warehouse);

        $component = Sku::create([
            'sku_code' => 'LK-W4-PIN',
            'sku_name' => 'Pin 20V',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        // Xuất linh kiện chỉ mở cho hồ sơ Đang kiểm tra/Đang sửa chữa.
        $caseId = $this->moveCaseToChecking($headers, $this->createTempOnlyCase($headers));

        $created = $this->postJson('/api/v1/warranty-component-issues', [
            'warehouse_id' => $warehouseId,
            'warranty_case_id' => $caseId,
            'lines' => [['sku_id' => $component->sku_id, 'required_qty' => 1]],
        ], $headers);
        $created->assertCreated()
            ->assertJsonPath('data.document.purpose', 'WARRANTY')
            ->assertJsonPath('data.document.status', 'DRAFT');
        $this->assertSame(now()->toDateString(), substr((string) $created->json('data.document.document_date'), 0, 10));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function createTempOnlyCase(array $headers): string
    {
        $create = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khach W4 '.Str::random(4),
            'customer_phone' => '09'.random_int(10000000, 99999999),
            'description' => 'May khong len nguon',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May khoan mau xanh',
        ], $headers);
        $create->assertCreated();

        return (string) $create->json('data.id');
    }

    /**
     * @param  list<string>|null  $permissions  null = SUPER_ADMIN (bypass permission check)
     */
    private function authHeaders(string $roleCode, ?array $permissions = null): array
    {
        $role = Role::create([
            'role_code' => $permissions === null ? 'SUPER_ADMIN' : $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions ?? [] as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
            );
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::create([
            'username' => Str::lower($roleCode).'-'.Str::lower(Str::random(6)),
            'display_name' => $roleCode,
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }
}
