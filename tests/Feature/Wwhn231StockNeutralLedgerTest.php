<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\LedgerReconciliationService;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-231 — khoá phạm vi sau khi re-scope (xem docs/decision-records/DISP-STK-231-ledger-rescope.md).
 *
 * Task này KHÔNG còn là "kiểm kê / variance / apply adjustment" (mô hình A). Phạm vi hiện
 * hành là đối soát ledger stock-neutral + tối ưu truy vấn. Bộ test dưới đây khoá đúng hai
 * mệnh đề mà register P0 yêu cầu, theo đúng thứ tự:
 *
 *   DEV231-P0-01  không tồn tại API sửa tồn ngoài Post Receipt / Post Issue / Reversal
 *                 (REQ-SRS-037, AC-SRS-037, BR-NOSTK-01..03)
 *   DEV231-P0-02  scan là stock-neutral; movement/balance chỉ đổi ở posting action
 *                 (SRS §5.1 "Scan không có stock effect", §5.2, §13.7 "no stock effect")
 *   DEV231-P1-03  hình dạng query mới cho cùng kết quả với bản cũ và có index phục vụ
 *   DEV231-P1-04  đối soát Ledger↔Balance đọc-thuần, không tự sửa tồn (§7.5)
 */
class Wwhn231StockNeutralLedgerTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * DEV231-P0-01. Quét chính route table đang chạy, đối chiếu với
     * `forbidden_path_fragments` của baseline contract thay vì một danh sách chép tay —
     * contract siết thêm mảnh nào thì test siết theo, không cần sửa test.
     */
    public function test_no_stock_mutating_route_exists_outside_canonical_posting_surface(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $fragments = $contract['api']['forbidden_path_fragments'];
        $this->assertContains('stocktake', $fragments);
        $this->assertContains('adjustment', $fragments);
        $this->assertContains('variance', $fragments);

        $offending = [];
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            foreach ($fragments as $fragment) {
                if (str_contains($uri, strtolower((string) $fragment))) {
                    $offending[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
                }
            }
        }

        $this->assertSame([], $offending, 'REQ-SRS-037 cấm mọi bề mặt kiểm kê/variance/sửa tồn trực tiếp; tìm thấy: '.implode(', ', $offending));
    }

    /**
     * DEV231-P0-01. Đường ghi tồn hợp lệ duy nhất vẫn còn nguyên guard — chứng minh phủ định
     * ở trên không phải vì đã xoá nhầm luôn cả bề mặt hợp lệ.
     */
    public function test_canonical_stock_write_surface_is_intact_and_guarded(): void
    {
        $guards = [];
        foreach (Route::getRoutes() as $route) {
            $guards[$route->uri()] = array_map('strval', $route->gatherMiddleware());
        }

        $expected = [
            'api/v1/inbound-documents/{id}/post-receipt' => 'permission:inbound.post',
            'api/v1/outbound-documents/{id}/post-issue' => 'permission:outbound.post',
            'api/v1/inbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
            'api/v1/outbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
        ];

        foreach ($expected as $uri => $permission) {
            $this->assertArrayHasKey($uri, $guards, "Thiếu đường ghi tồn canonical {$uri}.");
            $this->assertContains($permission, $guards[$uri], "{$uri} phải còn guard {$permission}.");
        }
    }

    /**
     * DEV231-P0-02. Inbound: SRS §5.1 "Scan không có stock effect".
     */
    public function test_inbound_scan_does_not_touch_movement_or_balance(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('WWHN231-IN-01');

        // Contract GĐ1 scan-driven: header-only + component-lines + Box.
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'WWHN-231 scan stock-neutral fixture',
        ]);
        $documentId = $document['document_id'];
        $lineId = (string) $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/component-lines",
            ['sku_id' => $sku->sku_id],
            $headers
        )->assertOk()->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $before = $this->stockSnapshot();

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'line_id' => $lineId,
            'quantity' => 7,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => (string) $document['version']])->assertOk();

        $this->assertSame($before, $this->stockSnapshot(), 'Scan inbound không được đổi movement/balance (SRS §5.1).');

        // Đối chứng: chính document đó Post mới đổi tồn — nếu không có vế này thì snapshot
        // bằng nhau có thể chỉ vì fixture chưa bao giờ chạm được tới tầng tồn.
        $this->postInboundReceipt($headers, $documentId, (int) $scan->json('data.version'))
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        $after = $this->stockSnapshot();
        $this->assertNotSame($before, $after, 'Post Receipt phải là action đổi tồn.');
        $this->assertSame(
            $before['movement_count'] + 1,
            $after['movement_count'],
            'Post Receipt sinh đúng một movement.'
        );
    }

    /**
     * DEV231-P0-02. Outbound: SRS §5.2 — scan/match và cả chuẩn bị/in nhãn đều stock-neutral;
     * chỉ Hoàn tất xuất/Post Issue mới trừ tồn.
     */
    public function test_outbound_scan_and_label_preparation_are_stock_neutral(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN231-OUT-01');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);

        $before = $this->stockSnapshot();

        $this->postJson("/api/v1/outbound-documents/{$created['document_id']}/scan", [
            'line_id' => $created['line_ids'][0],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();
        $this->markLabelsPrinted($created['document_id']);

        $this->assertSame(
            $before,
            $this->stockSnapshot(),
            'Scan/match và chuẩn bị-in nhãn outbound đều không được đổi tồn (SRS §5.2).'
        );
    }

    /**
     * DEV231-P1-04. Đối soát chỉ đọc: gọi report và chạy lệnh cron ở chế độ dry-run không
     * được để lại bất kỳ tác động tồn kho nào (§7.5 "never auto-update ledger").
     */
    public function test_reconciliation_report_and_command_are_read_only(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN231-REC-01');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 4);

        $before = $this->stockSnapshot();

        $response = $this->getJson('/api/v1/reports/inventory-reconciliation', $headers);
        $response->assertOk();
        $this->assertSame(
            ['ledger_to_balance', 'serial_subledger', 'quantity_subledger', 'container_integrity'],
            $response->json('data.checks'),
            '§7.5 đòi đủ 4 check.'
        );

        $this->artisan('inventory:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->stockSnapshot(), 'Đối soát không được sửa ledger/balance.');
    }

    /**
     * DEV231-P1-04. Vế dương của đối soát: khi có lệch thật, hệ thống phải (a) phát hiện
     * đúng dimension, (b) ghi incident để có disposition, và (c) **vẫn không sửa tồn**.
     * §7.5: "Create Reconciliation_Incident and block readiness/sign-off; resolve with
     * audited reversal under WWHN-26, never by direct ledger or Balance edit."
     */
    public function test_detected_divergence_creates_incident_without_touching_stock(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN231-DIV-01');

        // Cấy lệch: projection có 9 nhưng ledger không có movement nào tương ứng.
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 9);

        $before = $this->stockSnapshot();
        $incidentsBefore = $this->incidentCount();

        $divergences = app(LedgerReconciliationService::class)->divergences();
        $this->assertNotSame([], $divergences, 'Đối soát phải phát hiện lệch đã cấy.');
        $target = collect($divergences)->firstWhere('product_id', (string) $sku->sku_id);
        $this->assertNotNull($target, 'Lệch phải được quy đúng về SKU đã cấy.');
        $this->assertSame('LEDGER_MISSING_FOR_BALANCE', $target['divergence_type']);
        $this->assertSame(9.0, $target['on_hand_qty']);
        $this->assertSame(0.0, $target['ledger_qty']);

        $this->artisan('inventory:reconcile')->assertSuccessful();

        $this->assertGreaterThan($incidentsBefore, $this->incidentCount(), 'Lệch phải được ghi thành reconciliation incident.');
        $this->assertSame($before, $this->stockSnapshot(), 'Ghi incident không được kèm theo bất kỳ sửa tồn nào.');

        $report = $this->getJson('/api/v1/reports/inventory-reconciliation?only_mismatch=1', $headers);
        $report->assertOk()->assertJsonPath('data.summary.overall_status', 'FAIL');
        $this->assertContains(
            (string) $sku->sku_id,
            collect($report->json('data.rows'))->pluck('product_id')->all(),
            'Báo cáo phải liệt kê đúng dimension đang lệch để người xử lý có disposition.'
        );
    }

    private function incidentCount(): int
    {
        $table = DB::getDriverName() === 'pgsql' ? 'reconciliation_incidents' : 'reconciliation_incident';

        return (int) DB::table($table)->count();
    }

    /**
     * DEV231-P1-03. Hình dạng query mới (aggregate-then-join) phải trả đúng cùng kết quả
     * với hình dạng cũ (join-trước-gom). Không có vế này thì "tối ưu" chỉ là đổi kế hoạch
     * thực thi mà không ai chứng minh được nó còn đúng nghiệp vụ.
     */
    public function test_optimized_ledger_aggregate_matches_legacy_group_by_shape(): void
    {
        $headers = $this->superAdminHeaders();
        foreach (['WWHN231-PAR-01', 'WWHN231-PAR-02'] as $index => $code) {
            $this->postedReceiptFixture($headers, $code, 3 + $index);
        }

        $legacy = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'p.category_id', 'pc.category_name')
            ->selectRaw('SUM(m.qty_delta) AS ledger_qty')
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code', 'p.code', 'p.name', 'p.category_id', 'pc.category_name')
            ->get()
            ->map(fn ($row): array => [
                'key' => LedgerReconciliationService::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status),
                'ledger_qty' => (float) $row->ledger_qty,
                'warehouse_code' => (string) ($row->warehouse_code ?? ''),
                'product_code' => (string) ($row->product_code ?? ''),
            ])
            ->sortBy('key')
            ->values()
            ->all();

        $optimized = app(LedgerReconciliationService::class)
            ->ledgerAggregateWithMasterQuery()
            ->get()
            ->map(fn ($row): array => [
                'key' => LedgerReconciliationService::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status),
                'ledger_qty' => (float) $row->ledger_qty,
                'warehouse_code' => (string) ($row->warehouse_code ?? ''),
                'product_code' => (string) ($row->product_code ?? ''),
            ])
            ->sortBy('key')
            ->values()
            ->all();

        $this->assertNotSame([], $optimized, 'Fixture phải sinh ít nhất một dimension ledger.');
        $this->assertSame($legacy, $optimized, 'Aggregate tối ưu phải trùng khít bản join-trước-gom.');
    }

    /**
     * DEV231-P1-03. Số query của báo cáo tồn không được tăng khi đổi hình dạng: aggregate
     * ledger vẫn đúng MỘT câu, không phải N+1 theo dimension.
     */
    public function test_inventory_balance_report_issues_a_single_ledger_aggregate_query(): void
    {
        $headers = $this->superAdminHeaders();
        $this->postedReceiptFixture($headers, 'WWHN231-QC-01', 2);
        $this->postedReceiptFixture($headers, 'WWHN231-QC-02', 5);

        $ledgerQueries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$ledgerQueries): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'sum(qty_delta)')) {
                $ledgerQueries[] = $event->sql;
            }
        });

        $this->getJson('/api/v1/reports/inventory-balance', $headers)->assertOk();

        $this->assertCount(1, $ledgerQueries, 'Báo cáo tồn chỉ được gom ledger đúng một lần: '.count($ledgerQueries).' câu.');

        $sql = (string) $ledgerQueries[0];
        $groupPos = stripos($sql, 'group by');
        $beforeGroup = $groupPos === false ? $sql : substr($sql, 0, $groupPos);
        $this->assertStringNotContainsStringIgnoringCase(
            'join',
            $beforeGroup,
            'Aggregate phải chạy trên riêng stock_movements, master data join sau.'
        );
    }

    /**
     * DEV231-P1-03. Index phục vụ aggregate phải thực sự tồn tại sau migrate — nếu không,
     * benchmark trong evidence không tái lập được trên môi trường khác.
     */
    public function test_ledger_dimension_index_exists(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped("Driver {$driver} nằm ngoài baseline.");
        }

        $exists = $driver === 'pgsql'
            ? DB::table('pg_indexes')->where('indexname', 'idx_sm_ledger_dimension')->exists()
            : DB::table('sqlite_master')->where('type', 'index')->where('name', 'idx_sm_ledger_dimension')->exists();

        $this->assertTrue($exists, 'Thiếu idx_sm_ledger_dimension — aggregate đối soát mất index phục vụ.');
    }

    /**
     * Ảnh chụp toàn bộ tác động tồn kho: số movement, tổng delta, và projection theo dimension.
     *
     * @return array<string, mixed>
     */
    private function stockSnapshot(): array
    {
        return [
            'movement_count' => (int) DB::table('stock_movements')->count(),
            'movement_delta_sum' => (float) (DB::table('stock_movements')->sum('qty_delta') ?? 0),
            'balances' => DB::table('inventory_balances')
                ->select('warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'available_qty', 'reserved_qty')
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->orderBy('stock_status')
                ->get()
                ->map(fn ($row): array => [
                    'key' => LedgerReconciliationService::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status),
                    'on_hand_qty' => (float) $row->on_hand_qty,
                    'available_qty' => (float) $row->available_qty,
                    'reserved_qty' => (float) $row->reserved_qty,
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function postedReceiptFixture(array $headers, string $skuCode, int $qty): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture($skuCode);

        // Contract GĐ1 scan-driven: header-only + component-lines + Box + đếm thực tế.
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'WWHN-231 ledger fixture',
        ]);
        $documentId = $document['document_id'];

        $received = $this->receiveComponentQuantity(
            $headers,
            $documentId,
            (string) $sku->sku_id,
            $qty,
            $document['version']
        );

        $this->postInboundReceipt($headers, $documentId, $received['version'])->assertOk();

        return ['document_id' => $documentId, 'sku_id' => (string) $sku->sku_id, 'warehouse_id' => (string) $warehouse->id];
    }
}
