<?php

namespace Tests\Feature\Cache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * Mục 8: tồn kho là correctness-sensitive. Test đi qua HTTP thật END-TO-END (không
 * mock CacheService) để chứng minh: post-receipt thật (qua
 * CurrentBaselineStockService::mutateBalance — điểm chốt duy nhất ghi
 * inventory_balances) bump `stock:version` và GET /api/v1/inventory,
 * /api/v1/reports/inventory-balance, /api/v1/reports/dashboard-quick phản ánh dữ
 * liệu mới NGAY LẬP TỨC, không chờ TTL ngắn (stock_short/report/dashboard).
 */
class StockCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'stock-cache-'.Str::lower(Str::random(6)),
            'display_name' => 'Stock Cache Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    /** Nhận kho thật qua HTTP: create -> box -> scan -> post-receipt. */
    private function postComponentReceipt(array $headers, string $skuCode, int $qty): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Hợp đồng GĐ1 scan-driven: header-only + materialize dòng COMPONENT qua
        // /component-lines (không còn tạo phiếu kèm lines/qty_planned).
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Stock cache test fixture',
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $register->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'receipt-'.Str::random(12),
        ])->assertOk();

        return ['warehouse_id' => (string) $warehouse->id, 'sku_id' => (string) $sku->sku_id];
    }

    public function test_inventory_balance_list_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();

        // Warm cache TRƯỚC khi có tồn kho — endpoint list toàn bộ (không filter theo
        // SKU cụ thể vì SKU chưa tồn tại lúc này).
        $before = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $beforeTotal = (int) $before->json('meta.total');

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-INV-01', 7);

        $after = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $afterTotal = (int) $after->json('meta.total');
        $afterRows = collect($after->json('data'));
        $row = $afterRows->firstWhere('sku_id', $fixture['sku_id']);

        $this->assertGreaterThan($beforeTotal, $afterTotal, 'Danh sách tồn kho phải phản ánh dòng balance mới ngay sau post-receipt.');
        $this->assertNotNull($row, 'Balance của SKU vừa nhập phải xuất hiện ngay, không đợi TTL stock_short hết hạn.');
        $this->assertSame(7.0, (float) $row['on_hand_qty']);
    }

    public function test_dashboard_quick_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();

        $before = $this->getJson('/api/v1/reports/dashboard-quick', $headers)->assertOk();
        $beforeAvailable = (float) $before->json('data.available_qty');

        $this->postComponentReceipt($headers, 'STOCK-CACHE-DASH-01', 4);

        $after = $this->getJson('/api/v1/reports/dashboard-quick', $headers)->assertOk();
        $afterAvailable = (float) $after->json('data.available_qty');

        $this->assertGreaterThanOrEqual(
            $beforeAvailable + 4,
            $afterAvailable,
            'dashboard-quick available_qty phải cộng dồn ngay sau post-receipt, không được trả cache cũ.'
        );
    }

    public function test_report_inventory_balance_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();

        $this->getJson('/api/v1/reports/inventory-balance?warehouse_id='.$warehouse->id, $headers)->assertOk();

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-RPT-01', 9);

        $after = $this->getJson('/api/v1/reports/inventory-balance?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $row = collect($after->json('data.rows'))->firstWhere('product_id', $fixture['sku_id']);

        $this->assertNotNull($row, 'Report inventory-balance phải thấy SKU vừa post ngay lập tức.');
        $this->assertSame(9.0, (float) $row['on_hand_qty']);
    }

    public function test_inventory_balance_warehouse_filter_does_not_leak_across_filters(): void
    {
        $headers = $this->authHeaders();
        $warehouseA = $this->canonicalWarehouse();

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-FILTER-01', 3);

        $matching = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouseA->id.'&sku_id='.$fixture['sku_id'], $headers)->assertOk();
        $nonMatching = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouseA->id.'&sku_id='.((string) Str::uuid()), $headers)->assertOk();

        $this->assertGreaterThan(0, (int) $matching->json('meta.total'));
        $this->assertSame(0, (int) $nonMatching->json('meta.total'), 'Filter theo sku_id khác nhau không được đụng cache key.');
    }

    /**
     * 5 báo cáo nặng mới được đưa vào cache (xnt, xnt-daily, document-history,
     * outbound-by-agency, movement-trace) dùng CHUNG version namespace
     * `stock:version` với các endpoint đã có cache từ trước. Test này chứng minh
     * điều đó đúng END-TO-END: hâm nóng cache khi chưa có tồn, post-receipt thật,
     * rồi đọc lại phải thấy dữ liệu mới NGAY — không chờ hết TTL 'report' (300s).
     *
     * Thiếu test này thì một lần đổi namespace hoặc quên bump sẽ biến 5 endpoint
     * trên thành nguồn số liệu cũ suốt 5 phút mà không ai phát hiện.
     */
    public function test_cached_heavy_reports_reflect_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();

        // Vài báo cáo bắt buộc khoảng ngày (422 nếu thiếu) — dùng chung một kỳ
        // bao trọn thời điểm chạy test để mọi endpoint đều hợp lệ.
        $period = 'from_date='.now()->subDay()->toDateString().'&to_date='.now()->addDay()->toDateString();

        $endpoints = [
            '/api/v1/reports/inventory-xnt?'.$period.'&warehouse_id='.$warehouse->id,
            '/api/v1/reports/inventory-xnt-daily?'.$period.'&warehouse_id='.$warehouse->id,
            '/api/v1/reports/document-history?'.$period,
            '/api/v1/reports/outbound-by-agency?'.$period,
            '/api/v1/reports/movement-trace?'.$period,
        ];

        // Hâm nóng: mỗi endpoint ghi một entry ứng với version hiện tại.
        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint, $headers)->assertOk();
        }

        $skuCode = 'STOCK-CACHE-HEAVY-01';
        $this->postComponentReceipt($headers, $skuCode, 5);

        // Đọc lại: nếu version không bump, các lời gọi này trả nguyên payload cũ.
        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint, $headers)->assertOk();
        }

        $xnt = $this->getJson('/api/v1/reports/inventory-xnt?'.$period.'&warehouse_id='.$warehouse->id, $headers)->assertOk();
        // Báo cáo XNT phát hành theo MÃ SKU (product_code), không có product_id.
        $line = collect($xnt->json('data.lines'))
            ->first(fn (array $row): bool => ($row['product_code'] ?? null) === $skuCode);

        $this->assertNotNull(
            $line,
            'Báo cáo XNT phải thấy SKU vừa post-receipt ngay; null nghĩa là cache không bị bump theo stock:version.'
        );

        $trace = $this->getJson('/api/v1/reports/movement-trace?'.$period, $headers)->assertOk();
        $traced = collect($trace->json('data.rows'))
            ->contains(fn (array $row): bool => ($row['product_code'] ?? null) === $skuCode);

        $this->assertTrue(
            $traced,
            'Sổ movement phải có bút toán vừa sinh; thiếu nghĩa là đang phục vụ entry cache dựng trước lúc post.'
        );
    }

    /**
     * Payload báo cáo phải ĐI QUA được cache store rồi quay lại nguyên vẹn.
     *
     * `config/cache.php` đặt `serializable_classes = false` (chặn gadget-chain khi
     * unserialize). Bất kỳ PHP object nào lọt vào payload — Collection của query
     * builder, stdClass của từng dòng `->get()`, Carbon — khi đọc lại sẽ thành
     * `__PHP_Incomplete_Class`; CacheService coi đó là miss nên KHÔNG sai dữ liệu,
     * nhưng cache không bao giờ hit và mỗi request vẫn chạy full SQL.
     *
     * Đây đúng là lỗi đã xảy ra thật với 4 báo cáo của BaM1ReportController (thấy
     * qua `cache.corrupted` trong log). Test chốt lại: hai lần gọi liên tiếp phải
     * cho payload y hệt nhau.
     */
    public function test_report_payloads_survive_a_cache_round_trip(): void
    {
        $headers = $this->authHeaders();
        $this->postComponentReceipt($headers, 'STOCK-CACHE-ROUNDTRIP-01', 2);

        $period = 'from_date='.now()->subDays(7)->toDateString()
            .'&to_date='.now()->addDay()->toDateString();

        $endpoints = [
            '/api/v1/reports/outbound-by-recipient?'.$period,
            '/api/v1/reports/warranty-defects?'.$period,
            '/api/v1/reports/warranty-defect-sku-breakdown?'.$period,
            '/api/v1/reports/warranty-component-usage?'.$period,
            '/api/v1/reports/import-match?'.$period,
            '/api/v1/reports/label-log?'.$period,
            '/api/v1/reports/inventory-reconciliation',
        ];

        foreach ($endpoints as $endpoint) {
            $first = $this->getJson($endpoint, $headers)->assertOk()->json('data');
            $second = $this->getJson($endpoint, $headers)->assertOk()->json('data');

            $this->assertSame(
                $first,
                $second,
                "Payload của {$endpoint} đổi giữa hai lần gọi liên tiếp — dấu hiệu cache không dùng lại được."
            );
        }
    }

    /**
     * Báo cáo phân trang TRONG SQL phải có page/per_page trong cache key.
     *
     * Nhóm BaM1 dùng LIMIT/OFFSET ở SQL, khác với các báo cáo cắt trang trong bộ
     * nhớ. Dùng chung một entry cho mọi trang thì người xem trang 2 sẽ nhận lại
     * đúng nội dung trang 1.
     */
    public function test_sql_paginated_report_separates_pages_in_cache(): void
    {
        $headers = $this->authHeaders();
        $period = 'from_date='.now()->subDays(30)->toDateString()
            .'&to_date='.now()->addDay()->toDateString();

        $page1 = $this->getJson(
            '/api/v1/reports/warranty-defects?'.$period.'&page=1&per_page=1',
            $headers
        )->assertOk();
        $page2 = $this->getJson(
            '/api/v1/reports/warranty-defects?'.$period.'&page=2&per_page=1',
            $headers
        )->assertOk();

        $this->assertSame(1, (int) $page1->json('data.pagination.current_page'));
        $this->assertSame(2, (int) $page2->json('data.pagination.current_page'));
    }

    /**
     * Cache key của báo cáo phải đổi theo BỘ LỌC. Đây là lớp chống "trả nhầm số
     * liệu của bộ lọc khác" — kiểu lỗi nguy hiểm nhất khi thêm cache vào báo cáo.
     */
    public function test_report_cache_key_separates_different_filters(): void
    {
        $headers = $this->authHeaders();

        $this->postComponentReceipt($headers, 'STOCK-CACHE-FILTER-RPT-01', 6);

        $inRange = $this->getJson(
            '/api/v1/reports/document-history?from_date='.now()->subDay()->toDateString()
                .'&to_date='.now()->addDay()->toDateString(),
            $headers
        )->assertOk();
        $outOfRange = $this->getJson(
            '/api/v1/reports/document-history?from_date=2000-01-01&to_date=2000-01-02',
            $headers
        )->assertOk();

        $this->assertGreaterThan(
            count($outOfRange->json('data.rows')),
            count($inRange->json('data.rows')),
            'Hai khoảng ngày khác nhau phải cho hai kết quả khác nhau — dùng chung cache key là trả sai số liệu.'
        );
    }
}
