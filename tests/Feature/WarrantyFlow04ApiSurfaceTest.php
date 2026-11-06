<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyReportTime;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\TestCase;

/**
 * Bề mặt API bổ sung cho luồng 04 · Bảo hành & linh kiện:
 * field ERD §45 (reported/confirmed defect, accessories, cancel_reason...),
 * attachments §47 và GET detail/filter cho Component Issue §48-50.
 */
class WarrantyFlow04ApiSurfaceTest extends TestCase
{
    use OpensWarrantyCaseForComponentIssue;
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
            'username' => 'flow04-'.Str::lower(Str::random(6)),
            'display_name' => 'Flow04 Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** Mỗi mutation warranty cần một Idempotency-Key riêng theo contract v1.7. */
    private function idem(): array
    {
        return ['Idempotency-Key' => 'flow04-'.(string) Str::uuid()];
    }

    private function createTempOnlyCase(array $headers): string
    {
        $create = $this->postJson('/api/v1/warranty-cases', [
            // Persist explicit owner scope through the canonical create contract.
            'warehouse_id' => (string) $this->ensureActiveWarehouse('KHO-F04', 'Kho Flow04')->id,
            'customer_name' => 'Khach Flow04',
            'customer_phone' => '0933333333',
            'description' => 'May khong len nguon',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May khoan mau xanh',
        ], $headers + $this->idem());
        $create->assertCreated();

        return (string) $create->json('data.id');
    }

    public function test_warranty_case_exposes_erd_intake_diagnosis_and_cancel_fields(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'PROD-F04-001',
            'sku_name' => 'San pham flow04',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-F04-001',
            'item_status' => 'ISSUED',
        ]);

        $create = $this->postJson('/api/v1/warranty-cases', [
            // Persist explicit owner scope through the canonical create contract.
            'warehouse_id' => (string) $this->ensureActiveWarehouse('KHO-F04', 'Kho Flow04')->id,
            'item_code' => 'SER-F04-001',
            'customer_name' => 'Nguyen Flow04',
            'customer_phone' => '0905555555',
            'customer_address' => '12 Hoang Quoc Viet, Ha Noi',
            'description' => 'Khong chay motor',
            'reported_defect' => 'Motor khong quay khi cap nguon',
            'accessories_received' => 'Pin 20V, sac, hop',
            'received_condition' => 'Tray xuoc nhe than may',
        ], $headers + $this->idem());
        $create->assertCreated()
            ->assertJsonPath('data.reported_defect', 'Motor khong quay khi cap nguon')
            ->assertJsonPath('data.accessories_received', 'Pin 20V, sac, hop')
            ->assertJsonPath('data.received_condition', 'Tray xuoc nhe than may')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $this->assertNotNull($create->json('data.received_at'));
        $caseId = (string) $create->json('data.id');

        // Ghi chú chẩn đoán không đổi status -> version tăng + event NOTE.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'confirmed_defect' => 'Chet mach cap nguon motor',
        ], $headers + $this->idem() + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.confirmed_defect', 'Chet mach cap nguon motor')
            ->assertJsonPath('data.version', 2);
        $this->assertTrue(
            DB::table('warranty_event')->where('warranty_case_id', $caseId)->where('event_type', 'NOTE')->exists(),
        );

        // Hủy case bắt buộc lý do theo baseline.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CANCELLED',
        ], $headers + $this->idem() + ['If-Match' => '2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANCEL_REASON_REQUIRED');

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], $headers + $this->idem() + ['If-Match' => '2'])->assertOk();

        $cancel = $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CANCELLED',
            'cancel_reason' => 'Khach tu choi sua, xin nhan lai may',
        ], $headers + $this->idem() + ['If-Match' => '3']);
        $cancel->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancel_reason', 'Khach tu choi sua, xin nhan lai may');
        $this->assertNotNull($cancel->json('data.closed_at'));
    }

    public function test_warranty_attachment_upload_list_download_and_logical_delete(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders();
        $caseId = $this->createTempOnlyCase($headers);

        $upload = $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            // Phải là ảnh thật: §13.8 content-type check đọc magic bytes, nên fake file
            // rỗng (application/x-empty) bị chặn giống file bị đổi tên phần mở rộng.
            'file' => UploadedFile::fake()->image('mat-truoc.jpg'),
            'attachment_type' => 'INTAKE',
        ], $headers);
        $upload->assertCreated()
            ->assertJsonPath('data.attachment_type', 'INTAKE')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $attachmentId = (string) $upload->json('data.id');
        $this->assertNotEmpty($upload->json('data.checksum'));
        $this->assertArrayNotHasKey('storage_path', (array) $upload->json('data'));

        $list = $this->getJson("/api/v1/warranty-cases/{$caseId}/attachments", $headers);
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        $this->get("/api/v1/warranty-attachments/{$attachmentId}/download", $headers)
            ->assertOk();

        $this->deleteJson("/api/v1/warranty-attachments/{$attachmentId}", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'DELETED');

        $after = $this->getJson("/api/v1/warranty-cases/{$caseId}/attachments", $headers);
        $this->assertCount(0, $after->json('data'));
        $this->get("/api/v1/warranty-attachments/{$attachmentId}/download", $headers)
            ->assertStatus(404);
    }

    /**
     * Disk media để `throw => false`: ghi lỗi (R2 sai credential, mất mạng) chỉ trả false.
     * Upload phải báo 503 và KHÔNG để lại dòng ACTIVE trỏ tới tệp không tồn tại.
     */
    public function test_warranty_attachment_upload_returns_503_when_storage_write_fails(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders();
        $caseId = $this->createTempOnlyCase($headers);

        $broken = Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('putFileAs')->once()->andReturnFalse();
        Storage::set('broken-media', $broken);
        config(['warranty.photo_disk' => 'broken-media']);

        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => UploadedFile::fake()->image('mat-truoc.jpg'),
            'attachment_type' => 'INTAKE',
        ], $headers)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'STORAGE_UNAVAILABLE');

        $this->assertSame(0, DB::table('warranty_attachments')->where('warranty_case_id', $caseId)->count());
    }

    /**
     * WAR-02: video mp4 hợp lệ nhỏ nhất mà getID3 đọc được duration — chỉ ftyp +
     * moov/mvhd (đủ để quicktime module tính playtime_seconds = duration/time_scale)
     * + mdat đệm để tránh lỗi "cannot fseek" khi file quá nhỏ.
     */
    private function fakeMp4UploadedFile(string $name, int $durationSeconds): UploadedFile
    {
        $box = fn (string $type, string $payload): string => pack('N', 8 + strlen($payload)).$type.$payload;

        $mvhdPayload = str_repeat("\x00", 4) // version+flags
            .str_repeat("\x00", 4) // creation_time
            .str_repeat("\x00", 4) // modification_time
            .pack('N', 1000) // time_scale
            .pack('N', $durationSeconds * 1000) // duration
            .pack('N', 0x00010000) // rate
            .pack('n', 0x0100) // volume
            .str_repeat("\x00", 10) // reserved
            .str_repeat("\x00", 36) // matrix
            .str_repeat("\x00", 24) // preview/poster/selection/current time
            .pack('N', 2); // next_track_id

        $bytes = $box('ftyp', 'isom'.pack('N', 0).'isomiso2mp41')
            .$box('moov', $box('mvhd', $mvhdPayload))
            .$box('mdat', str_repeat("\x00", 2048));

        $path = tempnam(sys_get_temp_dir(), 'war02-mp4-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    public function test_warranty_video_attachment_upload_duration_and_cap(): void
    {
        Storage::fake('local');
        $headers = $this->authHeaders();
        $caseId = $this->createTempOnlyCase($headers);

        // Video >5 phút bị chặn, không lưu ra storage. Kiểm tra trước khi có video
        // active nào để guard thời lượng chạy được (guard đếm cap chạy trước guard
        // thời lượng trong validateVideo(), nếu case đã đủ cap thì mọi upload sau đều
        // dừng lại ở ATTACHMENT_LIMIT_EXCEEDED bất kể thời lượng).
        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => $this->fakeMp4UploadedFile('qua-dai.mp4', 400),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTACHMENT_VIDEO_TOO_LONG');

        $upload = $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => $this->fakeMp4UploadedFile('tiep-nhan.mp4', 30),
        ], $headers);
        $upload->assertCreated()
            ->assertJsonPath('data.media_kind', 'VIDEO')
            ->assertJsonPath('data.duration_seconds', 30)
            ->assertJsonPath('data.stock_effect', 'NONE');

        // Video thứ 2 vượt cap 1 video/case.
        $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
            'file' => $this->fakeMp4UploadedFile('video-2.mp4', 20),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTACHMENT_LIMIT_EXCEEDED');

        // Video không tính vào cap 10 ảnh: vẫn tải được đủ 10 ảnh sau khi đã có 1 video.
        for ($i = 0; $i < 10; $i++) {
            $this->post("/api/v1/warranty-cases/{$caseId}/attachments", [
                'file' => UploadedFile::fake()->image("anh-{$i}.jpg"),
            ], $headers)->assertCreated();
        }

        $list = $this->getJson("/api/v1/warranty-cases/{$caseId}/attachments?per_page=100", $headers);
        $list->assertOk();
        $this->assertCount(11, $list->json('data'));
    }

    public function test_component_issue_detail_route_and_warranty_case_filter(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHO-F04', 'name' => 'Kho Flow04', 'status' => 'ACTIVE']);
        $componentSku = Sku::create([
            'sku_code' => 'LK-F04-PIN-20V',
            'sku_name' => 'Pin 20V',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        // Xuất linh kiện chỉ mở cho hồ sơ Đang kiểm tra/Đang sửa chữa.
        $caseId = $this->moveCaseToChecking($headers, $this->createTempOnlyCase($headers));

        $create = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $caseId,
            'document_date' => now()->toDateString(),
            'purpose' => 'WARRANTY',
            'lines' => [
                ['sku_id' => $componentSku->sku_id, 'required_qty' => 2],
            ],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.document.id');
        $this->assertNotSame('', $documentId);

        $detail = $this->getJson("/api/v1/component-issue-documents/{$documentId}", $headers);
        $detail->assertOk()
            ->assertJsonPath('data.document.warranty_case_id', $caseId)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.lines.0.sku_code', 'LK-F04-PIN-20V');

        $filtered = $this->getJson('/api/v1/component-issue-documents?warranty_case_id='.$caseId, $headers);
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));

        $none = $this->getJson('/api/v1/component-issue-documents?warranty_case_id='.(string) Str::uuid(), $headers);
        $none->assertOk();
        $this->assertCount(0, $none->json('data'));

        $this->getJson('/api/v1/component-issue-documents/'.(string) Str::uuid(), $headers)
            ->assertStatus(404);
    }

    public function test_warranty_report_masks_pii_and_traces_component_issues(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07T15:00:00+07:00'));
        $headers = $this->authHeaders();
        $caseId = $this->createTempOnlyCase($headers);

        $report = $this->getJson('/api/v1/reports/warranty-defects', $headers);
        // GĐ1 2026-08-25: route trỏ BaM1ReportController::warrantyDefects (multi-defect).
        // Cờ mask nằm ở từng row (rows.*.pii_masked), không còn data.pii_included;
        // case TEMP_ONLY hiển thị model = manual_product_description thay vì UNKNOWN_MODEL.
        $report->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.temp_only', 1)
            ->assertJsonPath('data.rows.0.model', 'May khoan mau xanh')
            ->assertJsonPath('data.rows.0.components_issued', 'Chưa xuất');
        // §13.8 + §13.10 RPT-WAR-01: "customer/address masked by default" — cột vẫn
        // có trên báo cáo nhưng nội dung luôn ở dạng mask, kể cả với SUPER_ADMIN.
        $row = $report->json('data.rows.0');
        $this->assertTrue($report->json('data.rows.0.pii_masked'));
        $this->assertArrayHasKey('customer_name', $row);
        $this->assertArrayHasKey('customer_phone', $row);
        $this->assertStringContainsString('***', (string) $row['customer_name']);
        $this->assertStringStartsWith('*', (string) $row['customer_phone']);

        $filtered = $this->getJson(
            '/api/v1/reports/warranty-defects?status=RETURNED',
            $headers,
        );
        $filtered->assertOk();
        $this->assertCount(0, $filtered->json('data.rows'));

        // SUPER_ADMIN bypass quyền => export chứa PII và được audit.
        $auditBefore = DB::table('audit_logs')->where('action', 'WARRANTY_REPORT_EXPORTED')->count();
        $this->get('/api/v1/reports/warranty-defects/export-excel', $headers)
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        $this->assertSame(
            $auditBefore + 1,
            DB::table('audit_logs')->where('action', 'WARRANTY_REPORT_EXPORTED')->count(),
        );
    }

    #[DataProvider('reportTimezones')]
    public function test_warranty_report_and_excel_share_vietnam_day_bounds_for_mixed_source_times(string $timezone): void
    {
        $this->travelTo(Carbon::parse('2026-11-07T15:00:00+07:00'));
        config()->set('app.timezone', $timezone);
        $oldZone = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $headers = $this->authHeaders();
            $wanted = [];
            foreach ([
                ['2026-11-06T17:00:00Z', true],
                ['2026-11-07T00:00:00+07:00', true],
                ['2026-11-07 23:59:59', true],
                ['2026-11-07T16:59:59Z', true],
                ['2026-11-08T00:00:00+07:00', false],
                ['2026-11-06T16:59:59Z', false],
            ] as [$time, $included]) {
                $id = $this->createTempOnlyCase($headers);
                // PG's aware source column needs an explicit offset for a legacy local fixture.
                $stored = DB::getDriverName() === 'pgsql' && $time === '2026-11-07 23:59:59' ? $time.'+07:00' : $time;
                DB::table('warranty_case')->where('warranty_case_id', $id)->update(['received_at' => $stored]);
                if ($included) {
                    $wanted[] = (string) DB::table('warranty_case')->where('warranty_case_id', $id)->value('warranty_case_code');
                }
            }
            $before = DB::table('warranty_case')->orderBy('warranty_case_id')->get()->toArray();
            $query = '?from_date=2026-11-07&to_date=2026-11-07&per_page=100';
            $report = $this->getJson('/api/v1/reports/warranty-defects'.$query, $headers)->assertOk();
            $this->assertEqualsCanonicalizing($wanted, array_column($report->json('data.rows'), 'case_no'));
            $this->assertSame(count($wanted), $report->json('data.summary.total'));
            $paged = $this->getJson('/api/v1/reports/warranty-defects?from_date=2026-11-07&to_date=2026-11-07&per_page=2', $headers)->assertOk();
            $this->assertCount(2, $paged->json('data.rows'));
            $this->assertSame(count($wanted), $paged->json('data.summary.total'));
            $export = $this->get('/api/v1/reports/warranty-defects/export-excel'.$query, $headers)->assertOk();
            $file = storage_path('framework/testing/warranty-range-'.Str::uuid().'.xlsx');
            file_put_contents($file, $export->streamedContent());
            try {
                $rows = IOFactory::load($file)->getActiveSheet()->toArray(null, false, false, false);
                $this->assertEqualsCanonicalizing($wanted, array_column(array_slice($rows, 1), 0));
            } finally {
                @unlink($file);
            }
            $this->assertEquals($before, DB::table('warranty_case')->orderBy('warranty_case_id')->get()->toArray());
        } finally {
            date_default_timezone_set($oldZone);
        }
    }

    public static function reportTimezones(): array
    {
        return [['Asia/Ho_Chi_Minh'], ['UTC']];
    }

    public function test_warranty_report_range_preserves_last_microsecond_and_created_fallback(): void
    {
        $values = [
            ['2026-11-07T16:59:59.999999Z', '2026-11-08T00:00:00+07:00', true],
            ['2026-11-07T17:00:00.000000Z', '2026-11-07T10:00:00+07:00', false],
            [null, '2026-11-06T17:00:00.000001Z', true],
            [null, null, false],
        ];
        $cast = DB::getDriverName() === 'pgsql' ? 'CAST(? AS timestamptz)' : '?';
        foreach ($values as [$received, $created, $expected]) {
            $query = DB::query()->fromRaw("(SELECT {$cast} AS received_at, {$cast} AS created_at) AS wc", [$received, $created]);
            $this->assertSame($expected, $query->where(WarrantyReportTime::range('2026-11-07', '2026-11-07'))->exists());
        }
    }

    public function test_warranty_events_follow_erd_taxonomy_and_responses_carry_etag(): void
    {
        $headers = $this->authHeaders();
        $caseId = $this->createTempOnlyCase($headers);

        $show = $this->getJson("/api/v1/warranty-cases/{$caseId}", $headers);
        $show->assertOk()->assertHeader('ETag', '"1"');

        $update = $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], $headers + $this->idem() + ['If-Match' => '1']);
        $update->assertOk()->assertHeader('ETag', '"2"');

        $types = DB::table('warranty_event')
            ->where('warranty_case_id', $caseId)
            ->orderBy('event_at')
            ->pluck('event_type')
            ->all();
        $this->assertSame(['RECEIVE', 'CHECK'], $types);
    }
}
