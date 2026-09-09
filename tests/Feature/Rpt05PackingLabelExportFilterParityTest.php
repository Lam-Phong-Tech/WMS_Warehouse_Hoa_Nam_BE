<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * RPT-05 "Nhãn đóng gói · In / In lại" — ĐỐI SOÁT BỘ LỌC MÀN HÌNH ↔ FILE XUẤT
 * (quyết định hợp đồng 09/09/2026).
 *
 * Bối cảnh: màn hình vẽ MỘT DÒNG MỘT LẦN IN, còn `/reports/label-log` (thứ mà
 * `/reports/export` report_code=label-log xuất ra) là bảng TỔNG HỢP THEO NHÃN theo
 * §13.10 RPT-LABEL-01. Chênh lệch SỐ DÒNG đó là hợp đồng — giữ nguyên, và màn hình
 * nói rõ ngay cạnh nút Xuất. Cái KHÔNG được phép lệch là BỘ LỌC: BR-EXP-01 đòi file
 * dùng đúng bộ lọc màn hình, mà trước bản vá này ba thứ bị bỏ rơi:
 *
 *  1. `action` (In / In lại) KHÔNG hề tồn tại ở BE ⇒ lọc "Chỉ In lại" rồi bấm Xuất
 *     vẫn ra file đủ cả PRINT lẫn REPRINT.
 *  2. `status` lọc `label_snapshots.status` — vòng đời của NHÃN — trong khi màn hình
 *     lọc kết quả của LẦN IN. Nhãn in lỗi rồi in lại thành công có ls.status =
 *     PRINTED nên rơi khỏi file khi lọc "In lỗi", dù lần in lỗi vẫn hiện trên màn.
 *  3. `keyword` chỉ soi doc_no / recipient / agency, còn ô tìm kiếm của màn hình soi
 *     cả mã nhãn, máy in, người in và mã lỗi ⇒ gõ tên máy in rồi Xuất ra file KHÔNG
 *     lọc gì cả (im lặng trả toàn kỳ).
 *
 * Bộ test này ghim cả ba, và ghim luôn hai điều KHÔNG được đổi: bộ 20 cột của
 * RPT-LABEL-01, và việc file vẫn là bảng gộp theo nhãn.
 */
class Rpt05PackingLabelExportFilterParityTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Warehouse $warehouse;

    /** Nhãn in một lần duy nhất, thành công — thuần "In lần đầu". */
    private string $printedOnce;

    /** Nhãn in thành công hai lần — có In lại theo đúng định nghĩa reprint_count. */
    private string $reprinted;

    /** Nhãn in lỗi rồi in lại thành công: ls.status = PRINTED nhưng CÓ lần in lỗi. */
    private string $failedThenPrinted;

    private string $printerA;

    private string $printerB;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user, $this->token] = $this->createSuperAdmin();
        $this->warehouse = $this->ensureActiveWarehouse('KHO-RPT05', 'Kho test RPT-05');

        $this->printerA = $this->createPrinterConfig('PRN-RPT05-A', 'Zebra ZD421');
        $this->printerB = $this->createPrinterConfig('PRN-RPT05-B', 'TSC TE200');

        $this->printedOnce = $this->createLabel('PX-RPT05-01', 'PKL-RPT05-01', 'PRINTED');
        $this->createPrintEvent($this->printedOnce, 'PRINTED', $this->printerA);

        $this->reprinted = $this->createLabel('PX-RPT05-02', 'PKL-RPT05-02', 'PRINTED');
        $this->createPrintEvent($this->reprinted, 'PRINTED', $this->printerA);
        $this->createPrintEvent($this->reprinted, 'PRINTED', $this->printerA);

        $this->failedThenPrinted = $this->createLabel('PX-RPT05-03', 'PKL-RPT05-03', 'PRINTED');
        // Trigger PostgreSQL chuẩn hoá status sự kiện in về bộ ERD, nên ca thật trên
        // DB tham chiếu là 'FAILED' chứ không phải 'PRINT_FAILED'.
        $this->createPrintEvent($this->failedThenPrinted, 'FAILED', $this->printerB, 'PRINTER_OUT_OF_PAPER');
        $this->createPrintEvent($this->failedThenPrinted, 'PRINTED', $this->printerB);
    }

    // ── BR-EXP-01: bộ lọc In / In lại phải tới được file ─────

    /** Lỗi chính của ticket: "Chỉ In lại" trên màn, file vẫn đủ cả PRINT lẫn REPRINT. */
    public function test_action_reprint_narrows_the_report_to_labels_that_were_reprinted(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-02'], $this->rows('&action=REPRINT'));
    }

    /**
     * "Chỉ In" ở mức nhãn = nhãn đã có ít nhất một lần in (lần in đầu của một nhãn
     * luôn là Print). Nhãn chưa in lần nào phải rơi ra.
     */
    public function test_action_print_keeps_every_label_that_has_been_printed_at_least_once(): void
    {
        $this->createLabel('PX-RPT05-04', 'PKL-RPT05-04', 'PENDING_PRINT');

        $this->assertLabelCodes(
            ['PKL-RPT05-01', 'PKL-RPT05-02', 'PKL-RPT05-03'],
            $this->rows('&action=PRINT')
        );
        $this->assertLabelCodes(
            ['PKL-RPT05-01', 'PKL-RPT05-02', 'PKL-RPT05-03', 'PKL-RPT05-04'],
            $this->rows('')
        );
    }

    /** Giá trị lạ phải là 422 chứ không phải im lặng bỏ qua bộ lọc. */
    public function test_unknown_action_is_rejected_instead_of_silently_ignored(): void
    {
        $today = now()->toDateString();
        $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}&action=DELETE")
            ->assertStatus(422);
    }

    // ── status: kết quả LẦN IN, không phải vòng đời của NHÃN ─

    /**
     * Ca đắt nhất: nhãn in lỗi rồi in lại thành công. Màn hình vẫn hiện dòng "In lỗi"
     * của nó, nên file lọc "In lỗi" cũng phải giữ nhãn này lại — dù ls.status đã là
     * PRINTED. Trước bản vá, file trả 0 dòng.
     */
    public function test_status_filter_follows_print_attempts_not_the_label_lifecycle(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-03'], $this->rows('&status=PRINT_FAILED'));
    }

    /** Cùng lý do, chiều ngược lại: mọi nhãn có ít nhất một lần in thành công. */
    public function test_status_printed_matches_every_label_with_a_successful_attempt(): void
    {
        $this->assertLabelCodes(
            ['PKL-RPT05-01', 'PKL-RPT05-02', 'PKL-RPT05-03'],
            $this->rows('&status=PRINTED')
        );
    }

    // ── keyword: cùng phạm vi với ô tìm kiếm của màn hình ────

    public function test_keyword_matches_the_label_code_shown_on_screen(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-02'], $this->rows('&keyword=PKL-RPT05-02'));
    }

    /** Gõ tên máy in rồi Xuất: trước bản vá file trả TOÀN KỲ, không lọc gì. */
    public function test_keyword_matches_printer_name_the_way_the_screen_does(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-03'], $this->rows('&keyword='.urlencode('TSC TE200')));
    }

    public function test_keyword_matches_the_printer_error_code(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-03'], $this->rows('&keyword=OUT_OF_PAPER'));
    }

    public function test_keyword_still_matches_the_outbound_document_number(): void
    {
        $this->assertLabelCodes(['PKL-RPT05-01'], $this->rows('&keyword=PX-RPT05-01'));
    }

    // ── Hai thứ KHÔNG được đổi ───────────────────────────────

    /**
     * Granularity là hợp đồng §13.10, không phải lỗi: một nhãn in hai lần vẫn là MỘT
     * dòng trong file. Nếu ai đó đổi truy vấn sang per-event, test này đỏ trước.
     */
    public function test_export_stays_one_row_per_label_even_when_a_label_was_printed_twice(): void
    {
        $rows = $this->rows('');
        $this->assertCount(3, $rows, 'Ba nhãn, năm lần in — file vẫn phải là ba dòng.');

        $reprinted = collect($rows)->firstWhere('label_code', 'PKL-RPT05-02');
        $this->assertSame(2, $reprinted['total_attempts']);
        $this->assertSame(1, $reprinted['reprint_count']);
    }

    /** Bộ 20 cột của RPT-LABEL-01 đã ký ở WWHN-310 — đổi phải qua BA. */
    public function test_export_keeps_the_signed_twenty_column_contract(): void
    {
        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/label-log/export-excel?from_date={$today}&to_date={$today}");
        $response->assertOk();
        $this->assertStringStartsWith("PK\x03\x04", $response->streamedContent());
    }

    /** Bộ lọc phải ĐI KÈM vào cache key, nếu không hai lần lọc khác nhau trả cùng file. */
    public function test_action_filter_participates_in_the_report_cache_key(): void
    {
        $all = $this->rows('');
        $reprintOnly = $this->rows('&action=REPRINT');

        $this->assertCount(3, $all);
        $this->assertCount(1, $reprintOnly, 'Lọc sau không được nhận lại payload đã cache của lượt không lọc.');
    }

    // ── helpers ──────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function rows(string $extraQuery): array
    {
        $today = now()->toDateString();
        $response = $this->api("/api/v1/reports/label-log?from_date={$today}&to_date={$today}{$extraQuery}");
        $response->assertOk();

        return $response->json('data.rows');
    }

    /**
     * @param  list<string>  $expected
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertLabelCodes(array $expected, array $rows): void
    {
        $actual = collect($rows)->pluck('label_code')->sort()->values()->all();
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function api(string $url)
    {
        return $this->getJson($url, ['Authorization' => "Bearer {$this->token}"]);
    }

    /** @return array{0: User, 1: string} */
    private function createSuperAdmin(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'rpt05-admin', 'display_name' => 'RPT-05 Admin',
            'email' => 'rpt05@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
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

    private function createLabel(string $docNo, string $labelCode, string $status): string
    {
        $documentId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => $docNo,
            'doc_date' => now()->toDateString(),
            'status' => 'POSTED',
            'src_warehouse_id' => $this->warehouse->id,
            'recipient_name' => 'Đại lý RPT-05',
            'posted_at' => now(),
            'posted_by' => $this->user->user_id,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $id,
            'outbound_document_id' => $documentId,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['label_code' => $labelCode, 'item_count' => 1, 'sku_count' => 1]),
            'status' => $status,
            'applied_at' => $status === 'PRINTED' ? now() : null,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createPrintEvent(string $snapshotId, string $status, string $printerId, ?string $errorCode = null): void
    {
        DB::table('label_print_events')->insert([
            'id' => (string) Str::uuid(),
            'label_snapshot_id' => $snapshotId,
            'printer_config_id' => $printerId,
            'status' => $status,
            'error_code' => $errorCode,
            'printed_at' => $status === 'PRINTED' ? now() : null,
            'created_by' => $this->user->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
