<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-240 — Reversal net effect trong báo cáo XNT và trace chain.
 *
 * Hai lỗi gốc bị khoá lại ở đây:
 *
 * 1. DEV240-P1-02A — `ReportController::buildXnt()` chỉ có MỘT cột `reversal_net` là tổng
 *    `qty_delta` có dấu của mọi REVERSAL. Đảo-nhập (âm) và đảo-xuất (dương) triệt tiêu
 *    lẫn nhau, nên không đọc được nhập/xuất THỰC của kỳ, trái BR-RPT-01..07 ("hiển thị
 *    gross/net") và BR-REV-11 ("report hiển thị... net effect").
 *
 * 2. DEV240-P1-02B — `StockMovementController::traceSource()` chỉ trả
 *    `StockMovement::where('original_movement_id', $id)->first()`: đúng MỘT reversal.
 *    BR-REV-05 cho phép đảo từng phần nhiều lần và BR-REV-08 cho phép reversal-của-reversal,
 *    nên bản cũ ẩn mất lịch sử và không thể tính net effect từ response.
 *
 * Bất biến then chốt được assert ở mọi ca: closing theo cột NET **bằng đúng** closing theo
 * công thức gross cũ, và cũng bằng tổng `qty_delta` của ledger. Không ca nào được phép
 * "làm đẹp" báo cáo bằng cách đánh mất một bút toán.
 */
class Wwhn240ReversalNetEffectContractTest extends TestCase
{
    use RefreshDatabase;

    /** Warehouse id ngoài mọi phạm vi hợp lệ — dùng làm scope_ref của actor "kho khác". */
    private const FOREIGN_WAREHOUSE_ID = '00000000-0000-4000-8000-000000000240';

    private User $admin;

    private string $token;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $this->admin = User::create([
            'username' => 'wwhn240-admin-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-240 Admin',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $this->admin->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->token = auth('api')->login($this->admin);

        $this->warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
    }

    // ══════════════════════════════════════════════════════════
    //  DEV240-P1-02A · XNT gross / reversal / net
    // ══════════════════════════════════════════════════════════

    public function test_xnt_tach_reversal_theo_movement_type_goc(): void
    {
        $sku = $this->sku('WWHN240-XNT-MIX');
        $today = now()->toDateString();

        $this->movement('OPENING', $sku, 100, now()->subDay()->toDateString());
        $receipt = $this->movement('RECEIPT', $sku, 30, $today);
        $issue = $this->movement('ISSUE', $sku, -20, $today);
        $component = $this->movement('COMPONENT_ISSUE', $sku, -5, $today);
        // Đảo 4 của RECEIPT (delta âm), 6 của ISSUE (delta dương), 2 của COMPONENT_ISSUE.
        $this->movement('REVERSAL', $sku, -4, $today, $receipt);
        $this->movement('REVERSAL', $sku, 6, $today, $issue);
        $this->movement('REVERSAL', $sku, 2, $today, $component);

        $line = $this->xntLine($sku, $today);

        // GROSS giữ nguyên — BR-REV-11 cấm ẩn lịch sử.
        $this->assertSame(30.0, (float) $line['receipt_gross']);
        $this->assertSame(20.0, (float) $line['issue_gross']);
        $this->assertSame(5.0, (float) $line['component_issue_gross']);
        // Bí danh tương thích không được đổi nghĩa.
        $this->assertSame((float) $line['receipt_gross'], (float) $line['receipt']);
        $this->assertSame((float) $line['issue_gross'], (float) $line['issue']);
        $this->assertSame((float) $line['component_issue_gross'], (float) $line['component_issue']);

        // REVERSAL quy về cùng chiều dương với cột gross tương ứng.
        $this->assertSame(4.0, (float) $line['receipt_reversal']);
        $this->assertSame(6.0, (float) $line['issue_reversal']);
        $this->assertSame(2.0, (float) $line['component_issue_reversal']);
        $this->assertSame(0.0, (float) $line['reversal_unlinked_net']);

        // NET.
        $this->assertSame(26.0, (float) $line['receipt_net']);
        $this->assertSame(14.0, (float) $line['issue_net']);
        $this->assertSame(3.0, (float) $line['component_issue_net']);

        // Cột gộp cũ vẫn đúng bằng tổng có dấu: -4 + 6 + 2 = 4.
        $this->assertSame(4.0, (float) $line['reversal_net']);
        $this->assertXntInvariants($line, $sku, $today);
        $this->assertSame(109.0, (float) $line['closing']);
    }

    public function test_xnt_khong_gop_nham_dao_xuat_voi_dao_xuat_linh_kien(): void
    {
        $sku = $this->sku('WWHN240-XNT-SIGN');
        $today = now()->toDateString();

        // Cả hai reversal đều có qty_delta DƯƠNG. Nếu phân loại bằng dấu thay vì bằng
        // movement_type gốc thì hai cột nghiệp vụ khác nhau sẽ bị gộp làm một.
        $issue = $this->movement('ISSUE', $sku, -9, $today);
        $component = $this->movement('COMPONENT_ISSUE', $sku, -7, $today);
        $this->movement('REVERSAL', $sku, 9, $today, $issue);
        $this->movement('REVERSAL', $sku, 7, $today, $component);

        $line = $this->xntLine($sku, $today);

        $this->assertSame(9.0, (float) $line['issue_reversal']);
        $this->assertSame(7.0, (float) $line['component_issue_reversal']);
        $this->assertSame(0.0, (float) $line['issue_net']);
        $this->assertSame(0.0, (float) $line['component_issue_net']);
        $this->assertXntInvariants($line, $sku, $today);
    }

    public function test_xnt_dao_cua_dao_tra_lai_gross_ban_dau(): void
    {
        $sku = $this->sku('WWHN240-XNT-CHAIN');
        $today = now()->toDateString();

        // BR-REV-08: sửa tiếp một Reversal POSTED bằng cách tạo Reversal MỚI trỏ vào
        // movement gần nhất trong chain, chứ không sửa/xoá.
        $receipt = $this->movement('RECEIPT', $sku, 10, $today);
        $undo = $this->movement('REVERSAL', $sku, -4, $today, $receipt);
        $this->movement('REVERSAL', $sku, 4, $today, $undo);

        $line = $this->xntLine($sku, $today);

        $this->assertSame(10.0, (float) $line['receipt_gross']);
        // Đảo rồi lại đảo ngược => lượng đã đảo ròng bằng 0, nhập thuần trở về 10.
        $this->assertSame(0.0, (float) $line['receipt_reversal']);
        $this->assertSame(10.0, (float) $line['receipt_net']);
        $this->assertSame(0.0, (float) $line['reversal_unlinked_net']);
        $this->assertXntInvariants($line, $sku, $today);
        $this->assertSame(10.0, (float) $line['closing']);
    }

    public function test_xnt_reversal_khong_truy_duoc_goc_van_vao_closing(): void
    {
        $sku = $this->sku('WWHN240-XNT-ORPHAN');
        $today = now()->toDateString();

        $this->movement('RECEIPT', $sku, 12, $today);
        // Dữ liệu migrate: REVERSAL không có original_movement_id. Không được rơi khỏi
        // closing, nếu không báo cáo sẽ lệch với ledger authoritative.
        $this->movement('REVERSAL', $sku, -5, $today);

        $line = $this->xntLine($sku, $today);

        $this->assertSame(0.0, (float) $line['receipt_reversal']);
        $this->assertSame(12.0, (float) $line['receipt_net']);
        $this->assertSame(-5.0, (float) $line['reversal_unlinked_net']);
        $this->assertXntInvariants($line, $sku, $today);
        $this->assertSame(7.0, (float) $line['closing']);
    }

    public function test_xnt_cong_bo_ngu_nghia_cot_gross_reversal_net(): void
    {
        $sku = $this->sku('WWHN240-XNT-SEMANTICS');
        $today = now()->toDateString();
        $this->movement('RECEIPT', $sku, 1, $today);

        $response = $this->api("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}");
        $response->assertOk();

        // BR-RPT-01..07: baseline yêu cầu "hiển thị gross/net" — payload phải tự khai
        // ngữ nghĩa để FE/BA/QA không phải suy diễn từ tên cột.
        $semantics = $response->json('data.column_semantics');
        $this->assertIsArray($semantics);
        foreach (['receipt_gross', 'receipt_reversal', 'receipt_net', 'issue_net', 'reversal_net', 'closing'] as $column) {
            $this->assertArrayHasKey($column, $semantics);
        }
        $this->assertStringStartsWith('GROSS', (string) $semantics['receipt_gross']);
        $this->assertStringStartsWith('REVERSAL', (string) $semantics['receipt_reversal']);
        $this->assertStringStartsWith('NET', (string) $semantics['receipt_net']);

        // Công thức cũ giữ nguyên (client hiện hành đang assert đúng chuỗi này).
        $response->assertJsonPath('data.formula', 'Closing = Opening + Receipt - Issue - Component Issue + Reversal');
        $this->assertStringContainsString('Receipt Net = Receipt Gross - Receipt Reversal', (string) $response->json('data.formula_net'));
    }

    public function test_xnt_export_van_tra_xlsx_voi_bo_cot_gross_reversal_net(): void
    {
        $sku = $this->sku('WWHN240-XNT-EXPORT');
        $today = now()->toDateString();
        $receipt = $this->movement('RECEIPT', $sku, 3, $today);
        $this->movement('REVERSAL', $sku, -1, $today, $receipt);

        $this->api("/api/v1/reports/inventory-xnt/export-excel?from_date={$today}&to_date={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ══════════════════════════════════════════════════════════
    //  DEV240-P1-02B · trace-source chain + net effect
    // ══════════════════════════════════════════════════════════

    public function test_trace_source_tra_du_chain_va_net_effect_cho_movement_goc(): void
    {
        $sku = $this->sku('WWHN240-TRACE-BASE');
        $today = now()->toDateString();

        $receipt = $this->movement('RECEIPT', $sku, 10, $today);
        $this->movement('REVERSAL', $sku, -3, $today, $receipt);

        $response = $this->api("/api/v1/stock-movements/{$receipt}/trace-source");
        $response->assertOk();

        $nodes = $response->json('data.correction_chain.nodes');
        $this->assertCount(2, $nodes);
        $this->assertSame('ORIGINAL', $nodes[0]['role']);
        $this->assertSame(0, $nodes[0]['depth']);
        $this->assertTrue($nodes[0]['is_subject']);
        $this->assertSame('REVERSAL', $nodes[1]['role']);
        $this->assertSame(1, $nodes[1]['depth']);
        $this->assertFalse($nodes[1]['is_subject']);
        $this->assertSame($receipt, $nodes[1]['original_movement_id']);

        $response->assertJsonPath('data.correction_chain.root_movement_id', $receipt)
            ->assertJsonPath('data.correction_chain.reversal_count', 1)
            ->assertJsonPath('data.correction_chain.truncated', false)
            ->assertJsonPath('data.net_effect.basis', 'LEDGER')
            ->assertJsonPath('data.net_effect.original_movement_type', 'RECEIPT')
            ->assertJsonPath('data.net_effect.original_qty', 10)
            ->assertJsonPath('data.net_effect.reversed_qty', 3)
            ->assertJsonPath('data.net_effect.remaining_reversible_qty', 7)
            ->assertJsonPath('data.net_effect.net_qty_delta', 7)
            ->assertJsonPath('data.net_effect.fully_reversed', false);

        // Khoá cũ vẫn còn nguyên hình dạng — client hiện hành không được vỡ.
        $this->assertNotNull($response->json('data.reversal_movement'));
        $this->assertNull($response->json('data.original_movement'));
    }

    public function test_trace_source_khong_con_giau_reversal_thu_hai(): void
    {
        $sku = $this->sku('WWHN240-TRACE-PARTIAL');
        $today = now()->toDateString();

        // BR-REV-05: đảo từng phần nhiều lần trên cùng một movement gốc.
        $receipt = $this->movement('RECEIPT', $sku, 10, $today);
        $this->movement('REVERSAL', $sku, -3, $today, $receipt);
        $this->movement('REVERSAL', $sku, -2, $today, $receipt);

        $response = $this->api("/api/v1/stock-movements/{$receipt}/trace-source");
        $response->assertOk();

        $this->assertCount(2, $response->json('data.reversal_movements'));
        $this->assertCount(3, $response->json('data.correction_chain.nodes'));
        $response->assertJsonPath('data.net_effect.reversed_qty', 5)
            ->assertJsonPath('data.net_effect.remaining_reversible_qty', 5)
            ->assertJsonPath('data.net_effect.net_qty_delta', 5)
            ->assertJsonPath('data.net_effect.reversal_count', 2);
    }

    public function test_trace_source_di_nguoc_ve_goc_khi_tra_tu_mot_reversal(): void
    {
        $sku = $this->sku('WWHN240-TRACE-UPWARD');
        $today = now()->toDateString();

        $receipt = $this->movement('RECEIPT', $sku, 8, $today);
        $undo = $this->movement('REVERSAL', $sku, -8, $today, $receipt);
        $redo = $this->movement('REVERSAL', $sku, 8, $today, $undo);

        // Tra từ mắt xích sâu nhất vẫn phải thấy nguyên chain và cùng một net effect.
        $response = $this->api("/api/v1/stock-movements/{$redo}/trace-source");
        $response->assertOk()
            ->assertJsonPath('data.correction_chain.root_movement_id', $receipt)
            ->assertJsonPath('data.correction_chain.node_count', 3)
            ->assertJsonPath('data.correction_chain.max_depth', 2)
            ->assertJsonPath('data.net_effect.original_movement_id', $receipt)
            ->assertJsonPath('data.net_effect.reversed_qty', 0)
            ->assertJsonPath('data.net_effect.net_qty_delta', 8)
            ->assertJsonPath('data.net_effect.fully_reversed', false);

        $this->assertSame($undo, $response->json('data.original_movement.id'));
        $this->assertSame($receipt, $response->json('data.root_movement.id'));
    }

    public function test_trace_source_danh_dau_fully_reversed_khi_dao_het(): void
    {
        $sku = $this->sku('WWHN240-TRACE-FULL');
        $today = now()->toDateString();

        $issue = $this->movement('ISSUE', $sku, -6, $today);
        $this->movement('REVERSAL', $sku, 6, $today, $issue);

        $this->api("/api/v1/stock-movements/{$issue}/trace-source")
            ->assertOk()
            ->assertJsonPath('data.net_effect.original_qty', 6)
            ->assertJsonPath('data.net_effect.reversed_qty', 6)
            ->assertJsonPath('data.net_effect.remaining_reversible_qty', 0)
            ->assertJsonPath('data.net_effect.net_qty_delta', 0)
            ->assertJsonPath('data.net_effect.fully_reversed', true);
    }

    public function test_trace_source_movement_khong_ton_tai_tra_404(): void
    {
        $response = $this->api('/api/v1/stock-movements/'.self::FOREIGN_WAREHOUSE_ID.'/trace-source')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonStructure(['error' => ['code', 'details'], 'meta' => ['request_id', 'timestamp']]);

        // QA240-10 (§8.2): envelope lỗi chuẩn — error.message không rỗng, meta.request_id thật.
        $this->assertNotEmpty($response->json('message'), 'Envelope lỗi chuẩn phải có message.');
        $this->assertNotNull($response->json('meta.request_id'), 'Envelope lỗi chuẩn phải có meta.request_id.');
    }

    // ══════════════════════════════════════════════════════════
    //  DEV240-P1-04 · RBAC / data scope trên đúng response mới
    // ══════════════════════════════════════════════════════════

    public function test_trace_source_ngoai_warehouse_scope_bi_tu_choi_403(): void
    {
        $sku = $this->sku('WWHN240-SCOPE-DENY');
        $today = now()->toDateString();
        $receipt = $this->movement('RECEIPT', $sku, 5, $today);
        $this->movement('REVERSAL', $sku, -2, $today, $receipt);

        $foreign = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, ['inventory.view', 'inventory.trace']);

        $response = $this->getJson("/api/v1/stock-movements/{$receipt}/trace-source", $foreign)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // QA240-11: từ chối ngoài scope không được rò rỉ bất kỳ node nào của chain.
        $this->assertArrayNotHasKey('data', $response->json(), 'Response 403 không được có khối data chứa chain.');
        $body = $response->getContent();
        $this->assertStringNotContainsString('correction_chain', $body);
        $this->assertStringNotContainsString('"nodes"', $body);
        $this->assertStringNotContainsString('net_effect', $body);
    }

    public function test_trace_source_thieu_inventory_view_bi_tu_choi_403(): void
    {
        $sku = $this->sku('WWHN240-PERM-DENY');
        $today = now()->toDateString();
        $receipt = $this->movement('RECEIPT', $sku, 5, $today);

        // Chỉ có inventory.trace, thiếu inventory.view: trace là read nâng cao nên phải
        // có cả hai (EnforceInventoryWarehouseScope).
        $partial = $this->scopedHeaders((string) $this->warehouse->id, ['inventory.trace']);

        $this->getJson("/api/v1/stock-movements/{$receipt}/trace-source", $partial)
            ->assertStatus(403);
    }

    public function test_trace_source_trong_scope_van_doc_duoc_day_du_chain(): void
    {
        $sku = $this->sku('WWHN240-SCOPE-ALLOW');
        $today = now()->toDateString();
        $receipt = $this->movement('RECEIPT', $sku, 5, $today);
        $this->movement('REVERSAL', $sku, -2, $today, $receipt);

        // Ca đối chứng chống guard chặn nhầm.
        $inScope = $this->scopedHeaders((string) $this->warehouse->id, ['inventory.view', 'inventory.trace']);

        $this->getJson("/api/v1/stock-movements/{$receipt}/trace-source", $inScope)
            ->assertOk()
            ->assertJsonPath('data.correction_chain.node_count', 2)
            ->assertJsonPath('data.net_effect.reversed_qty', 2);
    }

    /**
     * QA240-14 — thiếu `report.view` gọi `GET /api/v1/reports/inventory-xnt` phải bị
     * từ chối 403. Trước bản vá này chưa có Feature test nào gửi HTTP thật tới đúng
     * route `inventory-xnt` với actor thiếu permission (chỉ có test lân cận cho
     * `dashboard-quick` và test đăng ký route không gửi request thật).
     */
    public function test_inventory_xnt_report_requires_report_view_permission(): void
    {
        $sku = $this->sku('WWHN240-RBAC-REPORT');
        $today = now()->toDateString();
        $this->movement('RECEIPT', $sku, 5, $today);

        // Đủ inventory.view nhưng KHÔNG có report.view — permission gate của route
        // (Modules/Warehouse/routes/api.php:186) phải chặn trước khi tới controller.
        $withoutReportView = $this->scopedHeaders((string) $this->warehouse->id, ['inventory.view']);

        $this->getJson("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}", $withoutReportView)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    // ══════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════

    private function api(string $url)
    {
        return $this->getJson($url, ['Authorization' => "Bearer {$this->token}"]);
    }

    private function sku(string $code): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => 'WWHN-240 '.$code,
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }

    /** @return string movement id */
    private function movement(string $type, Sku $sku, float $delta, string $date, ?string $originalMovementId = null): string
    {
        $movement = StockMovement::create([
            'movement_no' => 'MV-'.Str::upper(Str::random(10)),
            'business_key' => 'wwhn240:'.Str::lower(Str::random(12)),
            'movement_type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $sku->sku_id,
            'sku_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE',
            // ERD bảng 30: quantity NOT NULL và luôn dương; chiều nằm ở movement_type.
            'quantity' => abs($delta),
            'qty_before' => 0,
            'qty_delta' => $delta,
            'qty_after' => abs($delta),
            'original_movement_id' => $originalMovementId,
            'created_by' => $this->admin->user_id,
            'occurred_at' => "{$date} 12:00:00",
            'created_at' => "{$date} 12:00:00",
        ]);

        return (string) $movement->id;
    }

    /** @return array<string, mixed> */
    private function xntLine(Sku $sku, string $date): array
    {
        $response = $this->api("/api/v1/reports/inventory-xnt?from_date={$date}&to_date={$date}&product_id={$sku->sku_id}");
        $response->assertOk();
        $lines = $response->json('data.lines');
        $this->assertCount(1, $lines, 'Mỗi ca chỉ dựng đúng một tổ hợp kho/SKU/stock_status.');

        return $lines[0];
    }

    /**
     * Bất biến sống còn của báo cáo: cột NET không được làm mất bút toán nào.
     *
     * @param  array<string, mixed>  $line
     */
    private function assertXntInvariants(array $line, Sku $sku, string $date): void
    {
        $grossClosing = (float) $line['opening'] + (float) $line['receipt'] - (float) $line['issue']
            - (float) $line['component_issue'] + (float) $line['reversal_net'];
        $this->assertEqualsWithDelta($grossClosing, (float) $line['closing'], 1e-9, 'closing theo cột NET phải bằng closing theo công thức gross.');

        $reversalRecomposed = -(float) $line['receipt_reversal'] + (float) $line['issue_reversal']
            + (float) $line['component_issue_reversal'] + (float) $line['reversal_unlinked_net'];
        $this->assertEqualsWithDelta((float) $line['reversal_net'], $reversalRecomposed, 1e-9, 'Tổng ba cột reversal có dấu phải tái lập đúng reversal_net.');

        $ledgerTotal = (float) DB::table('stock_movements')
            ->where('product_id', $sku->sku_id)
            ->where('occurred_at', '<=', "{$date} 23:59:59")
            ->sum('qty_delta');
        $this->assertEqualsWithDelta($ledgerTotal, (float) $line['closing'], 1e-9, 'closing phải khớp tổng qty_delta của ledger authoritative (DEC-INV-001).');
    }

    /**
     * Actor bị giới hạn đúng một kho qua `user_role.scope_ref`.
     *
     * @param  list<string>  $permissionKeys
     * @return array<string, string>
     */
    private function scopedHeaders(string $warehouseId, array $permissionKeys): array
    {
        $roleCode = 'WWHN240_SCOPED_'.Str::upper(Str::random(6));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $user = User::create([
            'username' => 'wwhn240-scoped-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-240 Scoped Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseId,
        ]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }
}
