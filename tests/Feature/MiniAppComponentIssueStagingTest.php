<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Màn "Xuất linh kiện" của Mini App (nghiệp vụ 04) end-to-end qua HTTP.
 *
 * Phát biểu nghiệp vụ được khoá ở đây:
 *  - chỉ hồ sơ ĐANG KIỂM TRA / ĐANG SỬA CHỮA mới xuất được linh kiện;
 *  - linh kiện DÁN MÃ: quét mã linh kiện, mỗi lần quét là một hiện vật;
 *  - linh kiện KHÔNG dán mã, đựng trong hộp: quét mã hộp + nhập số lượng;
 *  - "Xác nhận xuất linh kiện" là hành động DUY NHẤT trừ tồn.
 *
 * Kèm các đường sửa sai không phải huỷ phiếu: gỡ dòng quét (unscan) và thêm/sửa/
 * xoá dòng linh kiện.
 */
class MiniAppComponentIssueStagingTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures {
        authHeadersWithRole as private legacyAuthHeadersWithRole;
    }
    use RefreshDatabase;

    private const RESOLVE = '/api/v1/mini-app/warranty-component-issues/resolve-code';

    private const RECORD = '/api/v1/mini-app/warranty-component-issues/record';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * Tồn linh kiện ĐẾM THEO HỘP dựng bằng đúng ledger thật: inbound → box →
     * scan số đếm → post-receipt, rồi nạp subledger nội dung hộp.
     *
     * @return array{headers: array, warehouse_id: string, sku_id: string, sku_code: string, container_id: string, box_code: string, case_id: string}
     */
    private function boxFixture(int $qty = 10): array
    {
        $auth = $this->superAdminHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouseFixture();

        $sku = $this->componentSkuFixture('HN-COMP-MA-'.Str::upper(Str::random(5)));
        $boxCode = 'BOX-'.$sku->sku_code.'-0001';

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, ['source_name' => 'NCC xuất linh kiện']);
        $received = $this->receiveComponentQuantity(
            $headers,
            $document['document_id'],
            (string) $sku->sku_id,
            $qty,
            $document['version'],
            $boxCode
        );
        $this->postInboundReceipt($headers, $document['document_id'], $received['version'], 'ci-rcv-'.(string) Str::uuid())
            ->assertOk();

        // KHÔNG nạp `container_items` thủ công: luồng nhập kho scan-driven đã ghi
        // subledger của hộp. Thêm một lần nữa là hộp có gấp đôi số thật, và bug
        // kiểu đó ẩn kỹ vì mọi khẳng định theo balance vẫn đúng.
        $this->assertSame($qty, (int) DB::table('container_items')
            ->where('container_id', $received['container_id'])
            ->where('is_active', true)
            ->sum('quantity'));

        return [
            'headers' => $headers,
            'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id,
            'sku_code' => (string) $sku->sku_code,
            'container_id' => $received['container_id'],
            'box_code' => $boxCode,
            'case_id' => $this->warrantyCase($headers, checking: true),
        ];
    }

    /**
     * Linh kiện DÁN MÃ: SKU serial-tracked + một hiện vật IN_STOCK có mã quét,
     * và opening balance có gốc ledger để đối soát vẫn MATCH sau khi Post.
     *
     * @return array{headers: array, warehouse_id: string, sku_id: string, item_id: string, code_value: string, case_id: string}
     */
    private function serialFixture(): array
    {
        $auth = $this->superAdminHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $warehouse = $this->warehouseFixture();

        $sku = $this->componentSkuFixture('HN-COMP-SER-'.Str::upper(Str::random(5)), serialTracked: true);
        $itemId = $this->seedItemInStock((string) $sku->sku_id);
        $codeValue = 'LK-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemId, $codeValue);
        $this->seedLedgerBackedBalance((string) $warehouse->id, (string) $sku->sku_id, 1, (string) $auth['__user_id']);

        return [
            'headers' => $headers,
            'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id,
            'item_id' => $itemId,
            'code_value' => $codeValue,
            'case_id' => $this->warrantyCase($headers, checking: true),
        ];
    }

    // Use real isolated login families for each role, not a token carrying
    // custom sid claims left by another actor in this PHPUnit process.
    private function authHeadersWithRole(string $roleCode): array
    {
        $auth = $this->legacyAuthHeadersWithRole($roleCode);
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'component-'.Str::uuid().'@example.test',
            'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $reset = function (): void {
            $this->app['auth']->forgetGuards();
            $this->app['tymon.jwt']->unsetToken();
            $this->app['tymon.jwt.auth']->unsetToken();
        };
        $reset();
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $warehouse = $this->warehouseFixture();
        $reset();
        $selected = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id,
        ], ['Authorization' => 'Bearer '.$login->json('data.access_token')])->assertOk();
        $reset();

        return ['Authorization' => 'Bearer '.$selected->json('data.access_token'), '__user_id' => $user->user_id];
    }

    private function warrantyCase(array $headers, bool $checking): string
    {
        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khách xuất linh kiện',
            'customer_phone' => '0966666666',
            'description' => 'Máy không lên nguồn',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ci-case-'.(string) Str::uuid()])->assertCreated();

        $caseId = (string) $case->json('data.id');

        return $checking ? $this->moveCaseToChecking($headers, $caseId) : $caseId;
    }

    private function balanceQty(string $warehouseId, string $skuId): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('stock_status', 'AVAILABLE')
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->sum('available_qty');
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'ci-record-'.(string) Str::uuid()];
    }

    // ------------------------------------------------------------------
    // resolve-code — tiền kiểm thuần đọc
    // ------------------------------------------------------------------

    public function test_resolve_code_ma_hop_bao_can_nhap_so_luong_va_tra_ton_cua_hop(): void
    {
        $fixture = $this->boxFixture(7);

        $response = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $fixture['box_code'],
            'warranty_case_id' => $fixture['case_id'],
        ], $fixture['headers']);

        $response->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.item_type', 'BOX')
            ->assertJsonPath('data.requires_quantity', true)
            ->assertJsonPath('data.available_qty', 7)
            ->assertJsonPath('data.sku_id', $fixture['sku_id'])
            ->assertJsonPath('data.eligible_for_component_issue', true)
            ->assertJsonPath('data.case_allows_issue', true)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.wms_document_created', false);

        // Thuần đọc: không sinh phiếu nào.
        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    public function test_resolve_code_ma_dan_tren_linh_kien_khong_hoi_so_luong(): void
    {
        $fixture = $this->serialFixture();

        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $fixture['code_value'],
            'warranty_case_id' => $fixture['case_id'],
        ], $fixture['headers'])
            ->assertOk()
            ->assertJsonPath('data.item_type', 'ITEM')
            ->assertJsonPath('data.requires_quantity', false)
            ->assertJsonPath('data.item_id', $fixture['item_id'])
            ->assertJsonPath('data.item_status', 'IN_STOCK')
            ->assertJsonPath('data.eligible_for_component_issue', true);
    }

    public function test_resolve_code_bao_ro_khi_ho_so_chua_toi_luot_xuat_linh_kien(): void
    {
        $fixture = $this->boxFixture(3);
        $received = $this->warrantyCase($fixture['headers'], checking: false);

        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $fixture['box_code'],
            'warranty_case_id' => $received,
        ], $fixture['headers'])
            ->assertOk()
            // Mã vẫn hợp lệ; chỉ hồ sơ là chưa tới lượt — hai tín hiệu tách bạch để
            // Mini App chỉ đúng việc ("chuyển hồ sơ sang Đang kiểm tra") thay vì báo
            // chung chung "không quét được".
            ->assertJsonPath('data.eligible_for_component_issue', true)
            ->assertJsonPath('data.warranty_case_status', 'RECEIVED')
            ->assertJsonPath('data.case_allows_issue', false)
            ->assertJsonPath('data.allowed_case_statuses', ['CHECKING', 'REPAIRING']);
    }

    public function test_resolve_code_ma_khong_ton_tai_tra_ly_do_ro_rang(): void
    {
        $fixture = $this->boxFixture(3);

        // `BOX-KHONG-CO-THAT-9999` có ĐÚNG dạng mã hộp, nên lý do rõ ràng ở đây là
        // "SKU [KHONG-CO-THAT] không có trong danh mục" chứ không phải câu chung
        // "Barcode/QR/RFID không tồn tại" — xem test_ma_dang_hop_khong_ra_hop_bao_dung_ly_do.
        // Mã KHÔNG có dạng hộp mới giữ PHYSICAL_CODE_NOT_FOUND.
        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => 'BOX-KHONG-CO-THAT-9999',
        ], $fixture['headers'])
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.eligible_for_component_issue', false)
            ->assertJsonPath('data.eligibility_code', 'BOX_SKU_NOT_FOUND');
    }

    /**
     * Mã CÓ DẠNG mã hộp nhưng không ra hộp phải nói rõ hỏng ở đâu.
     *
     * Câu "Barcode/QR/RFID không tồn tại" đã tốn hai vòng trao đổi với đội Mini
     * App: họ lần lượt kết luận BE thiếu tính năng tạo container, rồi BE chặn
     * nhầm hộp hợp lệ — trong khi mã thử nghiệm chỉ đơn giản là chưa nhập kho.
     */
    public function test_ma_dang_hop_khong_ra_hop_bao_dung_ly_do(): void
    {
        $fixture = $this->boxFixture(3);
        $headers = $fixture['headers'];
        $wh = $fixture['warehouse_id'];

        $resolve = fn (string $code) => $this->postJson(self::RESOLVE, [
            'warehouse_id' => $wh, 'raw_code' => $code,
        ], $headers)->assertOk();

        // SKU trong mã hộp chưa có trong danh mục.
        $resolve('BOX-SKU-KHONG-CO-THAT-0001')
            ->assertJsonPath('data.eligible_for_issue', false)
            ->assertJsonPath('data.eligibility_code', 'BOX_SKU_NOT_FOUND');

        // SKU có thật nhưng là PRODUCT — mã hộp chỉ dành cho linh kiện.
        $product = Sku::query()->where('sku_type', 'PRODUCT')->where('status', 'ACTIVE')->first();
        if ($product !== null) {
            $resolve('BOX-'.$product->sku_code.'-0001')
                ->assertJsonPath('data.eligibility_code', 'BOX_SKU_NOT_COMPONENT');
        }

        // SKU linh kiện đúng, nhưng hộp chưa từng đi qua Post Receipt.
        $resolve('BOX-'.$fixture['sku_code'].'-9999')
            ->assertJsonPath('data.eligible_for_issue', false)
            ->assertJsonPath('data.eligibility_code', 'BOX_NOT_RECEIVED');

        // Mã không phải dạng hộp thì giữ nguyên mã lỗi cũ.
        $resolve('KHONG-PHAI-MA-HOP')
            ->assertJsonPath('data.eligibility_code', 'PHYSICAL_CODE_NOT_FOUND');

        // Toàn bộ vẫn thuần đọc.
        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    /** Hộp hợp lệ phải ELIGIBLE, không phụ thuộc hồ sơ (đó là cờ case_allows_issue riêng). */
    public function test_hop_hop_le_luon_eligible_ke_ca_khi_khong_gui_case(): void
    {
        $fixture = $this->boxFixture(5);

        foreach ([null, $fixture['case_id']] as $caseId) {
            $body = ['warehouse_id' => $fixture['warehouse_id'], 'raw_code' => $fixture['box_code']];
            if ($caseId !== null) {
                $body['warranty_case_id'] = $caseId;
            }

            $this->postJson(self::RESOLVE, $body, $fixture['headers'])
                ->assertOk()
                ->assertJsonPath('data.eligible_for_issue', true)
                ->assertJsonPath('data.eligibility_code', 'ELIGIBLE')
                ->assertJsonPath('data.sku_type', 'COMPONENT')
                ->assertJsonPath('data.available_qty', 5);
        }
    }

    // ------------------------------------------------------------------
    // Hợp đồng client: bí danh đường dẫn + bí danh tên field
    // ------------------------------------------------------------------

    /**
     * Nghiệp vụ này mang sẵn hai tên resource (component-issue-documents và
     * warranty-component-issues) ở cả hai prefix (có và không có mini-app). Client
     * gọi tên nào cũng phải vào đúng một handler — nếu không, mỗi bên tự đoán một
     * đường và lại mất một vòng trao đổi để phát hiện 404.
     */
    public function test_resolve_code_goi_duoc_o_moi_bi_danh_duong_dan(): void
    {
        $fixture = $this->boxFixture(4);

        foreach ([
            '/api/v1/component-issue-documents/resolve-code',
            '/api/v1/warranty-component-issues/resolve-code',
            '/api/v1/mini-app/component-issue-documents/resolve-code',
            self::RESOLVE,
        ] as $path) {
            $this->postJson($path, [
                'warehouse_id' => $fixture['warehouse_id'],
                'raw_code' => $fixture['box_code'],
                'warranty_case_id' => $fixture['case_id'],
            ], $fixture['headers'])
                ->assertOk()
                ->assertJsonPath('data.resolved', true)
                ->assertJsonPath('data.available_qty', 4)
                ->assertJsonPath('data.sku_id', $fixture['sku_id'])
                ->assertJsonPath('data.eligible_for_component_issue', true);
        }

        // Thuần đọc ở mọi bí danh: không đường nào lỡ tay sinh phiếu.
        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    /**
     * `component_sku_id` / `component_sku_code` / `eligible_for_issue` là bí danh
     * cho client Mini App, CÙNG GIÁ TRỊ với tên gốc. Tên gốc phải còn nguyên: đó
     * là quy ước dùng chung của cả 5 endpoint resolve-code.
     */
    public function test_resolve_code_tra_ca_bi_danh_field_lan_ten_goc(): void
    {
        $fixture = $this->boxFixture(6);

        $data = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $fixture['box_code'],
            'warranty_case_id' => $fixture['case_id'],
        ], $fixture['headers'])->assertOk()->json('data');

        foreach ([
            'component_sku_id' => 'sku_id',
            'component_sku_code' => 'sku_code',
            'eligible_for_issue' => 'eligible_for_component_issue',
        ] as $alias => $origin) {
            $this->assertArrayHasKey($alias, $data, "Thiếu bí danh {$alias}.");
            $this->assertArrayHasKey($origin, $data, "Mất tên gốc {$origin}.");
            $this->assertSame($data[$origin], $data[$alias], "Bí danh {$alias} đã trôi khỏi {$origin}.");
        }

        $this->assertSame($fixture['sku_code'], $data['component_sku_code']);
        $this->assertTrue($data['eligible_for_issue']);
    }

    /** Mã hỏng cũng phải có đủ bí danh, để client đọc một kiểu cho mọi trường hợp. */
    public function test_bi_danh_field_co_ca_khi_ma_khong_resolve_duoc(): void
    {
        $fixture = $this->boxFixture(2);

        $data = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => 'BOX-KHONG-CO-THAT-9999',
        ], $fixture['headers'])->assertOk()->json('data');

        $this->assertNull($data['component_sku_id']);
        $this->assertNull($data['component_sku_code']);
        $this->assertFalse($data['eligible_for_issue']);
    }

    /** Bí danh phải phủ cả `record`, không thì FE resolve một đường, ghi một nẻo. */
    public function test_record_goi_duoc_qua_bi_danh_component_issue_documents(): void
    {
        $fixture = $this->boxFixture(9);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $this->postJson('/api/v1/component-issue-documents/record', [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [
                ['raw_code' => $fixture['box_code'], 'quantity' => 2, 'scan_source' => 'CAMERA'],
            ],
        ], $fixture['headers'] + $this->idem())
            ->assertCreated()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'DECREASE')
            ->assertJsonPath('data.lines.0.scanned_qty', 2);

        $this->assertSame($before - 2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    // ------------------------------------------------------------------
    // record — "Xác nhận xuất linh kiện"
    // ------------------------------------------------------------------

    public function test_quet_ma_hop_kem_so_luong_thi_xuat_kho_va_tru_ton(): void
    {
        $fixture = $this->boxFixture(10);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $response = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'note' => 'Thay chổi than',
            'items' => [
                ['raw_code' => $fixture['box_code'], 'quantity' => 3, 'scan_source' => 'CAMERA'],
            ],
        ], $fixture['headers'] + $this->idem());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.posted', true)
            ->assertJsonPath('data.stock_effect', 'DECREASE')
            ->assertJsonPath('data.movement_created', true)
            ->assertJsonPath('data.scan_evidence_count', 1)
            ->assertJsonPath('data.lines.0.required_qty', 3)
            ->assertJsonPath('data.lines.0.scanned_qty', 3)
            ->assertJsonPath('data.lines.0.sku_code', $fixture['sku_code']);

        $this->assertSame($before - 3, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // Subledger của hộp giảm đúng số đã rút.
        $this->assertSame(7, (int) DB::table('container_items')
            ->where('container_id', $fixture['container_id'])
            ->where('is_active', true)
            ->sum('quantity'));

        // Bằng chứng quét được ghi lại (NFR-SRS-004).
        $this->assertTrue(DB::table('scan_batch')
            ->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')
            ->where('source_id', (string) $response->json('data.component_issue_document_id'))
            ->exists());
    }

    public function test_quet_ma_dan_tren_linh_kien_tru_dung_mot_hien_vat(): void
    {
        $fixture = $this->serialFixture();

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['code_value']]],
        ], $fixture['headers'] + $this->idem())
            ->assertCreated()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.lines.0.required_qty', 1)
            ->assertJsonPath('data.items.0.item_id', $fixture['item_id']);

        $this->assertSame(0, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame('ISSUED', (string) DB::table('item_instances')->where('item_id', $fixture['item_id'])->value('item_status'));
    }

    public function test_gom_nhieu_lan_quet_cung_sku_thanh_mot_dong(): void
    {
        $fixture = $this->boxFixture(10);

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [
                ['raw_code' => $fixture['box_code'], 'quantity' => 2],
                ['raw_code' => $fixture['box_code'], 'quantity' => 3],
            ],
        ], $fixture['headers'] + $this->idem())
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.required_qty', 5)
            ->assertJsonPath('data.scan_evidence_count', 2);

        $this->assertSame(5, (int) DB::table('container_items')
            ->where('container_id', $fixture['container_id'])
            ->where('is_active', true)
            ->sum('quantity'));
    }

    public function test_khong_cho_xuat_khi_ho_so_moi_tiep_nhan(): void
    {
        $fixture = $this->boxFixture(5);
        $received = $this->warrantyCase($fixture['headers'], checking: false);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $received,
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_CASE_STATUS_NOT_ALLOWED')
            // Giá trị máy trong details giữ nguyên enum thô: client tự map nhãn (quy ước
            // "enum phải qua map nhãn" nằm ở FE). Pin nhãn "Đã tiếp nhận" trước đây là
            // pin chính lỗi translateDetails() dịch cả giá trị máy.
            ->assertJsonPath('error.details.warranty_case_status', 'RECEIVED');

        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    public function test_khong_cho_xuat_khi_ho_so_da_hoan_tat(): void
    {
        $fixture = $this->boxFixture(5);
        $caseId = $fixture['case_id'];
        $version = (int) DB::table('warranty_case')->where('warranty_case_id', $caseId)->value('version');

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'confirmed_defect' => 'Chổi than mòn',
            'note' => 'Đã thay chổi than và chạy thử',
            'status' => 'COMPLETED',
        ], $fixture['headers'] + ['If-Match' => (string) $version, 'Idempotency-Key' => 'ci-done-'.(string) Str::uuid()])
            ->assertOk();

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $caseId,
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_CASE_STATUS_NOT_ALLOWED');
    }

    public function test_ma_hop_thieu_so_luong_bi_tu_choi_dung_dong(): void
    {
        $fixture = $this->boxFixture(5);

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['box_code']]],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOX_QUANTITY_REQUIRED')
            ->assertJsonPath('error.details.row_no', 1);
    }

    public function test_tong_so_luong_rut_tu_mot_hop_khong_duoc_vuot_ton(): void
    {
        $fixture = $this->boxFixture(4);

        // Từng dòng đều <= tồn hộp, chỉ TỔNG mới vượt — lỗi này chỉ thấy được ở
        // tầng danh sách, đó là lý do prepareBatch cộng dồn trước khi tạo phiếu.
        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [
                ['raw_code' => $fixture['box_code'], 'quantity' => 3],
                ['raw_code' => $fixture['box_code'], 'quantity' => 3],
            ],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_INSUFFICIENT')
            ->assertJsonPath('error.details.row_no', 2)
            ->assertJsonPath('error.details.available_qty', 4);

        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    public function test_quet_trung_cung_mot_hien_vat_trong_danh_sach_bi_chan(): void
    {
        $fixture = $this->serialFixture();

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [
                ['raw_code' => $fixture['code_value']],
                ['raw_code' => $fixture['code_value']],
            ],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_COMPONENT_ITEM')
            ->assertJsonPath('error.details.first_row_no', 1);
    }

    public function test_record_co_the_dung_lai_o_buoc_cho_post(): void
    {
        $fixture = $this->boxFixture(6);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $response = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'post_immediately' => false,
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 2]],
        ], $fixture['headers'] + $this->idem());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.posted', false)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // Post riêng vẫn chạy được trên đúng phiếu đó.
        $documentId = (string) $response->json('data.component_issue_document_id');
        $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '"'.$response->json('data.version').'"',
            'Idempotency-Key' => 'ci-late-post-'.(string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stock_effect', 'DECREASE');

        $this->assertSame($before - 2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_gui_lai_cung_idempotency_key_khong_tru_ton_lan_hai(): void
    {
        $fixture = $this->boxFixture(8);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $payload = [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 2]],
        ];
        $headers = $fixture['headers'] + ['Idempotency-Key' => 'ci-replay-fixed'];

        $first = $this->postJson(self::RECORD, $payload, $headers)->assertCreated();
        $replay = $this->postJson(self::RECORD, $payload, $headers)->assertCreated();

        $this->assertSame(
            (string) $first->json('data.component_issue_document_id'),
            (string) $replay->json('data.component_issue_document_id'),
        );
        $this->assertSame($before - 2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame(1, DB::table('component_issue_documents')->count());
    }

    public function test_field_la_trong_payload_bi_tu_choi(): void
    {
        $fixture = $this->boxFixture(5);

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'ghi_chu_sai_ten' => 'x',
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ------------------------------------------------------------------
    // Sửa sai mà không phải huỷ phiếu
    // ------------------------------------------------------------------

    public function test_go_dong_quet_nham_va_tra_phieu_ve_draft(): void
    {
        $fixture = $this->boxFixture(9);
        $record = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'post_immediately' => false,
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 4]],
        ], $fixture['headers'] + $this->idem())->assertCreated();

        $documentId = (string) $record->json('data.component_issue_document_id');
        $version = (int) $record->json('data.version');

        $detail = $this->getJson("/api/v1/mini-app/warranty-component-issues/{$documentId}", $fixture['headers'])->assertOk();
        $scanRowId = (string) $detail->json('data.lines.0.items.0.id');

        $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/unscan", [
            'component_issue_item_id' => $scanRowId,
            'reason' => 'Quét nhầm hộp',
        ], $fixture['headers'] + ['If-Match' => '"'.$version.'"'])
            ->assertOk()
            ->assertJsonPath('data.removed_qty', 4)
            ->assertJsonPath('data.scanned_qty', 0)
            // Không còn dòng quét nào ACTIVE ⇒ phiếu quay về DRAFT.
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->assertSame('REMOVED', (string) DB::table('component_issue_items')->where('id', $scanRowId)->value('status'));
    }

    public function test_go_dong_quet_bang_chinh_ma_vua_quet(): void
    {
        $fixture = $this->serialFixture();
        $record = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'post_immediately' => false,
            'items' => [['raw_code' => $fixture['code_value']]],
        ], $fixture['headers'] + $this->idem())->assertCreated();

        $documentId = (string) $record->json('data.component_issue_document_id');

        $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/unscan", [
            'code_value' => $fixture['code_value'],
        ], $fixture['headers'] + ['If-Match' => '"'.$record->json('data.version').'"'])
            ->assertOk()
            ->assertJsonPath('data.item_id', $fixture['item_id'])
            ->assertJsonPath('data.scanned_qty', 0);
    }

    public function test_sua_so_luong_can_xuat_roi_post_thay_vi_huy_phieu(): void
    {
        $fixture = $this->boxFixture(9);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        // Phiếu soạn trước theo bề mặt canonical: cần 5 nhưng chỉ quét được 2.
        $create = $this->postJson('/api/v1/mini-app/warranty-component-issues', [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'lines' => [['sku_id' => $fixture['sku_id'], 'required_qty' => 5]],
        ], $fixture['headers'])->assertCreated();
        $documentId = (string) $create->json('data.document.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $scan = $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/scans", [
            'line_id' => $lineId,
            'code_value' => $fixture['box_code'],
            'quantity' => 2,
        ], $fixture['headers'] + ['If-Match' => '"1"'])->assertOk();
        $version = (int) $scan->json('data.version');

        // Hạ dưới số đã quét là vô nghĩa — phải gỡ dòng quét trước.
        $this->patchJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/lines/{$lineId}", [
            'required_qty' => 1,
        ], $fixture['headers'] + ['If-Match' => '"'.$version.'"'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REQUIRED_QTY_BELOW_SCANNED');

        $patch = $this->patchJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/lines/{$lineId}", [
            'required_qty' => 2,
        ], $fixture['headers'] + ['If-Match' => '"'.$version.'"'])
            ->assertOk()
            ->assertJsonPath('data.required_qty', 2)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '"'.$patch->json('data.version').'"',
            'Idempotency-Key' => 'ci-post-'.(string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.stock_effect', 'DECREASE');

        $this->assertSame($before - 2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    public function test_them_va_xoa_dong_linh_kien_cua_phieu_dang_soan(): void
    {
        $fixture = $this->boxFixture(9);
        $other = $this->componentSkuFixture('HN-COMP-EXTRA-'.Str::upper(Str::random(4)));

        $create = $this->postJson('/api/v1/mini-app/warranty-component-issues', [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'lines' => [['sku_id' => $fixture['sku_id'], 'required_qty' => 1]],
        ], $fixture['headers'])->assertCreated();
        $documentId = (string) $create->json('data.document.id');

        $added = $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/lines", [
            'sku_id' => $other->sku_id,
            'required_qty' => 2,
            'note' => 'Phát sinh khi tháo máy',
        ], $fixture['headers'] + ['If-Match' => '"1"'])
            ->assertOk()
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.line_no', 2);

        $addedLineId = (string) $added->json('data.line_id');

        $this->deleteJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/lines/{$addedLineId}", [], $fixture['headers'] + [
            'If-Match' => '"'.$added->json('data.version').'"',
        ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonCount(1, 'data.lines');

        $this->assertSame(1, DB::table('component_issue_lines')->where('component_issue_document_id', $documentId)->count());
    }

    public function test_khong_xoa_duoc_dong_con_bang_chung_quet(): void
    {
        $fixture = $this->boxFixture(9);
        $record = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'post_immediately' => false,
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], $fixture['headers'] + $this->idem())->assertCreated();

        $documentId = (string) $record->json('data.component_issue_document_id');
        $lineId = (string) $record->json('data.lines.0.line_id');

        $this->deleteJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/lines/{$lineId}", [], $fixture['headers'] + [
            'If-Match' => '"'.$record->json('data.version').'"',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_LINE_HAS_SCANS');
    }

    public function test_khong_sua_duoc_phieu_da_post(): void
    {
        $fixture = $this->boxFixture(9);
        $record = $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], $fixture['headers'] + $this->idem())->assertCreated();

        $documentId = (string) $record->json('data.component_issue_document_id');

        $this->postJson("/api/v1/mini-app/warranty-component-issues/{$documentId}/unscan", [
            'code_value' => $fixture['box_code'],
        ], $fixture['headers'] + ['If-Match' => '"'.$record->json('data.version').'"'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    // ------------------------------------------------------------------
    // Danh sách hồ sơ cho màn xuất linh kiện
    // ------------------------------------------------------------------

    public function test_loc_ho_so_bao_hanh_theo_tung_status_da_cong_bo_cua_p09(): void
    {
        $auth = $this->superAdminHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $this->warehouseFixture();

        $this->warrantyCase($headers, checking: false);                 // RECEIVED
        $checkingId = $this->warrantyCase($headers, checking: true);    // CHECKING
        $repairingId = $this->warrantyCase($headers, checking: true);

        $version = (int) DB::table('warranty_case')->where('warranty_case_id', $repairingId)->value('version');
        $this->patchJson("/api/v1/warranty-cases/{$repairingId}", [
            'confirmed_defect' => 'Cháy cuộn dây',
            'status' => 'REPAIRING',
        ], $headers + ['If-Match' => (string) $version, 'Idempotency-Key' => 'ci-rep-'.(string) Str::uuid()])->assertOk();

        // P09 publishes one status per request; do not revive the removed CSV filter.
        $responses = array_map(fn (string $status) => $this->getJson('/api/v1/mini-app/warranty-cases?'.http_build_query([
            'warehouse_id' => (string) $this->warehouseFixture()->id, 'status' => $status, 'per_page' => 50,
        ]), $headers)->assertOk(), ['CHECKING', 'REPAIRING']);

        $ids = collect($responses)->flatMap(fn ($response) => $response->json('data'))->pluck('warranty_case_id')->all();
        sort($ids);
        $expected = [$checkingId, $repairingId];
        sort($expected);

        $this->assertSame($expected, $ids, 'Chỉ hồ sơ Đang kiểm tra + Đang sửa chữa được trả về.');
        // Keep the expected total 2; remove the old fallback that could hide missing metadata.
        $this->assertSame(2, array_sum(array_map(fn ($response) => $response->json('meta.total'), $responses)));
    }

    public function test_loc_mot_trang_thai_van_giu_nguyen_hanh_vi_cu(): void
    {
        $auth = $this->superAdminHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $this->warehouseFixture();

        $this->warrantyCase($headers, checking: false);
        $checkingId = $this->warrantyCase($headers, checking: true);

        $response = $this->getJson('/api/v1/mini-app/warranty-cases?'.http_build_query(['warehouse_id' => (string) $this->warehouseFixture()->id, 'status' => 'CHECKING']), $headers)->assertOk();

        $this->assertSame([$checkingId], collect($response->json('data'))->pluck('warranty_case_id')->all());
    }

    // ------------------------------------------------------------------
    // Phân quyền
    // ------------------------------------------------------------------

    public function test_khong_co_quyen_component_issue_thi_bi_tu_choi(): void
    {
        $fixture = $this->boxFixture(5);
        $stranger = $this->authHeadersWithRole('VIEWER');

        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $fixture['box_code'],
        ], ['Authorization' => $stranger['Authorization']])->assertStatus(403);

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], ['Authorization' => $stranger['Authorization']] + $this->idem())->assertStatus(403);

        $this->assertSame(0, DB::table('component_issue_documents')->count());
    }

    public function test_nhan_vien_bao_hanh_xuat_duoc_linh_kien(): void
    {
        $fixture = $this->boxFixture(5);
        $staff = $this->authHeadersWithRole('WARRANTY_STAFF');

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $fixture['box_code'], 'quantity' => 1]],
        ], ['Authorization' => $staff['Authorization']] + $this->idem())
            ->assertCreated()
            ->assertJsonPath('data.status', 'POSTED');
    }

    public function test_sku_khong_phai_linh_kien_bi_tu_choi(): void
    {
        $fixture = $this->boxFixture(5);
        $product = Sku::create([
            'sku_code' => 'HN-PROD-'.Str::upper(Str::random(5)),
            'sku_name' => 'Máy khoan',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $itemId = $this->seedItemInStock((string) $product->sku_id);
        $codeValue = 'SP-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemId, $codeValue);

        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $fixture['warehouse_id'],
            'raw_code' => $codeValue,
        ], $fixture['headers'])
            ->assertOk()
            ->assertJsonPath('data.eligible_for_component_issue', false)
            ->assertJsonPath('data.eligibility_code', 'COMPONENT_SKU_REQUIRED');

        $this->postJson(self::RECORD, [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'items' => [['raw_code' => $codeValue]],
        ], $fixture['headers'] + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_SKU_REQUIRED');
    }
}
