<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\StockDocumentService;
use Throwable;

/**
 * Dữ liệu vận hành trải ĐỀU 4 tháng 06→09/2026 và phủ ĐỦ mọi trạng thái của các
 * màn danh sách + 8 màn báo cáo — yêu cầu người dùng 04/09/2026.
 *
 * Vì sao cần thêm seeder này (đối chiếu trạng thái sau `db:seed` cũ):
 *  - Chứng từ chỉ trải 07→08/2026: tháng 6 và tháng 9 trống trơn, mọi bộ lọc kỳ
 *    (Tuần/Tháng/Quý) ở báo cáo Lịch sử nhập–xuất và Nhập–Xuất–Tồn đều lệch.
 *  - Phiếu xuất chỉ có DRAFT/POSTED; phiếu xuất linh kiện chỉ có POSTED; phiếu
 *    điều chỉnh không có CANCELLED và không phiếu nào có trạng thái hàng vật lý.
 *  - Yêu cầu xuất chỉ có CONSUMED; sự cố đối soát chỉ có RESOLVED/WAIVED;
 *    container thiếu 4/7 trạng thái và không có CARTON nào.
 *  - Nhật ký in nhãn không có REPRINT, không có PENDING; báo cáo Import & Match
 *    không có batch nào mang dòng lỗi/trùng nên 2 bộ lọc kết quả trả rỗng.
 *
 * NGUYÊN TẮC (giống HoaNamBulkDemoSeeder / OperationalReportRangeSeeder):
 *  - Mọi hiệu ứng tồn kho đi qua service nghiệp vụ thật (CurrentBaselineStock-
 *    Service, CurrentBaselineReversalService, ComponentContainerSubledgerService)
 *    — KHÔNG ghi tay inventory_balances/stock_movements, nên ledger = balance =
 *    sổ phụ và báo cáo đối soát không đẻ chênh lệch giả.
 *  - Backdate bằng Carbon::setTestNow() bọc quanh từng thao tác; không UPDATE
 *    ngược ngày sau khi POSTED (trigger immutability chặn).
 *  - Idempotent theo khoá tự nhiên (doc_no / request_no / incident_no / ...).
 *  - Chứng từ được tạo bởi NHIỀU tài khoản thuộc nhiều phòng ban khác nhau, vì
 *    báo cáo "Xuất theo nơi nhận" suy phòng ban xử lý từ người tạo phiếu.
 *
 * Chạy sau MasterCatalogCoverageSeeder + SkuCoverageSeeder (cần danh mục/SKU
 * đầy đủ) và sau HoaNamBulkDemoSeeder (dùng chung kho + pool item). Chỉ pgsql.
 */
class WarehouseOperationsCoverageSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const START = '2026-06-01';

    private const END = '2026-09-30';

    /** Guard "đã seed đủ" — phiếu cuối cùng của lượt chạy đầy đủ. */
    private const GUARD_DOC_NO = 'PN-COV-Q0040';

    /**
     * Độ dài cửa sổ "vừa xảy ra" — xem seedRecentWindowCoverage(). Giữ >= 14 để
     * phủ luôn preset "Tuần này" của báo cáo dù `db:seed` chạy vào Chủ nhật.
     */
    private const RECENT_WINDOW_DAYS = 14;

    /** Số SKU sản phẩm lấy mỗi nhóm hàng để nhập kho (trải đều mọi nhóm hàng). */
    private const PRODUCT_SKUS_PER_CATEGORY = 5;

    private const COMPONENT_SKUS_PER_CATEGORY = 4;

    private CurrentBaselineStockService $stock;

    private CurrentBaselineReversalService $reversal;

    private string $warehouseId;

    /** @var array<string,string> username → user_id */
    private array $actors = [];

    private string $defaultActor;

    /** @var list<array{id:string,name:string,address:string,type:string}> */
    private array $recipients = [];

    public function run(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first()
            ?? Warehouse::query()->orderBy('created_at')->first();
        if ($warehouse === null) {
            $this->command?->warn('WarehouseOperationsCoverageSeeder: chưa có kho — chạy WarehouseDatabaseSeeder trước.');

            return;
        }

        $this->warehouseId = (string) $warehouse->id;
        $this->stock = app(CurrentBaselineStockService::class);
        $this->reversal = app(CurrentBaselineReversalService::class);
        $this->loadActors();
        $this->loadRecipients();

        if ($this->actors === []) {
            $this->command?->warn('WarehouseOperationsCoverageSeeder: chưa có người dùng — chạy AuthDatabaseSeeder trước.');

            return;
        }

        [$productSkus, $componentSerialSkus, $componentQtySkus] = $this->buildSkuPools();
        if ($productSkus === []) {
            $this->command?->warn('WarehouseOperationsCoverageSeeder: chưa có SKU nào để nhập kho.');

            return;
        }

        $this->command?->info(
            'WarehouseOperationsCoverageSeeder: pool SKU — '.count($productSkus).' sản phẩm serial, '
            .count($componentSerialSkus).' linh kiện serial, '.count($componentQtySkus).' linh kiện theo số lượng.'
        );

        $productItems = $this->seedSerialReceipts($productSkus, 'PN-COV-P', 480, 8);
        $componentItems = $this->seedSerialReceipts($componentSerialSkus, 'PN-COV-C', 240, 8);
        $this->seedQuantityReceipts($componentQtySkus, 'PN-COV-Q', 40);
        $this->command?->info(
            '  đã nhập kho '.count($productItems).' sản phẩm + '.count($componentItems).' linh kiện serial (06→09/2026).'
        );

        $issued = $this->seedIssues($productItems, 6);
        $this->command?->info('  đã xuất bán '.count($issued).' hiện vật qua phiếu xuất có nơi nhận đủ 4 loại đơn nhận.');

        $this->seedComponentIssues($componentItems, 5);
        $this->seedQuantityComponentIssues($componentQtySkus, 30);
        $this->seedDocumentStateVariants($productSkus, $componentSerialSkus);
        $this->seedReversalLifecycle($productSkus);
        $this->seedOutboundRequestStates($productSkus);
        $this->seedContainers($productSkus, $componentSerialSkus);
        $this->seedStockStatusSpread();
        $this->seedItemLifecycleEdges();
        $this->seedNewCandidates($productSkus);
        $this->seedReconciliationIncidents();
        $this->seedImportReports();
        $this->seedOutboundRequestImports();
        $this->seedPrinterCoverage();
        $this->seedLabelPrintCoverage();
        $this->seedPutawayCoverage();
        $this->seedNotificationCoverage();
        // Phải đứng TRƯỚC backfillDisplayGaps() để chứng từ vừa sinh trong cửa sổ
        // 14 ngày gần nhất cũng được lấp nốt các ô hiển thị còn trống.
        $this->seedRecentWindowCoverage($productSkus, $componentQtySkus);
        $this->backfillDisplayGaps();

        $this->command?->info('WarehouseOperationsCoverageSeeder: HOÀN TẤT — dữ liệu vận hành 06→09/2026 đủ mọi trạng thái.');
    }

    // ==================================================================
    //  POOL & THAM CHIẾU
    // ==================================================================

    private function loadActors(): void
    {
        // Mỗi phòng ban vài người: báo cáo "Xuất theo nơi nhận" suy phòng ban xử
        // lý từ người tạo/ghi sổ phiếu, nên phiếu phải rải trên nhiều tài khoản.
        $wanted = [
            'keeper01', 'thukho02', 'nhanvienkho02', 'nhanvienkho03', 'manager01', 'quanlykho02',
            'kinhdoanh01', 'kinhdoanh02', 'quanlykho03', 'baohanh01', 'baohanh02', 'danhmuc01', 'admin',
        ];
        foreach ($wanted as $username) {
            $id = DB::table('app_user')->where('username', $username)->value('user_id');
            if ($id !== null) {
                $this->actors[$username] = (string) $id;
            }
        }
        $this->defaultActor = $this->actors['keeper01']
            ?? $this->actors['admin']
            ?? (string) DB::table('app_user')->orderBy('created_at')->value('user_id');
    }

    private function loadRecipients(): void
    {
        $this->recipients = DB::table('agencies')
            ->where('status', 'ACTIVE')
            ->orderBy('recipient_type')
            ->orderBy('agency_code')
            ->get(['agency_id', 'agency_name', 'address', 'recipient_type'])
            ->map(fn ($row) => [
                'id' => (string) $row->agency_id,
                'name' => (string) $row->agency_name,
                'address' => (string) ($row->address ?? 'Việt Nam'),
                'type' => (string) $row->recipient_type,
            ])
            ->all();
    }

    /**
     * Pool SKU trải ĐỀU mọi nhóm hàng: lấy tối đa N SKU mỗi nhóm hàng thay vì
     * lấy top-N toàn bảng (top-N sẽ dồn hết vào 1-2 nhóm và báo cáo lọc theo
     * nhóm hàng lại trống).
     *
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private function buildSkuPools(): array
    {
        return [
            $this->skusPerCategory('PRODUCT', true, self::PRODUCT_SKUS_PER_CATEGORY),
            $this->skusPerCategory('COMPONENT', true, self::COMPONENT_SKUS_PER_CATEGORY),
            $this->skusPerCategory('COMPONENT', false, 2),
        ];
    }

    /** @return list<string> */
    private function skusPerCategory(string $skuType, bool $serialTracked, int $perCategory): array
    {
        $rows = DB::table(DB::raw('(
            SELECT s.id,
                   ROW_NUMBER() OVER (PARTITION BY s.category_id ORDER BY s.sku_code) AS rn
            FROM skus s
            WHERE s.status = \'ACTIVE\'
              AND s.sku_type = ?
              AND s.tracking_mode = ?
        ) ranked'))
            ->setBindings([$skuType, $serialTracked ? 'SERIAL' : 'QUANTITY'])
            ->where('rn', '<=', $perCategory)
            ->pluck('id');

        return $rows->map(fn ($id) => (string) $id)->values()->all();
    }

    // ==================================================================
    //  NHẬP KHO
    // ==================================================================

    /**
     * Phiếu nhập serial trải ĐỀU 4 tháng: ngày phiếu chạy vòng qua toàn bộ ngày
     * trong kỳ nên mỗi tháng nhận xấp xỉ cùng một lượng phiếu.
     *
     * @param  list<string>  $skus
     * @return list<string> item ids IN_STOCK
     */
    private function seedSerialReceipts(array $skus, string $prefix, int $targetItems, int $batch): array
    {
        if ($skus === []) {
            return [];
        }

        $days = $this->calendar();
        $items = [];
        $created = 0;
        $docIndex = 0;
        // Số phiếu thường ÍT hơn số ngày trong kỳ, nên KHÔNG dùng modulo (sẽ
        // lấp kín tháng 6 rồi hết phiếu, tháng 9 trống): giãn đều chỉ số phiếu
        // lên toàn bộ 122 ngày để mỗi tháng nhận xấp xỉ cùng lượng chứng từ.
        $totalDocs = (int) ceil($targetItems / $batch);

        while ($created < $targetItems) {
            $take = min($batch, $targetItems - $created);
            $day = $days[$this->spreadIndex($docIndex, $totalDocs, count($days))];
            $skuId = $skus[$docIndex % count($skus)];
            $docNo = $prefix.sprintf('%05d', $docIndex + 1);
            $docIndex++;

            foreach ($this->receiptBatch($docNo, $skuId, $take, $day, $this->actorFor('inbound', $docIndex)) as $id) {
                $items[] = $id;
            }
            $created += $take;

            if ($docIndex % 25 === 0) {
                $this->command?->info("  {$prefix}: {$created}/{$targetItems} hiện vật ({$docIndex} phiếu)...");
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function receiptBatch(string $docNo, string $skuId, int $qty, Carbon $day, string $actorId): array
    {
        $serials = [];
        for ($s = 1; $s <= $qty; $s++) {
            $serials[] = 'SN-'.$docNo.'-'.sprintf('%03d', $s);
        }

        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return $this->itemIdsBySerial($serials);
        }

        Carbon::setTestNow($day);
        try {
            $lines = array_map(fn () => ['product_id' => $skuId, 'qty_planned' => 1], $serials);
            [$docId, $lineIds] = $this->createDocument('receipt', $docNo, $lines, $actorId, [
                'source_name' => 'Hoá đơn NCC '.strtoupper(substr(md5($docNo), 0, 6)).' — lô '.$docNo,
                'note' => 'Nhập kho theo hợp đồng cung ứng quý '.$this->quarterOf($day).'/2026.',
            ]);

            $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
            foreach ($serials as $i => $serial) {
                $this->stock->scanStockDocument($docId, 'receipt', [
                    'line_id' => $lineIds[$i],
                    'quantity' => 1,
                    'code_value' => "HN1|SKU={$skuCode}|ITEM={$serial}",
                ], $actorId);
            }
            $this->postDocument($docId, 'receipt', $actorId);
        } catch (Throwable $e) {
            $this->command?->warn('  bỏ qua phiếu nhập '.$docNo.': '.$this->describe($e));
        } finally {
            Carbon::setTestNow();
        }

        return $this->itemIdsBySerial($serials);
    }

    /**
     * Phiếu nhập theo SỐ LƯỢNG (linh kiện tracking_mode=QUANTITY) — nhánh tồn
     * không-serial trước đây gần như không có dữ liệu (7/514 SKU).
     *
     * @param  list<string>  $skus
     */
    private function seedQuantityReceipts(array $skus, string $prefix, int $docCount): void
    {
        if ($skus === []) {
            return;
        }

        $days = $this->calendar();
        for ($i = 0; $i < $docCount; $i++) {
            $docNo = $prefix.sprintf('%05d', $i + 1);
            if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
                continue;
            }

            $day = $days[$this->spreadIndex($i, $docCount, count($days))];
            $actorId = $this->actorFor('inbound', $i);
            // 2 SKU/phiếu để phiếu nhập có nhiều dòng như chứng từ thật.
            $picked = [$skus[$i % count($skus)], $skus[($i + 3) % count($skus)]];
            $picked = array_values(array_unique($picked));

            Carbon::setTestNow($day);
            try {
                $lines = [];
                foreach ($picked as $n => $skuId) {
                    // 10..24 mỗi dòng: đủ để nhánh tồn theo số lượng có dữ liệu
                    // mà không làm tổng lượng nhập át hẳn tổng lượng xuất trên
                    // biểu đồ "Nhập / Xuất theo ngày".
                    $lines[] = ['product_id' => $skuId, 'qty_planned' => 10 + (($i + $n) % 15)];
                }
                [$docId, $lineIds] = $this->createDocument('receipt', $docNo, $lines, $actorId, [
                    'source_name' => 'Phiếu giao hàng NCC linh kiện '.sprintf('%04d', $i + 1),
                    'note' => 'Nhập linh kiện rời theo số lượng (không quản lý serial).',
                ]);
                foreach ($lines as $n => $line) {
                    $this->stock->scanStockDocument($docId, 'receipt', [
                        'line_id' => $lineIds[$n],
                        'quantity' => (int) $line['qty_planned'],
                    ], $actorId);
                }
                $this->postDocument($docId, 'receipt', $actorId);
            } catch (Throwable $e) {
                $this->command?->warn('  bỏ qua phiếu nhập số lượng '.$docNo.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  XUẤT KHO
    // ==================================================================

    /**
     * @param  list<string>  $items
     * @return list<string> item ids đã ISSUED
     */
    private function seedIssues(array $items, int $batch): array
    {
        $bySku = [];
        foreach ($items as $itemId) {
            $skuId = (string) DB::table('item_instances')->where('item_id', $itemId)->value('sku_id');
            $bySku[$skuId][] = $itemId;
        }

        $issued = [];
        $docSeq = 0;
        foreach ($bySku as $skuId => $skuItems) {
            // Giữ ~40% tồn kho lại để màn tồn kho và báo cáo tồn có dữ liệu.
            $sellable = array_slice($skuItems, 0, (int) ceil(count($skuItems) * 0.6));
            foreach (array_chunk($sellable, $batch) as $chunk) {
                $docSeq++;
                $docNo = 'PX-COV-'.sprintf('%05d', $docSeq);
                if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
                    continue;
                }

                $eligible = array_values(array_filter(
                    $chunk,
                    fn ($id) => DB::table('item_instances')->where('item_id', $id)->value('item_status') === 'IN_STOCK'
                ));
                if ($eligible === []) {
                    continue;
                }

                $recipient = $this->recipients[$docSeq % max(1, count($this->recipients))] ?? null;
                if ($recipient === null) {
                    continue;
                }
                $actorId = $this->actorFor('outbound', $docSeq);
                $day = $this->followUpDay(
                    DB::table('item_instances')->where('item_id', $eligible[0])->value('received_at'),
                    $docSeq,
                    3
                );

                Carbon::setTestNow($day);
                try {
                    $requestId = $this->createOutboundRequest(
                        'TOA-COV-'.sprintf('%05d', $docSeq),
                        [[$skuId, count($eligible)]],
                        $recipient,
                        $actorId,
                        'CONSUMED'
                    );
                    $lines = array_map(fn () => ['product_id' => $skuId, 'qty_planned' => 1], $eligible);
                    [$docId, $lineIds] = $this->createDocument('issue', $docNo, $lines, $actorId, [
                        'outbound_request_id' => $requestId,
                        'agency_id' => $recipient['id'],
                        'recipient_name' => $recipient['name'],
                        'recipient_address' => $recipient['address'],
                        'note' => 'Giao hàng theo toa '.$docNo.' — '.$recipient['name'].'.',
                    ]);
                    foreach ($eligible as $i => $itemId) {
                        $this->stock->scanStockDocument($docId, 'issue', [
                            'line_id' => $lineIds[$i],
                            'quantity' => 1,
                            'item_id' => $itemId,
                        ], $actorId);
                    }
                    $this->markLabelPrinted($docId, $skuId, $actorId, $docSeq);
                    $this->postDocument($docId, 'issue', $actorId);
                    foreach ($eligible as $itemId) {
                        $issued[] = $itemId;
                    }
                } catch (Throwable $e) {
                    $this->command?->warn('  bỏ qua phiếu xuất '.$docNo.': '.$this->describe($e));
                } finally {
                    Carbon::setTestNow();
                }
            }
        }

        return $issued;
    }

    /**
     * Xuất linh kiện bảo hành — gắn vào case bảo hành đang xử lý.
     *
     * @param  list<string>  $componentItems
     */
    private function seedComponentIssues(array $componentItems, int $batch): void
    {
        $caseIds = DB::table('warranty_case')
            ->whereIn('status', ['CHECKING', 'REPAIRING'])
            ->orderBy('warranty_case_code')
            ->pluck('warranty_case_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($caseIds === [] || $componentItems === []) {
            return;
        }

        // Chỉ dùng ~55% linh kiện, phần còn lại giữ tồn cho màn tồn kho.
        $usable = array_slice($componentItems, 0, (int) round(count($componentItems) * 0.55));
        foreach (array_chunk($usable, $batch) as $index => $chunk) {
            $issueNo = 'CID-COV-'.sprintf('%05d', $index + 1);
            if (DB::table('component_issue_documents')->where('component_issue_no', $issueNo)->exists()) {
                continue;
            }

            $caseId = $caseIds[$index % count($caseIds)];
            $actorId = $this->actorFor('warranty', $index);
            $day = $this->followUpDay(
                DB::table('item_instances')->where('item_id', $chunk[0])->value('received_at'),
                $index,
                5
            );

            Carbon::setTestNow($day);
            try {
                $documentId = $this->createComponentIssueDocument($issueNo, $caseId, $actorId, 'DRAFT');
                $lineNo = 0;
                foreach ($chunk as $itemId) {
                    $row = DB::table('item_instances')->where('item_id', $itemId)->first();
                    if ($row === null || $row->item_status !== 'IN_STOCK') {
                        continue;
                    }
                    $lineNo++;
                    $this->addComponentIssueLine($documentId, $lineNo, (string) $row->sku_id, $itemId, $actorId);
                }

                if ($lineNo === 0) {
                    DB::table('component_issue_documents')->where('id', $documentId)->delete();

                    continue;
                }

                app(ComponentContainerSubledgerService::class)->consumeDocument($documentId);
                $key = 'seed-cov-component-issue-'.$documentId;
                $this->stock->postComponentIssue($documentId, $actorId, $key, sha1($key));
            } catch (Throwable $e) {
                $this->command?->warn('  bỏ qua phiếu xuất linh kiện '.$issueNo.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  XUẤT LINH KIỆN THEO SỐ LƯỢNG (SKU quantity-mode)
    // ==================================================================

    /**
     * Linh kiện quản lý theo SỐ LƯỢNG chỉ có đường NHẬP nếu không có bước này —
     * tồn của chúng chỉ tăng, và biểu đồ "Nhập / Xuất theo ngày" ở Dashboard lệch
     * hẳn về phía nhập. Component Issue chấp nhận dòng theo số lượng (không cần
     * item) miễn scanned_qty = required_qty, nên đây là đường xuất ĐÚNG NGHIỆP VỤ
     * cho nhóm SKU này.
     *
     * @param  list<string>  $componentQtySkus
     */
    private function seedQuantityComponentIssues(array $componentQtySkus, int $docCount): void
    {
        if ($componentQtySkus === []) {
            return;
        }

        $caseIds = DB::table('warranty_case')
            ->whereIn('status', ['CHECKING', 'REPAIRING'])
            ->orderBy('warranty_case_code')
            ->pluck('warranty_case_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($caseIds === []) {
            return;
        }

        $days = $this->calendar();
        for ($i = 0; $i < $docCount; $i++) {
            $issueNo = 'CIQ-COV-'.sprintf('%05d', $i + 1);
            if (DB::table('component_issue_documents')->where('component_issue_no', $issueNo)->exists()) {
                continue;
            }

            $caseId = $caseIds[$i % count($caseIds)];
            $actorId = $this->actorFor('warranty', $i);
            $day = $days[$this->spreadIndex($i, $docCount, count($days))]->copy()->setTime(15, 30);
            $skuId = $componentQtySkus[$i % count($componentQtySkus)];

            // Chỉ xuất trong phạm vi tồn khả dụng để không đẩy tồn xuống âm.
            $available = (int) DB::table('inventory_balances')
                ->where('warehouse_id', $this->warehouseId)
                ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->where('stock_status', 'AVAILABLE')
                ->sum('available_qty');
            $qty = min(2 + ($i % 6), max(0, $available - 2));
            if ($qty <= 0) {
                continue;
            }

            Carbon::setTestNow($day);
            try {
                $documentId = $this->createComponentIssueDocument($issueNo, $caseId, $actorId, 'DRAFT');
                $lineId = (string) Str::uuid();
                DB::table('component_issue_lines')->insert([
                    'id' => $lineId,
                    'component_issue_document_id' => $documentId,
                    'line_no' => 1,
                    'sku_id' => $skuId,
                    'required_qty' => $qty,
                    'scanned_qty' => $qty,
                    'created_at' => now(), 'created_by' => $actorId,
                    'updated_at' => now(), 'updated_by' => $actorId,
                    'version' => 0,
                ]);

                $key = 'seed-cov-cq-'.$documentId;
                $this->stock->postComponentIssue($documentId, $actorId, $key, sha1($key));
            } catch (Throwable $e) {
                $this->command?->warn('  bỏ qua xuất linh kiện theo số lượng '.$issueNo.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  THÔNG BÁO — chuông thông báo & màn "Xem tất cả"
    // ==================================================================

    /**
     * NotificationDatabaseSeeder chạy TRƯỚC seeder này nên chỉ thấy 3 chứng từ
     * baseline → chuông chỉ có 3 thông báo. Bổ sung thông báo tham chiếu ĐÚNG
     * chứng từ/hồ sơ do seeder này tạo, trải đủ loại sự kiện + 3 mức severity +
     * cả đã đọc/chưa đọc. Đi qua NotificationService thật (không INSERT tay) để
     * notification_recipients luôn khớp.
     */
    private function seedNotificationCoverage(): void
    {
        $service = app(NotificationService::class);
        $recipients = array_values(array_filter([
            $this->actors['admin'] ?? null,
            $this->actors['manager01'] ?? null,
            $this->actors['keeper01'] ?? null,
            $this->actors['baohanh01'] ?? null,
        ]));
        if ($recipients === []) {
            return;
        }

        $created = 0;

        $emit = function (
            string $eventKey, string $title, string $body, string $severity,
            string $subjectType, string $subjectId, array $payload, bool $markRead
        ) use ($service, $recipients, &$created): void {
            if (DB::table('notifications')->where('event_key', $eventKey)->where('subject_id', $subjectId)->exists()) {
                return;
            }
            $notification = $service->notify(
                eventKey: $eventKey,
                title: $title,
                body: $body,
                userIds: $recipients,
                severity: $severity,
                sourceModule: 'warehouse',
                subjectType: $subjectType,
                subjectId: $subjectId,
                payload: $payload,
                actorId: $this->defaultActor,
            );
            if ($notification === null) {
                return;
            }
            $created++;
            // Một phần đã đọc để badge "chưa đọc" không bằng tổng số.
            if ($markRead) {
                DB::table('notification_recipients')
                    ->where('notification_id', $notification->id)
                    ->update(['read_at' => now()]);
            }
        };

        foreach (DB::table('stock_documents')->where('doc_type', 'receipt')->where('status', 'POSTED')
            ->where('doc_no', 'like', 'PN-COV-%')->orderByDesc('doc_date')->limit(6)->get(['id', 'doc_no']) as $i => $doc) {
            $emit('inbound.completed', 'Đã hoàn tất nhập kho',
                "Phiếu nhập {$doc->doc_no} đã ghi sổ, tồn kho đã được cộng.",
                'info', 'stock_document', (string) $doc->id,
                ['document_id' => (string) $doc->id, 'doc_no' => $doc->doc_no], $i % 2 === 0);
        }

        foreach (DB::table('stock_documents')->where('doc_type', 'issue')->where('status', 'POSTED')
            ->where('doc_no', 'like', 'PX-COV-%')->orderByDesc('doc_date')->limit(6)->get(['id', 'doc_no', 'recipient_name']) as $i => $doc) {
            $emit('outbound.posted', 'Đã hoàn tất xuất kho',
                "Phiếu xuất {$doc->doc_no} giao cho {$doc->recipient_name} đã ghi sổ.",
                'info', 'stock_document', (string) $doc->id,
                ['document_id' => (string) $doc->id, 'doc_no' => $doc->doc_no], $i % 3 === 0);
        }

        foreach (DB::table('stock_documents')->where('status', 'CANCELLED')
            ->where('doc_no', 'like', '%-COV-%')->orderByDesc('doc_date')->limit(4)->get(['id', 'doc_no', 'doc_type', 'cancel_reason']) as $i => $doc) {
            $emit($doc->doc_type === 'receipt' ? 'inbound.cancelled' : 'outbound.cancelled',
                'Phiếu đã bị huỷ',
                "Phiếu {$doc->doc_no} bị huỷ. Lý do: ".($doc->cancel_reason ?: 'không ghi nhận.'),
                'warning', 'stock_document', (string) $doc->id,
                ['document_id' => (string) $doc->id, 'doc_no' => $doc->doc_no], $i % 2 === 1);
        }

        foreach (DB::table('reversal_documents')->whereIn('status', ['DRAFT', 'POSTED'])
            ->orderByDesc('created_at')->limit(5)->get(['id', 'reversal_no', 'status', 'reason_text']) as $i => $doc) {
            $emit($doc->status === 'POSTED' ? 'reversal.posted' : 'reversal.created',
                $doc->status === 'POSTED' ? 'Đã ghi sổ điều chỉnh tồn kho' : 'Có đề nghị điều chỉnh tồn kho',
                "Phiếu điều chỉnh {$doc->reversal_no}: ".($doc->reason_text ?: 'điều chỉnh theo biên bản kiểm kê.'),
                $doc->status === 'POSTED' ? 'warning' : 'info', 'reversal_document', (string) $doc->id,
                ['reversal_id' => (string) $doc->id, 'reversal_no' => $doc->reversal_no], $i % 2 === 0);
        }

        foreach (DB::table('warranty_case')->whereIn('status', ['RECEIVED', 'CHECKING', 'REPAIRING'])
            ->orderByDesc('received_at')->limit(6)->get(['warranty_case_id', 'warranty_case_code', 'status', 'customer_name']) as $i => $case) {
            $emit($case->status === 'RECEIVED' ? 'warranty.case_created' : 'warranty.status_changed',
                $case->status === 'RECEIVED' ? 'Tiếp nhận hồ sơ bảo hành mới' : 'Hồ sơ bảo hành đổi trạng thái',
                "Hồ sơ {$case->warranty_case_code} của khách {$case->customer_name} đang ở trạng thái {$case->status}.",
                'info', 'warranty_case', (string) $case->warranty_case_id,
                ['case_id' => (string) $case->warranty_case_id, 'to_status' => $case->status], $i % 3 === 1);
        }

        foreach (DB::table('reconciliation_incidents')->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->orderByDesc('detected_at')->limit(4)->get(['id', 'incident_no', 'severity', 'difference_qty']) as $i => $incident) {
            $emit('stock.shortage_detected', 'Phát hiện chênh lệch tồn kho',
                "Sự cố đối soát {$incident->incident_no}: lệch {$incident->difference_qty} đơn vị so với sổ cái.",
                $incident->severity === 'CRITICAL' ? 'critical' : 'warning',
                'stock_document', (string) $incident->id,
                ['incident_no' => $incident->incident_no, 'difference_qty' => $incident->difference_qty], false);
        }

        $this->command?->info("  đã tạo {$created} thông báo tham chiếu chứng từ/hồ sơ thật cho chuông thông báo.");
    }

    // ==================================================================
    //  BIẾN THỂ TRẠNG THÁI CHỨNG TỪ (DRAFT / SCANNING / CANCELLED)
    // ==================================================================

    /**
     * Mỗi tháng trong kỳ đều có phiếu ở cả 4 trạng thái cho cả 3 loại chứng từ,
     * để bộ lọc trạng thái × bộ lọc kỳ luôn ra dữ liệu.
     *
     * @param  list<string>  $productSkus
     * @param  list<string>  $componentSkus
     */
    private function seedDocumentStateVariants(array $productSkus, array $componentSkus): void
    {
        $months = ['2026-06-12', '2026-07-14', '2026-08-13', '2026-09-11'];
        $caseIds = DB::table('warranty_case')
            ->whereIn('status', ['RECEIVED', 'CHECKING', 'REPAIRING'])
            ->orderBy('warranty_case_code')
            ->pluck('warranty_case_id')->map(fn ($id) => (string) $id)->all();

        foreach ($months as $monthIndex => $date) {
            $day = Carbon::parse($date)->setTime(9, 30);
            $productSku = $productSkus[$monthIndex % count($productSkus)];
            $componentSku = $componentSkus === [] ? $productSku : $componentSkus[$monthIndex % count($componentSkus)];
            $actorId = $this->actorFor('inbound', $monthIndex);
            $suffix = sprintf('%02d', $monthIndex + 6);

            Carbon::setTestNow($day);
            try {
                $this->draftReceipt('PN-COV-DRAFT-'.$suffix, $productSku, $actorId);
                $this->scanningReceipt('PN-COV-SCAN-'.$suffix, $productSku, $actorId);
                $this->cancelledReceipt('PN-COV-CANCEL-'.$suffix, $componentSku, $actorId);
                $this->outboundStateVariants($suffix, $productSku, $monthIndex);
                if ($caseIds !== []) {
                    $this->componentIssueStateVariants($suffix, $componentSku, $caseIds[$monthIndex % count($caseIds)], $monthIndex);
                }
            } catch (Throwable $e) {
                $this->command?->warn('  biến thể trạng thái chứng từ tháng '.$suffix.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    private function draftReceipt(string $docNo, string $skuId, string $actorId): void
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }
        $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 6],
        ], $actorId, [
            'source_name' => 'Đơn đặt hàng NCC '.$docNo,
            'note' => 'Phiếu nháp — chờ hàng về kho để quét nhập.',
        ]);
    }

    private function scanningReceipt(string $docNo, string $skuId, string $actorId): void
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }
        [$docId, $lineIds] = $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 1],
            ['product_id' => $skuId, 'qty_planned' => 1],
            ['product_id' => $skuId, 'qty_planned' => 1],
        ], $actorId, [
            'source_name' => 'Phiếu giao hàng NCC '.$docNo,
            'note' => 'Đang quét dở — còn 2/3 hiện vật chưa quét.',
        ]);
        $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
        $this->stock->scanStockDocument($docId, 'receipt', [
            'line_id' => $lineIds[0],
            'quantity' => 1,
            'code_value' => "HN1|SKU={$skuCode}|ITEM=SN-{$docNo}-001",
        ], $actorId);
    }

    private function cancelledReceipt(string $docNo, string $skuId, string $actorId): void
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }
        [$docId] = $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 4],
        ], $actorId, [
            'source_name' => 'Phiếu giao hàng NCC '.$docNo,
            'note' => 'Phiếu lập nhầm nhà cung cấp.',
        ]);
        $document = StockDocument::query()->find($docId);
        if ($document !== null) {
            app(StockDocumentService::class)->cancel(
                $document,
                $actorId,
                'Huỷ phiếu — nhà cung cấp giao sai chủng loại, trả hàng tại cổng kho.'
            );
        }
    }

    /** Phiếu xuất ở DRAFT / SCANNING / CANCELLED (trước đây chỉ có DRAFT + POSTED). */
    private function outboundStateVariants(string $suffix, string $skuId, int $monthIndex): void
    {
        $recipient = $this->recipients[$monthIndex % max(1, count($this->recipients))] ?? null;
        if ($recipient === null) {
            return;
        }
        $actorId = $this->actorFor('outbound', $monthIndex);

        $variants = [
            ['PX-COV-DRAFT-'.$suffix, 'DRAFT', 'Phiếu xuất nháp — chờ soạn hàng.'],
            ['PX-COV-SCAN-'.$suffix, 'SCANNING', 'Đang soạn và quét hàng lên xe.'],
            ['PX-COV-CANCEL-'.$suffix, 'CANCELLED', 'Khách hoãn nhận hàng, huỷ phiếu xuất.'],
        ];

        foreach ($variants as $variantIndex => [$docNo, $targetStatus, $note]) {
            if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
                continue;
            }

            $requestId = $this->createOutboundRequest(
                'TOA-COV-'.$suffix.'-'.$variantIndex,
                [[$skuId, 2]],
                $recipient,
                $actorId,
                $targetStatus === 'CANCELLED' ? 'CANCELLED' : 'OPEN'
            );

            [$docId, $lineIds] = $this->createDocument('issue', $docNo, [
                ['product_id' => $skuId, 'qty_planned' => 1],
                ['product_id' => $skuId, 'qty_planned' => 1],
            ], $actorId, [
                'outbound_request_id' => $requestId,
                'agency_id' => $recipient['id'],
                'recipient_name' => $recipient['name'],
                'recipient_address' => $recipient['address'],
                'note' => $note,
            ]);

            if ($targetStatus === 'SCANNING') {
                $itemId = $this->pickIssuableItem($skuId);
                if ($itemId !== null) {
                    $this->stock->scanStockDocument($docId, 'issue', [
                        'line_id' => $lineIds[0],
                        'quantity' => 1,
                        'item_id' => $itemId,
                    ], $actorId);
                }
            } elseif ($targetStatus === 'CANCELLED') {
                $document = StockDocument::query()->find($docId);
                if ($document !== null) {
                    app(StockDocumentService::class)->cancel($document, $actorId, $note);
                }
            }
        }
    }

    /** Phiếu xuất linh kiện ở DRAFT / SCANNING / CANCELLED (trước đây chỉ POSTED). */
    private function componentIssueStateVariants(string $suffix, string $componentSku, string $caseId, int $monthIndex): void
    {
        $actorId = $this->actorFor('warranty', $monthIndex);

        foreach ([['CID-COV-DRAFT-'.$suffix, 'DRAFT'], ['CID-COV-SCAN-'.$suffix, 'SCANNING'], ['CID-COV-CANCEL-'.$suffix, 'CANCELLED']] as [$issueNo, $status]) {
            if (DB::table('component_issue_documents')->where('component_issue_no', $issueNo)->exists()) {
                continue;
            }

            $documentId = $this->createComponentIssueDocument($issueNo, $caseId, $actorId, 'DRAFT');
            $lineId = $this->addComponentIssueLine($documentId, 1, $componentSku, null, $actorId);

            if ($status === 'SCANNING') {
                $itemId = $this->pickIssuableItem($componentSku);
                if ($itemId !== null) {
                    DB::table('component_issue_items')->insert([
                        'id' => (string) Str::uuid(),
                        'component_issue_line_id' => $lineId,
                        'item_id' => $itemId,
                        'container_id' => null,
                        'quantity' => 1,
                        'status' => 'ACTIVE',
                        'created_at' => now(), 'created_by' => $actorId,
                        'updated_at' => now(), 'updated_by' => $actorId,
                        'version' => 0,
                    ]);
                }
                DB::table('component_issue_documents')->where('id', $documentId)->update([
                    'status' => 'SCANNING',
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                    'updated_by' => $actorId,
                ]);
            } elseif ($status === 'CANCELLED') {
                DB::table('component_issue_documents')->where('id', $documentId)->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Kỹ thuật xác định không cần thay linh kiện sau khi kiểm tra lại.',
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                    'updated_by' => $actorId,
                ]);
            }
        }
    }

    // ==================================================================
    //  ĐIỀU CHỈNH TỒN KHO (REVERSAL) — DRAFT / POSTED / CANCELLED
    // ==================================================================

    /**
     * Dùng đúng vòng đời nghiệp vụ: execute() -> DRAFT, đính kèm chứng từ ->
     * postReversalDocument() -> POSTED, hoặc cancelReversalDocument() ->
     * CANCELLED; phiếu đảo phiếu xuất còn có trạng thái hàng vật lý
     * PENDING_RETURN / CONFIRMED.
     *
     * @param  list<string>  $productSkus
     */
    private function seedReversalLifecycle(array $productSkus): void
    {
        // Phiếu nhập 1 hiện vật riêng để đảo sạch (đảo phiếu nhập chung sẽ vướng
        // guard DOWNSTREAM_DEPENDENCY khi hiện vật đã xuất).
        $plan = [
            ['RV-IN-06', 'POSTED', '2026-06-18'],
            ['RV-IN-07', 'DRAFT', '2026-07-16'],
            ['RV-IN-08', 'CANCELLED', '2026-08-19'],
            ['RV-IN-09', 'POSTED', '2026-09-15'],
        ];

        foreach ($plan as $planIndex => [$tag, $target, $date]) {
            $docNo = 'PN-COV-'.$tag;
            $skuId = $productSkus[$planIndex % count($productSkus)];
            $serial = 'SN-'.$docNo.'-001';
            $day = Carbon::parse($date)->setTime(10, 15);
            $actorId = $this->actorFor('inbound', $planIndex);

            Carbon::setTestNow($day);
            try {
                if (! DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
                    [$docId, $lineIds] = $this->createDocument('receipt', $docNo, [
                        ['product_id' => $skuId, 'qty_planned' => 1],
                    ], $actorId, [
                        'source_name' => 'Phiếu giao hàng NCC '.$docNo,
                        'note' => 'Lô nhập dùng để minh hoạ điều chỉnh tồn kho.',
                    ]);
                    $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
                    $this->stock->scanStockDocument($docId, 'receipt', [
                        'line_id' => $lineIds[0], 'quantity' => 1,
                        'code_value' => "HN1|SKU={$skuCode}|ITEM={$serial}",
                    ], $actorId);
                    $this->postDocument($docId, 'receipt', $actorId);
                }

                $this->runReversal(
                    (string) DB::table('stock_documents')->where('doc_no', $docNo)->value('id'),
                    'receipt',
                    $target,
                    'Kiểm đếm lại phát hiện thừa 1 hiện vật so với phiếu giao hàng NCC.',
                    $actorId,
                    false
                );
            } catch (Throwable $e) {
                $this->command?->warn('  điều chỉnh tồn (nhập) '.$docNo.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }

        // Đảo phiếu XUẤT: cần cho bộ lọc "Trạng thái hoàn tác" của báo cáo nơi
        // nhận (chưa hoàn / hoàn một phần / hoàn toàn bộ) và cho trạng thái hàng
        // vật lý PENDING_RETURN / CONFIRMED.
        $issueDocs = DB::table('stock_documents')
            ->where('doc_type', 'issue')
            ->where('status', 'POSTED')
            ->where('doc_no', 'like', 'PX-COV-%')
            ->orderBy('doc_no')
            ->limit(12)
            ->get(['id', 'doc_no', 'doc_date']);

        foreach ($issueDocs as $index => $doc) {
            // 0 = hoàn toàn bộ (POSTED), 1 = hoàn một phần (POSTED),
            // 2 = chờ hàng về (DRAFT · PENDING_RETURN), 3 = huỷ điều chỉnh,
            // 4..5 = giữ nguyên (để bộ lọc "Chưa hoàn tác" cũng có dữ liệu).
            $mode = $index % 6;
            if ($mode > 3) {
                continue;
            }
            $day = Carbon::parse($doc->doc_date)->addDays(4 + ($index % 6))->setTime(15, 20);
            if ($day->gt(Carbon::parse(self::END))) {
                $day = Carbon::parse(self::END)->setTime(15, 20);
            }
            $actorId = $this->actorFor('outbound', $index);

            [$target, $reason, $maxLines, $physicalConfirmed] = match ($mode) {
                0 => ['POSTED', 'Khách trả lại toàn bộ lô hàng do giao sai model đặt mua.', null, true],
                1 => ['POSTED', 'Khách trả lại một phần lô hàng, giữ lại số máy còn dùng được.', 1, true],
                2 => ['DRAFT', 'Khách báo trả hàng, đang chờ xe chở máy về kho để nhập lại.', 2, false],
                default => ['CANCELLED', 'Khách đổi ý giữ lại hàng, huỷ đề nghị điều chỉnh tồn kho.', 1, false],
            };

            Carbon::setTestNow($day);
            try {
                $this->runReversal((string) $doc->id, 'issue', $target, $reason, $actorId, $physicalConfirmed, $maxLines);
            } catch (Throwable $e) {
                $this->command?->warn('  điều chỉnh tồn (xuất) '.$doc->doc_no.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    /** ApiError ném HttpResponseException với message rỗng — lấy body JSON để log có nghĩa. */
    private function describe(Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message !== '') {
            return $message;
        }
        if ($e instanceof HttpResponseException) {
            return (string) $e->getResponse()->getContent();
        }

        return get_class($e);
    }

    /**
     * Tạo DRAFT rồi đưa về đúng trạng thái đích.
     *
     * Bám đúng rule item-scope của CurrentBaselineReversalService: SKU PRODUCT
     * hoặc serial-tracked phải khai MỖI MÁY MỘT DÒNG kèm item_id, quantity=1;
     * SKU quantity-mode đảo theo số lượng và KHÔNG được gán item_id.
     *
     * @param  'receipt'|'issue'|'component_issue'  $documentType
     * @param  'DRAFT'|'POSTED'|'CANCELLED'  $target
     * @param  int|null  $maxLines  null = đảo toàn bộ; số = chỉ đảo ngần ấy dòng (hoàn một phần)
     */
    private function runReversal(
        string $documentId,
        string $documentType,
        string $target,
        string $reason,
        string $actorId,
        bool $physicalReturnConfirmed,
        ?int $maxLines = null
    ): void {
        $movementType = match ($documentType) {
            'receipt' => 'RECEIPT',
            'component_issue' => 'COMPONENT_ISSUE',
            default => 'ISSUE',
        };
        // Movement COMPONENT_ISSUE không thuộc stock_document nào (chứng từ linh
        // kiện sống ở bảng riêng) — danh tính gốc nằm ở cặp source_type/source_id,
        // đúng như CurrentBaselineReversalService::createDraft() truy ngược.
        $movements = DB::table('stock_movements')
            ->where('movement_type', $movementType)
            ->when(
                $documentType === 'component_issue',
                fn ($query) => $query
                    ->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')
                    ->where('source_id', $documentId),
                fn ($query) => $query->where('stock_document_id', $documentId),
            )
            ->orderBy('created_at')
            ->get(['id', 'quantity', 'sku_id', 'product_id', 'item_id']);
        if ($movements->isEmpty()) {
            return;
        }

        $alreadyReversed = DB::table('reversal_line as rl')
            ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
            ->whereIn('rl.original_movement_id', $movements->pluck('id'))
            ->whereIn('rd.status', ['DRAFT', 'POSTED'])
            ->exists();
        if ($alreadyReversed) {
            return;
        }

        $lines = [];
        foreach ($movements as $movement) {
            if ($maxLines !== null && count($lines) >= $maxLines) {
                break;
            }
            $skuId = (string) ($movement->sku_id ?? $movement->product_id);
            $sku = DB::table('sku')->where('sku_id', $skuId)->first(['sku_type', 'is_serial_tracked']);
            if ($sku === null) {
                continue;
            }

            $itemScoped = strtoupper((string) $sku->sku_type) === 'PRODUCT' || (bool) $sku->is_serial_tracked;
            if (! $itemScoped) {
                $lines[] = ['movement_id' => (string) $movement->id, 'quantity' => abs((int) $movement->quantity)];

                continue;
            }

            if ($movement->item_id === null) {
                continue;
            }
            // BR-REV-03/04: chỉ đảo được item còn ở đúng trạng thái downstream.
            $itemStatus = (string) DB::table('item_instances')->where('item_id', $movement->item_id)->value('item_status');
            $eligible = match ($documentType) {
                'receipt' => in_array($itemStatus, ['IN_STOCK', 'NEW'], true),
                // Linh kiện serial đã lĩnh cho hồ sơ bảo hành mang IN_WARRANTY.
                'component_issue' => in_array($itemStatus, ['IN_WARRANTY', 'ISSUED'], true),
                default => in_array($itemStatus, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true),
            };
            if (! $eligible) {
                continue;
            }

            $lines[] = [
                'movement_id' => (string) $movement->id,
                'quantity' => 1,
                'item_id' => (string) $movement->item_id,
            ];
        }

        if ($lines === []) {
            return;
        }

        $payload = [
            'reason' => $reason,
            'reason_code' => 'CORRECTION',
            'lines' => $lines,
        ];
        if ($documentType !== 'receipt') {
            // Post một reversal của phiếu xuất đòi hàng vật lý đã CONFIRMED
            // (PHYSICAL_RETURN_REQUIRED); DRAFT giữ PENDING_RETURN để màn danh
            // sách có cả trạng thái "Chờ hàng quay lại kho".
            $payload['condition_status'] = $physicalReturnConfirmed ? 'SELLABLE' : 'DEFECTIVE';
            $payload['physical_return_confirmed'] = $target === 'POSTED' ? true : $physicalReturnConfirmed;
        }

        $version = (int) ($documentType === 'component_issue'
            ? DB::table('component_issue_documents')->where('id', $documentId)->value('version')
            : DB::table('stock_documents')->where('id', $documentId)->value('version'));
        $key = 'seed-cov-reversal-'.$documentId;
        $result = $this->reversal->execute($documentId, $documentType, $payload, $actorId, $key, sha1($key), $version);
        $reversalId = (string) $result['reversal_document_id'];

        if ($target === 'DRAFT') {
            return;
        }

        if ($target === 'CANCELLED') {
            $this->reversal->cancelReversalDocument(
                $reversalId,
                $actorId,
                (int) DB::table('reversal_document')->where('reversal_document_id', $reversalId)->value('version'),
                'Huỷ điều chỉnh — kiểm đếm lại cho kết quả khớp phiếu gốc.'
            );

            return;
        }

        // POSTED cần chứng từ đính kèm ACTIVE (DocumentEvidenceGuard).
        $this->attachEvidence('REVERSAL', $reversalId, $actorId, 'Biên bản kiểm đếm và xác nhận điều chỉnh tồn kho.');
        $postKey = 'seed-cov-reversal-post-'.$reversalId;
        $this->reversal->postReversalDocument(
            $reversalId,
            $actorId,
            $postKey,
            sha1($postKey),
            (int) DB::table('reversal_document')->where('reversal_document_id', $reversalId)->value('version')
        );
    }

    // ==================================================================
    //  YÊU CẦU XUẤT (OPEN / CANCELLED) — trước đây chỉ có CONSUMED
    // ==================================================================

    /** @param list<string> $productSkus */
    private function seedOutboundRequestStates(array $productSkus): void
    {
        $statuses = ['OPEN', 'OPEN', 'CANCELLED'];
        foreach ($statuses as $i => $status) {
            for ($n = 1; $n <= 3; $n++) {
                $requestNo = 'TOA-COV-'.$status.'-'.sprintf('%02d', ($i * 3) + $n);
                if (DB::table('outbound_requests')->where('request_no', $requestNo)->exists()) {
                    continue;
                }
                $recipient = $this->recipients[(($i * 3) + $n) % max(1, count($this->recipients))] ?? null;
                if ($recipient === null) {
                    return;
                }

                $day = Carbon::parse(self::START)->addDays((($i * 3) + $n) * 11)->setTime(9, 0);
                Carbon::setTestNow($day);
                try {
                    $this->createOutboundRequest(
                        $requestNo,
                        [
                            [$productSkus[(($i * 3) + $n) % count($productSkus)], 3 + $n],
                            [$productSkus[(($i * 5) + $n) % count($productSkus)], 2 + $n],
                        ],
                        $recipient,
                        $this->actorFor('outbound', ($i * 3) + $n),
                        $status
                    );
                } finally {
                    Carbon::setTestNow();
                }
            }
        }
    }

    // ==================================================================
    //  CONTAINER — đủ 7 trạng thái × 3 loại
    // ==================================================================

    /**
     * @param  list<string>  $productSkus
     * @param  list<string>  $componentSkus
     */
    private function seedContainers(array $productSkus, array $componentSkus): void
    {
        // CARTON/BOX bị guard "primary SKU" (chk container primary sku theo loại)
        // nên gắn SKU thật; TRAY là khay chứa hỗn hợp, không gắn primary SKU.
        $plan = [
            ['CTN-COV-001', 'CARTON', 'EMPTY', 'A1-01'],
            ['CTN-COV-002', 'CARTON', 'RECEIVED_PENDING_PUTAWAY', 'A1-02'],
            ['CTN-COV-003', 'CARTON', 'STORED', 'A1-03'],
            ['CTN-COV-004', 'CARTON', 'CLOSED', 'A1-04'],
            ['BOX-COV-001', 'BOX', 'IN_STOCK', 'B2-01'],
            ['BOX-COV-002', 'BOX', 'PARTIAL', 'B2-02'],
            ['BOX-COV-003', 'BOX', 'ISSUED', 'B2-03'],
            ['BOX-COV-004', 'BOX', 'EMPTY', 'B2-04'],
            ['TRAY-COV-001', 'TRAY', 'STORED', 'C3-01'],
            ['TRAY-COV-002', 'TRAY', 'PARTIAL', 'C3-02'],
            ['TRAY-COV-003', 'TRAY', 'CLOSED', 'C3-03'],
            ['TRAY-COV-004', 'TRAY', 'IN_STOCK', 'C3-04'],
        ];

        foreach ($plan as $index => [$code, $type, $status, $location]) {
            if (DB::table('containers')->where('container_code', $code)->exists()) {
                continue;
            }

            $primarySku = match ($type) {
                'CARTON' => $productSkus[$index % count($productSkus)] ?? null,
                'BOX' => ($componentSkus[$index % max(1, count($componentSkus))] ?? null),
                default => null,
            };

            try {
                $containerId = (string) Str::uuid();
                DB::table('containers')->insert([
                    'container_id' => $containerId,
                    'id' => $containerId,
                    'container_code' => $code,
                    'container_type' => $type,
                    'primary_sku_id' => $primarySku,
                    'parent_container_id' => null,
                    'zone_code' => 'ZONE-'.substr($location, 0, 1),
                    'location_hint' => 'Kệ '.$location,
                    'status' => $status,
                    'version' => 1,
                    'created_by' => $this->defaultActor,
                    'updated_by' => $this->defaultActor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                $this->command?->warn('  container '.$code.': '.$this->describe($e));
            }
        }
    }

    // ==================================================================
    //  CƠ CẤU TỒN — trải đủ 4 trạng thái tồn kho
    // ==================================================================

    /**
     * Sau các luồng nhập/xuất, gần như 100% tồn nằm ở AVAILABLE nên donut "Cơ cấu
     * tồn kho" chỉ có một lát cắt và bộ lọc trạng thái tồn (Không bán được / Lỗi /
     * Cách ly) ở màn "Tra cứu tồn & đối soát" trả rỗng.
     *
     * BẤT BIẾN ĐỐI SOÁT (DEC-INV-001 §7.5): báo cáo so
     *   inventory_balances.on_hand_qty == SUM(stock_movements.qty_delta)
     * theo từng (warehouse_id, product_id, stock_status). Seeder LUÔN ghi CẶP
     * movement + balance CÙNG dimension, CÙNG số lượng → mọi dòng thêm vào đều
     * KHỚP, không đẻ chênh lệch giả. movement_type='OPENING' là kiểu duy nhất hợp
     * lệ cho tồn không sinh từ chứng từ (chk_stock_movement_type_current).
     *
     * Cùng cách làm với InventoryStockStatusMixSeeder nhưng chọn SKU ĐỘNG (trải
     * đều nhóm hàng) thay vì danh sách mã cứng.
     */
    private function seedStockStatusSpread(): void
    {
        $statuses = ['DEFECTIVE', 'NON_SELLABLE', 'QUARANTINE'];

        // Ưu tiên SKU đang có tồn AVAILABLE, trải đều nhóm hàng.
        $skuIds = DB::table(DB::raw("(
            SELECT ranked_source.id, ranked_source.rn FROM (
              SELECT s.id,
                     ROW_NUMBER() OVER (PARTITION BY s.category_id ORDER BY s.sku_code) AS rn
              FROM skus s
              WHERE s.status = 'ACTIVE'
                AND EXISTS (
                  SELECT 1 FROM inventory_balances ib
                  WHERE (ib.sku_id = s.id OR ib.product_id = s.id)
                    AND ib.stock_status = 'AVAILABLE' AND ib.available_qty > 0
                )
            ) ranked_source
        ) ranked"))
            ->where('rn', '<=', 4)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $created = 0;
        foreach ($skuIds as $index => $skuId) {
            // 2 trạng thái phi-AVAILABLE cho mỗi SKU, xoay vòng qua cả 3.
            foreach ([$statuses[$index % 3], $statuses[($index + 1) % 3]] as $offset => $status) {
                $qty = 2 + (($index + $offset) % 9);
                if ($this->addOpeningBucket($skuId, $status, $qty, 'SEED-COV-STKMIX')) {
                    $created++;
                }
            }
        }

        $this->command?->info("  đã trải {$created} lát cắt tồn phi-AVAILABLE (khớp sổ cái) cho báo cáo cơ cấu tồn kho.");
    }

    /**
     * Ghi một bucket tồn đầu kỳ (movement OPENING + balance cùng dimension).
     * Idempotent theo business_key. Trả về true khi thực sự tạo mới.
     */
    private function addOpeningBucket(string $skuId, string $stockStatus, int $qty, string $keyPrefix): bool
    {
        $businessKey = $keyPrefix.':'.$stockStatus.':'.$skuId;
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            return false;
        }

        $day = Carbon::parse(self::START)->setTime(0, 10);
        Carbon::setTestNow($day);
        try {
            $balance = DB::table('inventory_balances')
                ->where('warehouse_id', $this->warehouseId)
                ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->where('stock_status', $stockStatus)
                ->first();
            $before = $balance ? (int) $balance->quantity : 0;
            $after = $before + $qty;

            if ($balance === null) {
                DB::table('inventory_balances')->insert([
                    'id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouseId,
                    'product_id' => $skuId, 'sku_id' => $skuId, 'stock_status' => $stockStatus,
                    'quantity' => $after, 'on_hand_qty' => $after, 'available_qty' => $after,
                    'reserved_qty' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('inventory_balances')->where('id', $balance->id)->update([
                    'quantity' => $after, 'on_hand_qty' => $after,
                    'available_qty' => (int) $balance->available_qty + $qty,
                    'version' => (int) $balance->version + 1, 'updated_at' => now(),
                ]);
            }

            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid(),
                'movement_no' => 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'business_key' => $businessKey,
                'movement_type' => 'OPENING',
                'warehouse_id' => $this->warehouseId,
                'product_id' => $skuId, 'sku_id' => $skuId, 'stock_status' => $stockStatus,
                'quantity' => $qty, 'qty_before' => $before, 'qty_delta' => $qty, 'qty_after' => $after,
                'stock_document_id' => null, 'stock_document_line_id' => null,
                'source_type' => 'OPENING_BALANCE', 'source_id' => null, 'source_line_id' => null,
                'item_id' => null, 'original_movement_id' => null, 'correction_chain_id' => null,
                'created_by' => $this->defaultActor, 'occurred_at' => now(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->command?->warn('  lát cắt tồn '.$stockStatus.': '.$this->describe($e));

            return false;
        } finally {
            Carbon::setTestNow();
        }

        return true;
    }

    // ==================================================================
    //  TRẠNG THÁI/TÌNH TRẠNG HIỆN VẬT ở nhánh hiếm (NEW / LOST / ADJUSTED_OUT)
    // ==================================================================

    /**
     * Bộ lọc trạng thái hiện vật ở màn truy vết có 7 giá trị; 3 giá trị cuối
     * (NEW / LOST / ADJUSTED_OUT) và 3 tình trạng phi-NORMAL trước đây chỉ có
     * 2-3 dòng mỗi loại nên nhìn như lỗi dữ liệu. Nâng lên mức nhìn thấy được,
     * KHÔNG đụng hiện vật đang gắn hồ sơ bảo hành để không phá đối soát.
     *
     * Lưu ý: chỉ đổi item_status/item_condition (thuộc tính vật lý của hiện vật),
     * KHÔNG đụng inventory_balances — hiện vật serial không phải nguồn của sổ
     * cái tồn nên đối soát ledger↔balance giữ nguyên.
     */
    private function seedItemLifecycleEdges(): void
    {
        $targets = [
            ['LOST', null, 8],
            ['ADJUSTED_OUT', null, 8],
            [null, 'DEFECTIVE', 10],
            [null, 'NON_SELLABLE', 10],
            [null, 'UNKNOWN', 8],
        ];

        foreach ($targets as [$status, $condition, $count]) {
            $items = DB::table('item_instances')
                ->where('item_status', 'IN_STOCK')
                ->where('item_condition', 'NORMAL')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('warranty_case as wc')
                        ->whereColumn('wc.item_id', 'item_instances.item_id');
                })
                ->orderByDesc('created_at')
                ->limit($count)
                ->pluck('item_id');

            foreach ($items as $itemId) {
                // KHÔNG ghi lý do vào location_hint — đó là cột "Vị trí" hiển thị
                // ở modal chi tiết tồn kho, không phải ô ghi chú.
                $update = ['updated_at' => now()];
                if ($status !== null) {
                    $update['item_status'] = $status;
                }
                if ($condition !== null) {
                    $update['item_condition'] = $condition;
                }
                DB::table('item_instances')->where('item_id', $itemId)->update($update);
            }
        }
    }

    // ==================================================================
    //  HIỆN VẬT MỚI CẤP MÃ (NEW) — chưa ghi sổ nhập
    // ==================================================================

    /**
     * Hiện vật ở trạng thái NEW = mã đã cấp/đã quét nhưng CHƯA ghi sổ nhập (chưa
     * cất hàng). Không thể "hạ cấp" một hiện vật IN_STOCK về NEW vì như vậy sẽ
     * lệch sổ cái tồn, nên sinh mới đúng như luồng quét cấp mã: item_instances
     * trước (physical_codes.item_id là FK trỏ về item), rồi physical_codes, rồi
     * nối ngược physical_code_id.
     *
     * @param  list<string>  $productSkus
     */
    private function seedNewCandidates(array $productSkus): void
    {
        if ($productSkus === []) {
            return;
        }

        for ($i = 1; $i <= 8; $i++) {
            $serial = 'SN-COV-NEW-'.sprintf('%03d', $i);
            if (DB::table('item_instances')->where('serial_number', $serial)->exists()) {
                continue;
            }

            $skuId = $productSkus[$i % count($productSkus)];
            $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
            $day = Carbon::parse(self::END)->subDays($i * 2)->setTime(9, 45);
            $itemId = (string) Str::uuid();
            $physicalCodeId = (string) Str::uuid();

            Carbon::setTestNow($day);
            try {
                DB::table('item_instances')->insert([
                    'item_id' => $itemId,
                    'id' => $itemId,
                    'item_code' => $serial,
                    'sku_id' => $skuId,
                    'physical_code_id' => null,
                    'serial_number' => $serial,
                    'item_status' => 'NEW', 'status' => 'NEW',
                    'item_condition' => 'NORMAL', 'condition' => 'NORMAL',
                    'location_hint' => 'Khu tiếp nhận — chờ ghi sổ nhập',
                    'version' => 1,
                    'received_at' => now(),
                    'created_by' => $this->defaultActor, 'updated_by' => $this->defaultActor,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('physical_codes')->insert([
                    'physical_code_id' => $physicalCodeId,
                    'code_value' => 'HN1|SKU='.$skuCode.'|ITEM='.$serial,
                    'code_type' => 'ITEM', 'carrier_type' => 'QR',
                    'status' => 'ACTIVE', 'assigned_object_type' => 'ITEM',
                    'assigned_object_id' => $itemId, 'item_id' => $itemId, 'is_primary' => true,
                    'assigned_by' => $this->defaultActor,
                    'created_by' => $this->defaultActor, 'updated_by' => $this->defaultActor,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('item_instances')->where('item_id', $itemId)
                    ->update(['physical_code_id' => $physicalCodeId, 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->command?->warn('  hiện vật NEW '.$serial.': '.$this->describe($e));

                return;
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  MÁY IN — đủ 3 trạng thái cấu hình
    // ==================================================================

    private function seedPrinterCoverage(): void
    {
        $plan = [
            ['PRN-COV-INACTIVE', 'Máy in nhãn kho phụ (ngừng dùng)', 'INACTIVE', 'Kho phụ tầng 1', 'FAILED'],
            ['PRN-COV-UNHEALTHY', 'Máy in nhãn quầy giao hàng', 'UNHEALTHY', 'Quầy giao hàng', 'FAILED'],
            ['PRN-COV-ACTIVE', 'Máy in nhãn khu soạn hàng', 'ACTIVE', 'Khu soạn hàng B', 'OK'],
        ];

        foreach ($plan as $index => [$code, $name, $status, $location, $health]) {
            if (DB::table('printer_configs')->where('printer_code', $code)->exists()) {
                continue;
            }

            DB::table('printer_configs')->insert([
                'id' => (string) Str::uuid(),
                'printer_code' => $code,
                'printer_name' => $name,
                'agent_code' => 'AGENT-KHO-'.sprintf('%02d', $index + 1),
                'agent_endpoint' => 'http://print-agent-'.($index + 1).'.hoanam.local:9100',
                'label_size' => '100x50',
                'label_width_mm' => 100,
                'label_height_mm' => 50,
                'dpi' => 203,
                'status' => $status,
                'location_hint' => $location,
                'last_health_at' => now()->subMinutes(15 + ($index * 20)),
                'last_health_result' => $health,
                'config' => json_encode(['darkness' => 12, 'speed' => 4], JSON_UNESCAPED_UNICODE),
                'created_by' => $this->defaultActor, 'updated_by' => $this->defaultActor,
                'created_at' => now(), 'updated_at' => now(), 'version' => 1,
            ]);
        }
    }

    // ==================================================================
    //  CẤT HÀNG (PUTAWAY) — đủ DRAFT / CONFIRMED / CANCELLED
    // ==================================================================

    private function seedPutawayCoverage(): void
    {
        // Cần cặp BOX (nguồn) + TRAY (đích) thật; putaway_events có trigger
        // append-only nên chỉ insert đúng trạng thái cuối, không update sau.
        $boxes = DB::table('containers')
            ->where('container_type', 'BOX')
            ->whereIn('status', ['RECEIVED_PENDING_PUTAWAY', 'IN_STOCK', 'PARTIAL'])
            ->orderBy('container_code')
            ->limit(6)
            ->get(['container_id', 'location_hint']);
        $tray = DB::table('containers')->where('container_type', 'TRAY')->orderBy('container_code')
            ->first(['container_id', 'location_hint']);
        if ($boxes->isEmpty() || $tray === null) {
            return;
        }

        foreach ($boxes as $index => $box) {
            $status = ['DRAFT', 'CANCELLED', 'DRAFT'][$index % 3];
            $note = $status === 'DRAFT'
                ? 'Đề xuất cất hàng — chờ thủ kho xác nhận vị trí.'
                : 'Huỷ đề xuất cất hàng: khay đích đã đầy, chuyển khay khác.';
            $day = Carbon::parse(self::END)->subDays(20 - ($index * 3))->setTime(11, 0);

            $exists = DB::table('putaway_events')
                ->where('box_container_id', $box->container_id)
                ->where('note', $note)
                ->exists();
            if ($exists) {
                continue;
            }

            try {
                DB::table('putaway_events')->insert([
                    'id' => (string) Str::uuid(),
                    'stock_document_id' => null,
                    'warehouse_id' => $this->warehouseId,
                    'container_id' => $box->container_id,
                    'box_container_id' => $box->container_id,
                    'from_parent_container_id' => null,
                    'to_parent_container_id' => $tray->container_id,
                    'from_tray_id' => null,
                    'to_tray_id' => $tray->container_id,
                    'from_location_hint' => $box->location_hint,
                    'to_location_hint' => $tray->location_hint,
                    'location_hint' => $tray->location_hint,
                    'status' => $status,
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                    'note' => $note,
                    'created_by' => $this->actorFor('inbound', $index),
                    'updated_by' => $this->actorFor('inbound', $index),
                    'created_at' => $day,
                    'updated_at' => $day,
                    'version' => 1,
                ]);
            } catch (Throwable $e) {
                $this->command?->warn('  putaway: '.$this->describe($e));

                return;
            }
        }
    }

    // ==================================================================
    //  SỰ CỐ ĐỐI SOÁT — đủ 4 trạng thái × 3 mức nghiêm trọng
    // ==================================================================

    /**
     * Sự cố đối soát — đủ 4 trạng thái × 3 mức nghiêm trọng, và QUAN TRỌNG là
     * mỗi sự cố phải nói đúng sự thật của sổ sách:
     *
     *  - OPEN / INVESTIGATING = chênh lệch CÒN SỐNG: seeder tạo một sai lệch
     *    THẬT giữa inventory_balances và tổng stock_movements của đúng dimension
     *    (warehouse, sku, stock_status) đó. Nhờ vậy màn "Tra cứu tồn & đối soát"
     *    có dòng "Lệch" thật, widget "Tỷ lệ đối soát" và card cảnh báo ở Dashboard
     *    có số khác 0 — thay vì 100% khớp khiến cả màn đối soát trở nên vô nghĩa.
     *    Đây chính là loại sai lệch mà kiểm kê thực tế phát hiện; nó KHÔNG phải
     *    dữ liệu mồ côi vì luôn có một sự cố đối soát mô tả kèm số liệu khớp.
     *
     *  - RESOLVED / WAIVED = chênh lệch ĐÃ XỬ LÝ: sổ sách hiện đã khớp, bản ghi
     *    chỉ lưu số liệu lịch sử + cách xử lý. KHÔNG để lại sai lệch sống.
     *
     * Chỉ chạm tối đa 6 dimension nên tỉ lệ khớp toàn kho vẫn ~98%.
     */
    private function seedReconciliationIncidents(): void
    {
        if (DB::table('reconciliation_incidents')->where('incident_no', 'like', 'REC-COV-%')->exists()) {
            return;
        }

        // Mỗi dimension một dòng balance thật, trải đều 4 trạng thái tồn.
        $dimensions = DB::table('inventory_balances')
            ->where('warehouse_id', $this->warehouseId)
            ->whereNotNull('sku_id')
            ->where('on_hand_qty', '>', 6)
            ->orderBy('stock_status')
            ->orderBy('sku_id')
            ->limit(60)
            ->get(['id', 'sku_id', 'stock_status', 'on_hand_qty', 'quantity', 'available_qty', 'version']);
        if ($dimensions->isEmpty()) {
            return;
        }

        $severities = ['WARNING', 'ERROR', 'CRITICAL'];
        $types = ['QUANTITY_MISMATCH', 'SERIAL_SUBLEDGER_MISMATCH', 'CONTAINER_INTEGRITY_MISMATCH'];
        $reasons = [
            'Kiểm kê định kỳ cuối tháng phát hiện số đếm thực tế lệch sổ sách.',
            'Sổ phụ serial lệch sổ cái tồn sau đợt chuyển kho nội bộ.',
            'Số lượng trong thùng không khớp sổ phụ container sau khi tách lô.',
        ];
        $resolutionTypes = ['ADJUSTED', 'RECOUNTED', 'WAIVED_BY_MANAGER'];

        // 6 sự cố còn sống (OPEN/INVESTIGATING) + 6 sự cố đã đóng (RESOLVED/WAIVED).
        $plan = [];
        foreach (['OPEN', 'INVESTIGATING'] as $status) {
            foreach ($severities as $severity) {
                $plan[] = [$status, $severity, true];
            }
        }
        foreach (['RESOLVED', 'WAIVED'] as $status) {
            foreach ($severities as $severity) {
                $plan[] = [$status, $severity, false];
            }
        }

        $index = 0;
        $liveCount = 0;
        foreach ($plan as [$status, $severity, $live]) {
            $dimension = $dimensions[$index * 4 % $dimensions->count()] ?? null;
            $index++;
            if ($dimension === null) {
                continue;
            }

            $incidentNo = 'REC-COV-'.sprintf('%03d', $index);
            $detectedAt = Carbon::parse(self::START)->addDays($index * 9)->setTime(23, 30);
            // Thừa/thiếu xen kẽ; thiếu không bao giờ kéo tồn xuống âm.
            $variance = ($index % 2 === 0 ? 1 : -1) * (1 + ($index % 4));
            $ledgerQty = (int) $dimension->on_hand_qty;
            if ($ledgerQty + $variance < 0) {
                $variance = abs($variance);
            }
            $balanceQty = $ledgerQty + $variance;

            if ($live) {
                // Ghi lệch THẬT vào sổ tồn (không tạo movement) — đúng bản chất
                // "sổ sách lệch thực tế" mà đối soát phải phát hiện.
                DB::table('inventory_balances')->where('id', $dimension->id)->update([
                    'quantity' => (int) $dimension->quantity + $variance,
                    'on_hand_qty' => $balanceQty,
                    'available_qty' => max(0, (int) $dimension->available_qty + $variance),
                    'version' => (int) $dimension->version + 1,
                    'updated_at' => now(),
                ]);
                $liveCount++;
            }

            $closed = ! $live;
            DB::table('reconciliation_incidents')->insert([
                'id' => (string) Str::uuid(),
                'incident_no' => $incidentNo,
                'divergence_type' => $types[$index % count($types)],
                'warehouse_id' => $this->warehouseId,
                'sku_id' => $dimension->sku_id,
                'item_id' => null,
                'detected_value' => json_encode([
                    'stock_status' => $dimension->stock_status,
                    'on_hand_qty' => $balanceQty,
                    'ledger_qty' => $ledgerQty,
                    'variance' => $variance,
                ], JSON_UNESCAPED_UNICODE),
                'status' => $status,
                'severity' => $severity,
                'reason' => $reasons[$index % count($reasons)],
                'ledger_qty' => $ledgerQty,
                'balance_qty' => $balanceQty,
                'subledger_qty' => $ledgerQty,
                'difference_qty' => $variance,
                'detected_at' => $detectedAt,
                'resolution_ref' => $closed ? 'BB-KIEM-KE-'.sprintf('%03d', $index) : null,
                'resolution_type' => $closed ? $resolutionTypes[$index % count($resolutionTypes)] : null,
                'resolution_note' => $closed
                    ? 'Đã đối chiếu lại phiếu gốc, đếm lại thực tế và điều chỉnh sổ tồn theo biên bản kiểm kê.'
                    : null,
                'resolved_at' => $closed ? $detectedAt->copy()->addDays(2)->setTime(16, 0) : null,
                'resolved_by' => $closed ? ($this->actors['manager01'] ?? $this->defaultActor) : null,
                'created_by' => $this->defaultActor,
                'updated_by' => $this->defaultActor,
                'version' => $closed ? 2 : 1,
                'created_at' => $detectedAt,
                'updated_at' => $closed ? $detectedAt->copy()->addDays(2) : $detectedAt,
            ]);
        }

        // Sự cố cũ (REC-SEED-*) đang RESOLVED/WAIVED nhưng bỏ trống mọi cột xử lý
        // → màn/báo cáo hiện "Chưa có" ở 4 cột. Điền cho đủ.
        DB::table('reconciliation_incidents')
            ->whereIn('status', ['RESOLVED', 'WAIVED'])
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => DB::raw("detected_at + interval '2 days'"),
                'resolved_by' => $this->actors['manager01'] ?? $this->defaultActor,
                'resolution_type' => 'RECOUNTED',
                'resolution_note' => 'Kiểm đếm lại theo biên bản, số liệu khớp sau khi điều chỉnh.',
                'updated_at' => now(),
            ]);

        $this->command?->info("  đã ghi nhận {$liveCount} chênh lệch đối soát còn sống (kèm sự cố mô tả) cho màn đối soát.");
    }

    // ==================================================================
    //  BÁO CÁO CHẤT LƯỢNG IMPORT (SKU) — VALID / REJECTED / COMMITTED
    // ==================================================================

    private function seedImportReports(): void
    {
        $actorIds = array_values($this->actors);
        $plan = [
            // [status, mode, total, valid, error, inserted, updated, số dòng lỗi trùng, số dòng lỗi khác]
            ['COMMITTED', 'COMMIT', 120, 120, 0, 96, 24, 0, 0],
            ['COMMITTED', 'COMMIT', 80, 74, 6, 60, 14, 4, 2],
            ['COMMITTED', 'COMMIT', 200, 188, 12, 150, 38, 5, 7],
            ['VALID', 'VALIDATE', 60, 60, 0, 0, 0, 0, 0],
            ['VALID', 'VALIDATE', 45, 41, 4, 0, 0, 3, 1],
            ['REJECTED', 'VALIDATE', 30, 12, 18, 0, 0, 6, 12],
            ['REJECTED', 'COMMIT', 25, 9, 16, 0, 0, 9, 7],
            ['COMMITTED', 'COMMIT', 150, 150, 0, 120, 30, 0, 0],
            ['VALID', 'VALIDATE', 90, 85, 5, 0, 0, 2, 3],
            ['REJECTED', 'VALIDATE', 40, 15, 25, 0, 0, 10, 15],
            ['COMMITTED', 'COMMIT', 110, 104, 6, 88, 16, 3, 3],
            ['COMMITTED', 'COMMIT', 70, 70, 0, 55, 15, 0, 0],
        ];

        $days = $this->calendar();
        foreach ($plan as $i => [$status, $mode, $total, $valid, $error, $inserted, $updated, $duplicates, $failures]) {
            $requestId = 'seed-cov-import-'.sprintf('%03d', $i + 1);
            if (DB::table('import_reports')->where('request_id', $requestId)->exists()) {
                continue;
            }

            $day = $days[$this->spreadIndex($i, count($plan), count($days))]->copy()->setTime(10, 15);
            $rowErrors = [];
            $row = 2;
            for ($d = 0; $d < $duplicates; $d++, $row++) {
                $rowErrors[] = [
                    'row' => $row,
                    'code' => 'SKU_CODE_DUPLICATE',
                    'message' => 'Mã SKU đã tồn tại trong hệ thống, dòng bị bỏ qua.',
                    'field' => 'sku_code',
                ];
            }
            $failureCodes = [
                ['MISSING_REQUIRED_FIELD', 'Thiếu trường bắt buộc "Tên SKU".', 'sku_name'],
                ['INVALID_CATEGORY', 'Nhóm hàng không tồn tại trong danh mục.', 'category_code'],
                ['INVALID_BRAND', 'Hãng không tồn tại trong danh mục.', 'brand_code'],
                ['INVALID_UNIT', 'Đơn vị tính không hợp lệ.', 'unit'],
                ['INVALID_TRACKING_MODE', 'Cách theo dõi phải là SERIAL hoặc QUANTITY.', 'tracking_mode'],
            ];
            for ($f = 0; $f < $failures; $f++, $row++) {
                [$code, $message, $field] = $failureCodes[$f % count($failureCodes)];
                $rowErrors[] = ['row' => $row, 'code' => $code, 'message' => $message, 'field' => $field];
            }

            DB::table('import_reports')->insert([
                'import_report_id' => (string) Str::uuid(),
                'import_type' => 'SKU',
                'mode' => $mode,
                'status' => $status,
                'file_name' => 'danh-muc-sku-'.$day->format('Ymd').'-'.sprintf('%02d', $i + 1).'.xlsx',
                'file_sha256' => hash('sha256', $requestId),
                'template_version' => 'v1',
                'total_rows' => $total,
                'valid_rows' => $valid,
                'error_rows' => $error,
                'inserted_rows' => $inserted,
                'updated_rows' => $updated,
                'row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE),
                'actor_id' => $actorIds[$i % max(1, count($actorIds))] ?? $this->defaultActor,
                'request_id' => $requestId,
                'options_fingerprint' => hash('sha256', 'seed-cov-options-'.$i),
                'created_at' => $day,
                'updated_at' => $day,
            ]);
        }
    }

    // ==================================================================
    //  BATCH TOA XUẤT (Import & Match) — đủ 4 trạng thái batch × 3 kết quả dòng
    // ==================================================================

    private function seedOutboundRequestImports(): void
    {
        $skuCodes = DB::table('skus')->where('status', 'ACTIVE')->orderBy('sku_code')->limit(40)->pluck('sku_code');
        if ($skuCodes->isEmpty()) {
            return;
        }

        $days = $this->calendar();
        $plan = [
            ['UPLOADED', [['VALID', 6], ['WARNING', 2], ['ERROR', 1]]],
            ['VALIDATED', [['VALID', 9], ['WARNING', 1], ['ERROR', 0]]],
            ['REJECTED', [['VALID', 2], ['WARNING', 1], ['ERROR', 7]]],
            ['CONSUMED', [['VALID', 12], ['WARNING', 0], ['ERROR', 0]]],
            ['UPLOADED', [['VALID', 4], ['WARNING', 3], ['ERROR', 2]]],
            ['VALIDATED', [['VALID', 7], ['WARNING', 2], ['ERROR', 1]]],
            ['REJECTED', [['VALID', 1], ['WARNING', 0], ['ERROR', 9]]],
            ['CONSUMED', [['VALID', 8], ['WARNING', 1], ['ERROR', 0]]],
        ];

        foreach ($plan as $i => [$status, $mix]) {
            $importNo = 'IMP-COV-'.sprintf('%03d', $i + 1);
            if (DB::table('outbound_request_imports')->where('import_no', $importNo)->exists()) {
                continue;
            }

            $day = $days[$this->spreadIndex($i, count($plan), count($days))]->copy()->setTime(8, 45);
            $total = array_sum(array_column($mix, 1));
            $valid = $mix[0][1];
            $errors = $mix[2][1];
            $importId = (string) Str::uuid();

            DB::table('outbound_request_imports')->insert([
                'id' => $importId,
                'import_no' => $importNo,
                'source_file_name' => 'toa-xuat-'.$day->format('Ymd').'-'.sprintf('%02d', $i + 1).'.xlsx',
                'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
                'source_checksum' => hash('sha256', $importNo),
                'status' => $status,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'error_rows' => $errors,
                'uploaded_at' => $day,
                'uploaded_by' => $this->actorFor('outbound', $i),
                'validated_at' => $status === 'UPLOADED' ? null : $day->copy()->addMinutes(12),
                'consumed_at' => $status === 'CONSUMED' ? $day->copy()->addHours(2) : null,
                'consumed_by' => $status === 'CONSUMED' ? $this->actorFor('outbound', $i) : null,
                'version' => 1,
                'created_by' => $this->actorFor('outbound', $i),
                'updated_by' => $this->actorFor('outbound', $i),
                'created_at' => $day,
                'updated_at' => $day,
            ]);

            $rowNo = 0;
            foreach ($mix as [$validationStatus, $count]) {
                for ($n = 0; $n < $count; $n++) {
                    $rowNo++;
                    $skuCode = (string) $skuCodes[($rowNo + $i) % $skuCodes->count()];
                    $requiredQty = 1 + (($rowNo + $i) % 12);
                    [$errorCode, $errorMessage, $errorField] = match ($validationStatus) {
                        'ERROR' => ['SKU_NOT_FOUND', 'Mã SKU không tồn tại trong danh mục.', 'sku_code'],
                        'WARNING' => ['QTY_EXCEEDS_STOCK', 'Số lượng yêu cầu lớn hơn tồn khả dụng hiện tại.', 'required_qty'],
                        default => [null, null, null],
                    };

                    DB::table('outbound_request_import_rows')->insert([
                        'id' => (string) Str::uuid(),
                        'import_id' => $importId,
                        'row_no' => $rowNo,
                        'request_no' => $importNo.'-R'.sprintf('%03d', $rowNo),
                        'customer_name' => $this->recipients[$rowNo % max(1, count($this->recipients))]['name'] ?? 'Khách lẻ',
                        'customer_address' => $this->recipients[$rowNo % max(1, count($this->recipients))]['address'] ?? 'Việt Nam',
                        'sku_code' => $validationStatus === 'ERROR' ? 'SKU-KHONG-TON-TAI-'.$rowNo : $skuCode,
                        'product_name_raw' => 'Dòng '.$rowNo.' — '.$skuCode,
                        'required_qty' => $requiredQty,
                        'note' => 'Dòng nhập từ file Excel '.$importNo.'.',
                        'validation_status' => $validationStatus,
                        'error_field' => $errorField,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'raw_payload' => json_encode(['sku_code' => $skuCode, 'qty' => $requiredQty, 'request_no' => $importNo.'-R'.sprintf('%03d', $rowNo)], JSON_UNESCAPED_UNICODE),
                        'normalized_payload' => json_encode(['sku_code' => $skuCode, 'required_qty' => $requiredQty, 'request_no' => $importNo.'-R'.sprintf('%03d', $rowNo)], JSON_UNESCAPED_UNICODE),
                        'validation_errors' => $errorCode === null
                            ? json_encode([], JSON_UNESCAPED_UNICODE)
                            : json_encode([['code' => $errorCode, 'error_code' => $errorCode, 'message' => $errorMessage, 'field' => $errorField]], JSON_UNESCAPED_UNICODE),
                        'created_at' => $day,
                    ]);
                }
            }
        }
    }

    // ==================================================================
    //  NHẬT KÝ IN NHÃN — PRINT/REPRINT × PRINTED/FAILED/PENDING/SENT
    // ==================================================================

    private function seedLabelPrintCoverage(): void
    {
        $printers = DB::table('printer_configs')->orderBy('printer_code')->get(['id', 'status']);
        if ($printers->isEmpty()) {
            return;
        }
        // Máy in phải ACTIVE tại thời điểm ghi sự kiện (trg_erd_print_printer_active).
        $activePrinters = $printers->where('status', 'ACTIVE')->values();
        if ($activePrinters->isEmpty()) {
            return;
        }

        $snapshots = DB::table('label_snapshots')
            ->orderBy('created_at')
            ->limit(40)
            ->get(['id', 'created_at']);
        if ($snapshots->isEmpty()) {
            return;
        }

        $matrix = [
            ['PRINT', 'PRINTED', null],
            ['REPRINT', 'PRINTED', null],
            ['PRINT', 'FAILED', 'Máy in hết giấy — lệnh in bị huỷ.'],
            ['REPRINT', 'FAILED', 'Mất kết nối tới agent in tại kho.'],
            ['PRINT', 'PENDING', null],
            ['PRINT', 'SENT', null],
            ['REPRINT', 'SENT', null],
        ];

        foreach ($snapshots as $index => $snapshot) {
            [$eventType, $status, $errorMessage] = $matrix[$index % count($matrix)];
            $requestId = 'seed-cov-label-'.$snapshot->id.'-'.$index;
            if (DB::table('label_print_events')->where('request_id', $requestId)->exists()) {
                continue;
            }

            $printer = $activePrinters[$index % $activePrinters->count()];
            $printedAt = Carbon::parse($snapshot->created_at)->addMinutes(15 + ($index % 90));
            // ux_erd_label_print_events(label_snapshot_id, attempt_no): một số
            // bản chụp đã có lần in #1 từ seeder trước → lấy số lần kế tiếp.
            $attemptNo = 1 + (int) DB::table('label_print_events')
                ->where('label_snapshot_id', $snapshot->id)
                ->max('attempt_no');

            try {
                DB::table('label_print_events')->insert([
                    'id' => (string) Str::uuid(),
                    'label_snapshot_id' => $snapshot->id,
                    'printer_config_id' => $printer->id,
                    'printer_id' => $printer->id,
                    'event_type' => $eventType,
                    'attempt_no' => $attemptNo,
                    'status' => $status,
                    'error_code' => $status === 'FAILED' ? 'PRINTER_UNAVAILABLE' : null,
                    'error_message' => $errorMessage,
                    'requested_at' => $printedAt->copy()->subMinutes(2),
                    'printed_at' => in_array($status, ['PRINTED'], true) ? $printedAt : null,
                    'completed_at' => in_array($status, ['PRINTED', 'FAILED'], true) ? $printedAt : null,
                    'job_id' => 'JOB-'.strtoupper(substr(md5($requestId), 0, 10)),
                    'request_id' => $requestId,
                    'actor_id' => $this->actorFor('outbound', $index),
                    'metadata' => json_encode(['source' => 'seeder-coverage'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $printedAt,
                    'created_by' => $this->actorFor('outbound', $index),
                    'updated_at' => $printedAt,
                    'updated_by' => $this->actorFor('outbound', $index),
                    'version' => 0,
                ]);
            } catch (Throwable $e) {
                $this->command?->warn('  nhật ký in nhãn (bỏ qua 1 bản chụp): '.$this->describe($e));
            }
        }
    }

    // ==================================================================
    //  ĐIỀN CÁC Ô HIỂN THỊ CÒN TRỐNG (do seeder cũ để null)
    // ==================================================================

    /**
     * Yêu cầu người dùng: "không cột nào được thiếu dữ liệu". Các seeder cũ để
     * trống vài cột vẫn hiện ra UI, nên điền nốt ở đây — chỉ ĐIỀN CHỖ TRỐNG,
     * không ghi đè giá trị đã có, và bỏ qua an toàn nếu bảng bị guard immutable.
     */
    private function backfillDisplayGaps(): void
    {
        // 1. "Vị trí" của từng hiện vật ở modal chi tiết tồn kho (INV-01) —
        //    trước đây null gần như toàn bộ nên cột luôn hiện "—". Sinh vị trí
        //    ổn định theo item_id (chạy lại không đổi chỗ hàng).
        $items = DB::table('item_instances')->whereNull('location_hint')->pluck('item_id');
        foreach ($items as $itemId) {
            $hash = crc32((string) $itemId);
            $location = sprintf(
                'Kệ %s-%02d · Tầng %d · Ô %02d',
                chr(65 + ($hash % 6)),          // dãy kệ A..F
                ($hash >> 3) % 12 + 1,          // số kệ 01..12
                ($hash >> 7) % 4 + 1,           // tầng 1..4
                ($hash >> 11) % 20 + 1          // ô 01..20
            );
            DB::table('item_instances')->where('item_id', $itemId)->update(['location_hint' => $location]);
        }

        // 2. Vị trí của thùng/khay chưa gán chỗ.
        foreach (DB::table('containers')->whereNull('location_hint')->get(['container_id', 'container_type']) as $i => $container) {
            DB::table('containers')->where('container_id', $container->container_id)->update([
                'location_hint' => ($container->container_type === 'TRAY' ? 'Khay ' : 'Thùng ')
                    .sprintf('%s-%02d', chr(65 + ($i % 4)), ($i % 9) + 1),
                'zone_code' => 'ZONE-'.chr(65 + ($i % 4)),
                'updated_at' => now(),
            ]);
        }

        // 3. Chứng từ nguồn của phiếu nhập nháp (cột "Chứng từ nguồn").
        DB::table('stock_documents')
            ->where('doc_type', 'receipt')
            ->where('status', 'DRAFT')
            ->whereNull('source_name')
            ->update([
                'source_name' => DB::raw("'Đơn đặt hàng NCC ' || doc_no"),
                'updated_at' => now(),
            ]);

        // 4. Lý do của sự cố đối soát cũ (cột "Diễn giải").
        DB::table('reconciliation_incidents')
            ->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', ''))
            ->update([
                'reason' => 'Chênh lệch phát hiện khi kiểm kê định kỳ, đã ghi biên bản đối chiếu.',
                'updated_at' => now(),
            ]);

        // 5. Ghi chú phiếu xuất linh kiện (bảng có thể bị guard immutable sau
        //    POSTED tuỳ driver → bọc try/catch, thiếu ghi chú không phải lỗi chặn).
        try {
            DB::table('component_issue_documents')
                ->whereNull('note')
                ->update([
                    'note' => DB::raw("'Xuất linh kiện thay thế cho hồ sơ bảo hành — phiếu ' || component_issue_no"),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            $this->command?->warn('  ghi chú phiếu xuất linh kiện: '.$this->describe($e));
        }
    }

    // ==================================================================
    //  HELPERS
    // ==================================================================

    /**
     * @param  list<array{product_id:string,qty_planned:int}>  $lines
     * @param  array<string,mixed>  $extra
     * @return array{0:string,1:list<string>}
     */
    private function createDocument(string $type, string $docNo, array $lines, string $actorId, array $extra = []): array
    {
        $documentId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => $type,
            'doc_no' => $docNo,
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'src_warehouse_id' => $type === 'issue' ? $this->warehouseId : null,
            'dst_warehouse_id' => $type === 'receipt' ? $this->warehouseId : null,
            'source_name' => $extra['source_name'] ?? null,
            'outbound_request_id' => $extra['outbound_request_id'] ?? null,
            'agency_id' => $extra['agency_id'] ?? null,
            'recipient_name' => $extra['recipient_name'] ?? null,
            'recipient_address' => $extra['recipient_address'] ?? null,
            'note' => $extra['note'] ?? 'Dữ liệu vận hành mẫu 06→09/2026 (seeder).',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineIds = [];
        foreach (array_values($lines) as $index => $line) {
            $lineId = (string) Str::uuid();
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'line_no' => $index + 1,
                'product_id' => $line['product_id'],
                'qty_planned' => (int) $line['qty_planned'],
                'qty_actual' => (int) $line['qty_planned'],
                'scanned_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lineIds[] = $lineId;
        }

        if ($type === 'issue' && ! empty($extra['outbound_request_id'])) {
            DB::table('outbound_requests')->where('id', $extra['outbound_request_id'])->update([
                'status' => 'CONSUMED',
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
        }

        AuditLogger::record(
            action: $type === 'receipt' ? 'CREATE_INBOUND' : 'CREATE_OUTBOUND',
            objectType: 'stock_document',
            objectId: $documentId,
            actorId: $actorId,
            requestId: 'seed-cov-'.strtolower($docNo),
            afterData: ['doc_no' => $docNo, 'status' => 'DRAFT', 'version' => 1],
        );

        return [$documentId, $lineIds];
    }

    private function postDocument(string $documentId, string $type, string $actorId): void
    {
        $version = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');
        DB::table('stock_documents')->where('id', $documentId)->update([
            'version' => $version + 1,
            'updated_by' => $actorId,
            'updated_at' => now(),
        ]);
        $key = 'seed-cov-'.$type.'-'.$documentId;
        $this->stock->postStockDocument($documentId, $type, $actorId, $key, sha1($key));
    }

    /**
     * @param  list<array{0:string,1:int}>  $lines  [sku_id, qty]
     * @param  array{id:string,name:string,address:string,type:string}  $recipient
     */
    private function createOutboundRequest(string $requestNo, array $lines, array $recipient, string $actorId, string $status): string
    {
        $existing = DB::table('outbound_requests')->where('request_no', $requestNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = $this->ensureImport('IMP-COV-REQ-'.substr(md5($requestNo), 0, 8), $actorId);
        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => $requestNo,
            'import_id' => $importId,
            'agency_id' => $recipient['id'],
            'recipient_name' => $recipient['name'],
            'recipient_address' => $recipient['address'],
            'request_date' => now()->toDateString(),
            'status' => $status,
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_values($lines) as $index => [$skuId, $qty]) {
            DB::table('outbound_request_lines')->insert([
                'id' => (string) Str::uuid(),
                'outbound_request_id' => $requestId,
                'line_no' => $index + 1,
                'sku_id' => $skuId,
                'required_qty' => $qty,
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $requestId;
    }

    private function ensureImport(string $importNo, string $actorId): string
    {
        $existing = DB::table('outbound_request_imports')->where('import_no', $importNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => $importNo,
            'source_file_name' => 'toa-xuat-'.strtolower($importNo).'.xlsx',
            'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
            'source_checksum' => hash('sha256', $importNo),
            'status' => 'CONSUMED',
            'total_rows' => 1, 'valid_rows' => 1, 'error_rows' => 0,
            'uploaded_at' => now(), 'uploaded_by' => $actorId,
            'validated_at' => now(), 'consumed_at' => now(), 'consumed_by' => $actorId,
            'version' => 1,
            'created_by' => $actorId, 'updated_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $importId;
    }

    private function createComponentIssueDocument(string $issueNo, string $caseId, string $actorId, string $status): string
    {
        $documentId = (string) Str::uuid();
        DB::table('component_issue_documents')->insert([
            'id' => $documentId,
            'component_issue_no' => $issueNo,
            'warehouse_id' => $this->warehouseId,
            'warranty_case_id' => $caseId,
            'document_date' => now()->toDateString(),
            'purpose' => 'WARRANTY',
            'status' => $status,
            'note' => 'Xuất linh kiện thay thế cho hồ sơ bảo hành.',
            'created_at' => now(), 'created_by' => $actorId,
            'updated_at' => now(), 'updated_by' => $actorId,
            'version' => 0,
        ]);

        AuditLogger::record(
            action: 'CREATE_COMPONENT_ISSUE',
            objectType: 'component_issue_document',
            objectId: $documentId,
            actorId: $actorId,
            requestId: 'seed-cov-'.strtolower($issueNo),
            afterData: ['status' => $status, 'purpose' => 'WARRANTY'],
        );

        return $documentId;
    }

    private function addComponentIssueLine(string $documentId, int $lineNo, string $skuId, ?string $itemId, string $actorId): string
    {
        $lineId = (string) Str::uuid();
        DB::table('component_issue_lines')->insert([
            'id' => $lineId,
            'component_issue_document_id' => $documentId,
            'line_no' => $lineNo,
            'sku_id' => $skuId,
            'required_qty' => 1,
            'scanned_qty' => $itemId === null ? 0 : 1,
            'created_at' => now(), 'created_by' => $actorId,
            'updated_at' => now(), 'updated_by' => $actorId,
            'version' => 0,
        ]);

        if ($itemId !== null) {
            DB::table('component_issue_items')->insert([
                'id' => (string) Str::uuid(),
                'component_issue_line_id' => $lineId,
                'item_id' => $itemId,
                'container_id' => null,
                'quantity' => 1,
                'status' => 'ACTIVE',
                'created_at' => now(), 'created_by' => $actorId,
                'updated_at' => now(), 'updated_by' => $actorId,
                'version' => 0,
            ]);
        }

        return $lineId;
    }

    private function attachEvidence(string $documentType, string $documentId, string $actorId, string $note): void
    {
        if (! Schema::hasTable('document_attachments')) {
            return;
        }
        $exists = DB::table('document_attachments')
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'ACTIVE')
            ->exists();
        if ($exists) {
            return;
        }

        $fileName = 'bien-ban-'.strtolower($documentType).'-'.substr($documentId, 0, 8).'.pdf';
        DB::table('document_attachments')->insert([
            'id' => (string) Str::uuid(),
            'document_type' => $documentType,
            'document_id' => $documentId,
            'attachment_type' => 'SOURCE_DOCUMENT',
            'file_name' => $fileName,
            'content_type' => 'application/pdf',
            'file_size_bytes' => 148_000 + (crc32($documentId) % 90_000),
            'storage_disk' => 's3',
            'storage_key' => 'documents/'.strtolower($documentType).'/'.$documentId.'/'.$fileName,
            'checksum' => hash('sha256', $documentId),
            'status' => 'ACTIVE',
            'note' => $note,
            'uploaded_at' => now(),
            'uploaded_by' => $actorId,
            'created_at' => now(), 'created_by' => $actorId,
            'updated_at' => now(), 'updated_by' => $actorId,
            'version' => 1,
        ]);
    }

    private function markLabelPrinted(string $documentId, string $skuId, string $actorId, int $seq): void
    {
        if (DB::table('label_snapshots')->where('outbound_document_id', $documentId)->exists()) {
            return;
        }

        $items = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('m.item_id')
            ->pluck('m.item_id')->all();
        $docNo = (string) (DB::table('stock_documents')->where('id', $documentId)->value('doc_no') ?? $documentId);
        $labelCode = 'PL-'.$docNo;

        // Trải trạng thái nhãn để báo cáo nhãn đóng gói có cả "chờ in" và "in lỗi".
        $status = match ($seq % 8) {
            5 => 'PENDING_PRINT',
            7 => 'PRINT_FAILED',
            default => 'PRINTED',
        };

        $insert = [
            'id' => (string) Str::uuid(),
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            // Gán ngay lúc INSERT — trigger LABEL_SNAPSHOT_IMMUTABLE chặn UPDATE.
            'sku_id' => $skuId, 'item_id' => $items[0] ?? null,
            'label_payload' => json_encode([
                'source' => 'seeder-coverage', 'label_code' => $labelCode, 'outbound_no' => $docNo,
                'outbound_document_id' => $documentId, 'sku_id' => $skuId, 'item_ids' => $items,
            ], JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'applied_at' => $status === 'PRINTED' ? now() : null,
            'applied_by' => $status === 'PRINTED' ? $actorId : null,
            'version' => 1, 'created_by' => $actorId, 'updated_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('label_snapshots', 'label_code')) {
            $insert['label_code'] = $labelCode;
        }
        DB::table('label_snapshots')->insert($insert);
    }

    private function pickIssuableItem(string $skuId): ?string
    {
        $itemId = DB::table('item_instances')
            ->where('sku_id', $skuId)
            ->where('item_status', 'IN_STOCK')
            ->orderBy('item_id')
            ->value('item_id');

        return $itemId !== null ? (string) $itemId : null;
    }

    /** @param list<string> $serials @return list<string> */
    private function itemIdsBySerial(array $serials): array
    {
        return DB::table('item_instances')
            ->whereIn('serial_number', $serials)
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /** Người thao tác luân phiên theo nghiệp vụ (để phòng ban xử lý đa dạng). */
    private function actorFor(string $flow, int $salt): string
    {
        $pool = match ($flow) {
            'outbound' => ['kinhdoanh01', 'kinhdoanh02', 'quanlykho03', 'manager01', 'keeper01'],
            'warranty' => ['baohanh01', 'baohanh02', 'keeper01'],
            default => ['keeper01', 'thukho02', 'nhanvienkho02', 'nhanvienkho03', 'quanlykho02'],
        };
        $available = array_values(array_filter($pool, fn ($u) => isset($this->actors[$u])));
        if ($available === []) {
            return $this->defaultActor;
        }

        return $this->actors[$available[$salt % count($available)]];
    }

    /**
     * Ánh xạ chỉ số thứ $index trong tổng $total sang chỉ số ngày trong kỳ sao
     * cho các mốc rải ĐỀU từ ngày đầu tới ngày cuối (không dồn về đầu kỳ).
     */
    private function spreadIndex(int $index, int $total, int $dayCount): int
    {
        if ($total <= 1 || $dayCount <= 1) {
            return 0;
        }

        return (int) round($index * ($dayCount - 1) / ($total - 1)) % $dayCount;
    }

    /** @return list<Carbon> mọi ngày trong kỳ 01/06 → 30/09, giờ hành chính. */
    private function calendar(): array
    {
        $days = [];
        $cursor = Carbon::parse(self::START);
        $end = Carbon::parse(self::END);
        $i = 0;
        while ($cursor->lte($end)) {
            $days[] = $cursor->copy()->setTime(8 + ($i % 8), ($i % 6) * 10, 0);
            $cursor->addDay();
            $i++;
        }

        return $days;
    }

    private function followUpDay(?string $baseAt, int $salt, int $minDays): Carbon
    {
        $base = $baseAt !== null ? Carbon::parse($baseAt) : Carbon::parse(self::START);
        $day = $base->copy()->addDays($minDays + ($salt % 9))->setTime(14, ($salt % 6) * 10);
        $end = Carbon::parse(self::END)->setTime(16, 0);

        return $day->gt($end) ? $end : $day;
    }

    // ==================================================================
    //  CỬA SỔ "VỪA XẢY RA" — 14 NGÀY GẦN NHẤT TÍNH TỚI HÔM NAY
    // ==================================================================

    /**
     * Vì sao phải có phase này (yêu cầu người dùng 04/09/2026):
     *
     * Mọi bộ lọc MẶC ĐỊNH của báo cáo neo vào NGÀY HIỆN TẠI chứ không neo vào
     * lịch demo 06→09/2026 mà các phase trên rải dữ liệu:
     *  - "Báo cáo Nhập – Xuất – Tồn" mở sẵn ở **hôm qua → hôm nay**;
     *  - "Nhập liệu, đối chiếu & lỗi", "Trung tâm kiểm soát in nhãn", "Truy vết
     *    hàng hoá" mở sẵn ở **đầu tháng → HÔM NAY** (preset `this-month` của FE
     *    kết thúc ở today, KHÔNG phải cuối tháng — nên phần dữ liệu rơi vào các
     *    ngày sau hôm nay không cứu được màn hình);
     *  - Thẻ luồng của "Tổng quan vận hành" luôn nhìn 7 ngày gần nhất.
     *
     * Các phase trên dùng spreadIndex() nên rải ĐỀU 122 ngày: rơi đúng vào cửa
     * sổ vài ngày cuối gần như không còn gì. Hệ quả người dùng thấy: cột "Hoàn
     * tác" / "Ảnh hưởng hoàn tác" / "Nhập thuần / Xuất SP / Xuất LK" bằng 0,
     * chuỗi "Điều chỉnh tồn kho" ở màn truy vết trống, cột "Lỗi / Trùng" của
     * Import & Match và cột "Lỗi / lý do" của nhật ký in nhãn đều "Chưa có".
     *
     * Phase này NEO THEO now() nên chạy `db:seed` ngày nào cũng phủ đúng cửa sổ
     * mặc định của ngày đó, và mỗi ngày trong cửa sổ đều có đủ 4 chiều của
     * phương trình XNT (nhập / xuất sản phẩm / xuất linh kiện / điều chỉnh).
     * Idempotent: mọi khoá tự nhiên đều nhúng ngày (PN-RW-20260904, IMP-RW-…),
     * chạy lại trong cùng ngày không nhân bản.
     *
     * @param  list<string>  $productSkus
     * @param  list<string>  $componentQtySkus
     */
    private function seedRecentWindowCoverage(array $productSkus, array $componentQtySkus): void
    {
        if ($productSkus === [] || $componentQtySkus === []) {
            return;
        }

        $caseIds = DB::table('warranty_case')
            ->whereIn('status', ['CHECKING', 'REPAIRING'])
            ->orderBy('warranty_case_code')
            ->pluck('warranty_case_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $today = Carbon::today();
        for ($back = self::RECENT_WINDOW_DAYS - 1; $back >= 0; $back--) {
            $day = $today->copy()->subDays($back);
            $tag = $day->format('Ymd');
            $seq = self::RECENT_WINDOW_DAYS - $back;

            // 1. Nhập sản phẩm serial — nuôi cột "Nhập thuần" và pool hiện vật
            //    để cùng ngày còn có phiếu xuất. 3 SKU/phiếu vì kỳ mặc định của
            //    RPT-XNT-01 chỉ dài 2 ngày: 1 SKU/ngày thì gần như cả bảng vẫn 0.
            $serialSkus = array_values(array_unique([
                $productSkus[$seq % count($productSkus)],
                $productSkus[($seq + 7) % count($productSkus)],
                $productSkus[($seq + 13) % count($productSkus)],
            ]));
            $received = $this->recentSerialReceipt('PN-RW-'.$tag, $serialSkus, $day->copy()->setTime(8, 30), $seq);

            // 2. Nhập linh kiện theo số lượng — nguồn để đảo phiếu NHẬP mà không
            //    vướng guard DOWNSTREAM_DEPENDENCY của hiện vật serial.
            $qtySkus = array_values(array_unique([
                $componentQtySkus[$seq % count($componentQtySkus)],
                $componentQtySkus[($seq + 2) % count($componentQtySkus)],
            ]));
            $this->recentQuantityReceipt('PN-RWQ-'.$tag, $qtySkus, $day->copy()->setTime(9, 15), $seq);

            // 3. Xuất bán (cột "Xuất SP") + bản chụp nhãn của chính phiếu đó.
            $this->recentIssue('PX-RW-'.$tag, $received, $day->copy()->setTime(14, 10), $seq, $back);

            // 4. Xuất linh kiện bảo hành (cột "Xuất LK").
            $this->recentComponentIssue('CIQ-RW-'.$tag, $qtySkus[0], $caseIds, $day->copy()->setTime(15, 40), $seq);

            // 5. Điều chỉnh tồn kho — luân phiên đảo NHẬP / XUẤT / XUẤT LINH KIỆN
            //    để cả ba cột `receipt_reversal` / `issue_reversal` /
            //    `component_issue_reversal` của RPT-XNT-01 đều có số.
            $this->recentReversal($tag, $day, $seq, $back);

            // 6. Nhật ký in nhãn có lần in LỖI kèm mã lỗi + lý do.
            $this->recentLabelPrintEvents('PX-RW-'.$tag, $day, $seq);

            // 7. Nhập liệu & đối chiếu — cả hai ngữ cảnh IMPORT và MATCH đều phải
            //    có dòng LỖI và dòng TRÙNG. Xen kẽ ngày chẵn/lẻ cho giống nhật ký
            //    thật, nhưng HÔM NAY và HÔM QUA thì cả hai đều phải có: kỳ mặc
            //    định của màn này có thể chỉ dài đúng 1-2 ngày (mùng 1, mùng 2).
            $this->recentImportReport($tag, $day, $seq, $back);
            $this->recentMatchImport($tag, $day, $seq, $back);

            // 8. Điều chỉnh không truy được chứng từ gốc — nguồn DUY NHẤT của KPI
            //    "Ảnh hưởng hoàn tác (+/−)" (xem docblock của phương thức).
            if ($seq % 3 === 1 || $back <= 1) {
                $this->recentOpeningAdjustment($tag, $day, $seq);
            }
        }

        $this->command?->info(
            '  đã bổ sung dữ liệu '.self::RECENT_WINDOW_DAYS.' ngày gần nhất ('
            .$today->copy()->subDays(self::RECENT_WINDOW_DAYS - 1)->toDateString().' → '.$today->toDateString().').'
        );
    }

    /**
     * Phiếu nhập linh kiện theo SỐ LƯỢNG của một ngày trong cửa sổ.
     *
     * @param  list<string>  $skus
     */
    private function recentQuantityReceipt(string $docNo, array $skus, Carbon $day, int $seq): void
    {
        if ($skus === [] || DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }

        $actorId = $this->actorFor('inbound', $seq + 1);
        Carbon::setTestNow($day);
        try {
            $lines = [];
            foreach ($skus as $n => $skuId) {
                $lines[] = ['product_id' => $skuId, 'qty_planned' => 12 + ($n * 4) + ($seq % 5)];
            }
            [$docId, $lineIds] = $this->createDocument('receipt', $docNo, $lines, $actorId, [
                'source_name' => 'Phiếu giao hàng NCC linh kiện '.$docNo,
                'note' => 'Nhập linh kiện rời theo số lượng — dữ liệu vận hành ngày '.$day->format('d/m/Y').'.',
            ]);
            foreach ($lines as $n => $line) {
                $this->stock->scanStockDocument($docId, 'receipt', [
                    'line_id' => $lineIds[$n],
                    'quantity' => (int) $line['qty_planned'],
                ], $actorId);
            }
            $this->postDocument($docId, 'receipt', $actorId);
        } catch (Throwable $e) {
            $this->command?->warn('  phiếu nhập linh kiện '.$docNo.': '.$this->describe($e));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Phiếu nhập serial NHIỀU SKU của một ngày trong cửa sổ.
     *
     * receiptBatch() chỉ nhập được 1 SKU/phiếu (dùng cho lô lớn của phase chính);
     * ở đây cần trải rộng SKU trên ít ngày nên tự dựng phiếu nhiều dòng.
     *
     * @param  list<string>  $skus
     * @return list<array{sku_id:string,item_id:string}>
     */
    private function recentSerialReceipt(string $docNo, array $skus, Carbon $day, int $seq): array
    {
        if ($skus === []) {
            return [];
        }

        $serials = [];
        foreach ($skus as $index => $skuId) {
            $serials[] = ['sku_id' => $skuId, 'serial' => 'SN-'.$docNo.'-'.sprintf('%03d', $index + 1)];
        }

        if (! DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            $actorId = $this->actorFor('inbound', $seq);
            Carbon::setTestNow($day);
            try {
                $lines = array_map(fn ($row) => ['product_id' => $row['sku_id'], 'qty_planned' => 1], $serials);
                [$docId, $lineIds] = $this->createDocument('receipt', $docNo, $lines, $actorId, [
                    'source_name' => 'Hoá đơn NCC '.strtoupper(substr(md5($docNo), 0, 6)).' — lô '.$docNo,
                    'note' => 'Nhập kho ngày '.$day->format('d/m/Y').'.',
                ]);
                foreach ($serials as $i => $row) {
                    $skuCode = (string) DB::table('sku')->where('sku_id', $row['sku_id'])->value('sku_code');
                    $this->stock->scanStockDocument($docId, 'receipt', [
                        'line_id' => $lineIds[$i],
                        'quantity' => 1,
                        'code_value' => "HN1|SKU={$skuCode}|ITEM={$row['serial']}",
                    ], $actorId);
                }
                $this->postDocument($docId, 'receipt', $actorId);
            } catch (Throwable $e) {
                $this->command?->warn('  phiếu nhập '.$docNo.': '.$this->describe($e));
            } finally {
                Carbon::setTestNow();
            }
        }

        $received = [];
        foreach ($serials as $row) {
            $itemId = DB::table('item_instances')->where('serial_number', $row['serial'])->value('item_id');
            if ($itemId !== null) {
                $received[] = ['sku_id' => $row['sku_id'], 'item_id' => (string) $itemId];
            }
        }

        return $received;
    }

    /**
     * Phiếu xuất bán của một ngày trong cửa sổ — dùng chính hiện vật vừa nhập
     * cùng ngày nên không tranh chấp pool tồn của các phase trước.
     *
     * @param  list<array{sku_id:string,item_id:string}>  $received
     */
    private function recentIssue(string $docNo, array $received, Carbon $day, int $seq, int $back): void
    {
        if ($received === [] || DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }
        $recipient = $this->recipients[$seq % max(1, count($this->recipients))] ?? null;
        if ($recipient === null) {
            return;
        }

        // Chừa hiện vật cuối cùng lại trong kho để màn tồn kho vẫn có hàng mới về.
        $eligible = array_values(array_filter(
            array_slice($received, 0, max(1, count($received) - 1)),
            fn ($row) => DB::table('item_instances')->where('item_id', $row['item_id'])->value('item_status') === 'IN_STOCK'
        ));
        if ($eligible === []) {
            return;
        }

        $actorId = $this->actorFor('outbound', $seq);
        Carbon::setTestNow($day);
        try {
            $requestLines = [];
            foreach ($eligible as $row) {
                $requestLines[$row['sku_id']] = ($requestLines[$row['sku_id']] ?? 0) + 1;
            }
            $requestId = $this->createOutboundRequest(
                'TOA-RW-'.$day->format('Ymd'),
                array_map(fn ($skuId, $qty) => [$skuId, $qty], array_keys($requestLines), array_values($requestLines)),
                $recipient,
                $actorId,
                'CONSUMED'
            );
            $lines = array_map(fn ($row) => ['product_id' => $row['sku_id'], 'qty_planned' => 1], $eligible);
            [$docId, $lineIds] = $this->createDocument('issue', $docNo, $lines, $actorId, [
                'outbound_request_id' => $requestId,
                'agency_id' => $recipient['id'],
                'recipient_name' => $recipient['name'],
                'recipient_address' => $recipient['address'],
                'note' => 'Giao hàng ngày '.$day->format('d/m/Y').' — '.$recipient['name'].'.',
            ]);
            foreach ($eligible as $i => $row) {
                $this->stock->scanStockDocument($docId, 'issue', [
                    'line_id' => $lineIds[$i],
                    'quantity' => 1,
                    'item_id' => $row['item_id'],
                ], $actorId);
            }
            // markLabelPrinted() chọn trạng thái bản chụp theo `seq % 8` (5 = chờ
            // in, 7 = in lỗi). Kỳ mặc định của Trung tâm in nhãn là "đầu tháng →
            // hôm nay" nên có thể chỉ dài 1-2 ngày; ép hai ngày gần nhất mang đủ
            // "in lỗi" và "chờ in" để KPI + bộ lọc kết quả luôn ra dòng.
            $this->markLabelPrinted($docId, $eligible[0]['sku_id'], $actorId, match ($back) {
                1 => 7,
                2 => 5,
                default => $seq,
            });
            $this->postDocument($docId, 'issue', $actorId);
        } catch (Throwable $e) {
            $this->command?->warn('  phiếu xuất '.$docNo.': '.$this->describe($e));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Phiếu xuất linh kiện bảo hành theo số lượng của một ngày trong cửa sổ.
     *
     * @param  list<string>  $caseIds
     */
    private function recentComponentIssue(string $issueNo, string $skuId, array $caseIds, Carbon $day, int $seq): void
    {
        if ($caseIds === [] || DB::table('component_issue_documents')->where('component_issue_no', $issueNo)->exists()) {
            return;
        }

        // Chỉ xuất trong phạm vi tồn khả dụng để không đẩy tồn xuống âm.
        $available = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $this->warehouseId)
            ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
            ->where('stock_status', 'AVAILABLE')
            ->sum('available_qty');
        $qty = min(2 + ($seq % 4), max(0, $available - 2));
        if ($qty <= 0) {
            return;
        }

        $actorId = $this->actorFor('warranty', $seq);
        Carbon::setTestNow($day);
        try {
            $documentId = $this->createComponentIssueDocument($issueNo, $caseIds[$seq % count($caseIds)], $actorId, 'DRAFT');
            DB::table('component_issue_lines')->insert([
                'id' => (string) Str::uuid(),
                'component_issue_document_id' => $documentId,
                'line_no' => 1,
                'sku_id' => $skuId,
                'required_qty' => $qty,
                'scanned_qty' => $qty,
                'created_at' => now(), 'created_by' => $actorId,
                'updated_at' => now(), 'updated_by' => $actorId,
                'version' => 0,
            ]);
            $key = 'seed-rw-cq-'.$documentId;
            $this->stock->postComponentIssue($documentId, $actorId, $key, sha1($key));
        } catch (Throwable $e) {
            $this->command?->warn('  phiếu xuất linh kiện '.$issueNo.': '.$this->describe($e));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Một phiếu điều chỉnh tồn kho mỗi ngày, luân phiên đảo NHẬP → XUẤT → XUẤT
     * LINH KIỆN và luân phiên trạng thái POSTED / DRAFT / CANCELLED.
     *
     * Đảo phiếu nhập luôn nhắm vào phiếu linh kiện theo SỐ LƯỢNG (`PN-RWQ-*`):
     * đảo phiếu nhập serial cùng ngày sẽ vướng BR-REV-03 nếu hiện vật đã lên
     * phiếu xuất trong cùng ngày.
     *
     * Luân phiên trạng thái tính theo $back (số ngày lùi) chứ KHÔNG theo $seq:
     * DRAFT/CANCELLED không sinh movement nào, mà kỳ mặc định của RPT-XNT-01 chỉ
     * dài đúng 2 ngày (hôm qua → hôm nay) — rơi trúng hai ngày đó thì cột "Hoàn
     * tác" lại bằng 0 y như trước. Hai ngày gần nhất vì vậy luôn POSTED.
     */
    private function recentReversal(string $tag, Carbon $day, int $seq, int $back): void
    {
        $target = match (true) {
            $back <= 1 => 'POSTED',
            $back % 7 === 4 => 'CANCELLED',
            $back % 5 === 3 => 'DRAFT',
            default => 'POSTED',
        };

        [$documentId, $documentType, $reason] = match ($seq % 3) {
            0 => [
                DB::table('stock_documents')->where('doc_no', 'PN-RWQ-'.$tag)->where('status', 'POSTED')->value('id'),
                'receipt',
                'Kiểm đếm lại lô linh kiện vừa nhập, số thực nhận ít hơn phiếu giao hàng NCC.',
            ],
            1 => [
                DB::table('stock_documents')->where('doc_no', 'PX-RW-'.$tag)->where('status', 'POSTED')->value('id'),
                'issue',
                'Khách trả lại máy do giao sai cấu hình so với đơn đặt hàng.',
            ],
            default => [
                DB::table('component_issue_documents')->where('component_issue_no', 'CIQ-RW-'.$tag)->where('status', 'POSTED')->value('id'),
                'component_issue',
                'Linh kiện đã lĩnh nhưng không dùng tới, kỹ thuật nhập trả lại kho.',
            ],
        };
        if ($documentId === null) {
            return;
        }

        Carbon::setTestNow($day->copy()->setTime(16, 45));
        try {
            $this->runReversal(
                (string) $documentId,
                $documentType,
                $target,
                $reason,
                $this->actorFor('inbound', $seq + 2),
                $target === 'POSTED',
                1
            );
        } catch (Throwable $e) {
            $this->command?->warn('  điều chỉnh tồn kho '.$tag.' ('.$documentType.'): '.$this->describe($e));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Nhật ký in nhãn của phiếu xuất trong ngày.
     *
     * Cột "Lỗi / lý do" của RPT-LABEL-01 đọc `error_code` + `metadata.reason` +
     * `error_message` của TỪNG lần in, mà lần in lỗi duy nhất trong bộ dữ liệu cũ
     * nằm ở tháng 6–8 (seedLabelPrintCoverage() chỉ lấy 40 bản chụp CŨ NHẤT) nên
     * cửa sổ mặc định không bao giờ thấy. Ở đây mỗi 3/4 số ngày có ít nhất một
     * lần in lỗi, đủ mã lỗi khác nhau để bộ lọc "Kết quả = In lỗi" cũng ra dòng.
     */
    private function recentLabelPrintEvents(string $docNo, Carbon $day, int $seq): void
    {
        $snapshotId = DB::table('label_snapshots as ls')
            ->join('stock_documents as sd', 'sd.id', '=', 'ls.outbound_document_id')
            ->where('sd.doc_no', $docNo)
            ->value('ls.id');
        if ($snapshotId === null) {
            return;
        }

        // Máy in phải ACTIVE tại thời điểm ghi sự kiện (trg_erd_print_printer_active).
        $printers = DB::table('printer_configs')->where('status', 'ACTIVE')->orderBy('printer_code')->get(['id']);
        if ($printers->isEmpty()) {
            return;
        }

        // [event_type, status, error_code, error_message, reason]
        $attempts = match ($seq % 4) {
            0 => [
                ['PRINT', 'FAILED', 'PRINTER_OUT_OF_PAPER', 'Máy in báo hết giấy, lệnh in bị huỷ giữa chừng.', 'OUT_OF_PAPER'],
                ['REPRINT', 'PRINTED', null, null, null],
            ],
            1 => [
                ['PRINT', 'FAILED', 'PRINTER_OFFLINE', 'Mất kết nối tới agent in đặt tại kho tổng.', 'PRINTER_OFFLINE'],
                ['PRINT', 'FAILED', 'LABEL_TEMPLATE_INVALID', 'Mẫu nhãn không khớp khổ giấy đang nạp trên máy in.', 'TEMPLATE_MISMATCH'],
            ],
            2 => [
                ['PRINT', 'PRINTED', null, null, null],
                ['REPRINT', 'FAILED', 'PRINTER_JAMMED', 'Kẹt giấy khi in lại nhãn thùng, cần thao tác thủ công.', 'PAPER_JAM'],
            ],
            default => [
                ['PRINT', 'PRINTED', null, null, null],
            ],
        };

        foreach ($attempts as $index => [$eventType, $status, $errorCode, $errorMessage, $reason]) {
            $requestId = 'seed-rw-label-'.$snapshotId.'-'.$index;
            if (DB::table('label_print_events')->where('request_id', $requestId)->exists()) {
                continue;
            }

            $printer = $printers[($seq + $index) % $printers->count()];
            $at = $day->copy()->setTime(14, 30 + ($index * 7));
            $attemptNo = 1 + (int) DB::table('label_print_events')
                ->where('label_snapshot_id', $snapshotId)
                ->max('attempt_no');
            $actorId = $this->actorFor('outbound', $seq + $index);

            try {
                DB::table('label_print_events')->insert([
                    'id' => (string) Str::uuid(),
                    'label_snapshot_id' => $snapshotId,
                    'printer_config_id' => $printer->id,
                    'printer_id' => $printer->id,
                    'event_type' => $eventType,
                    'attempt_no' => $attemptNo,
                    'status' => $status,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'requested_at' => $at->copy()->subMinutes(2),
                    'printed_at' => $status === 'PRINTED' ? $at : null,
                    'completed_at' => in_array($status, ['PRINTED', 'FAILED'], true) ? $at : null,
                    'job_id' => 'JOB-'.strtoupper(substr(md5($requestId), 0, 10)),
                    'request_id' => $requestId,
                    'actor_id' => $actorId,
                    'metadata' => json_encode(
                        array_filter(['source' => 'seeder-recent-window', 'reason' => $reason]),
                        JSON_UNESCAPED_UNICODE
                    ),
                    'created_at' => $at,
                    'created_by' => $actorId,
                    'updated_at' => $at,
                    'updated_by' => $actorId,
                    'version' => 0,
                ]);
            } catch (Throwable $e) {
                $this->command?->warn('  nhật ký in nhãn '.$docNo.': '.$this->describe($e));
            }
        }
    }

    /**
     * Nhật ký import danh mục SKU (ngữ cảnh IMPORT của màn "Nhập liệu, đối chiếu
     * & lỗi") — mỗi 2 ngày một lô, luôn có cả dòng TRÙNG lẫn dòng LỖI để hai ô
     * thống kê "Trùng" / "Lỗi" và hai bộ lọc kết quả tương ứng đều ra dữ liệu.
     */
    private function recentImportReport(string $tag, Carbon $day, int $seq, int $back): void
    {
        if ($seq % 2 === 0 && $back > 1) {
            return;
        }
        $requestId = 'seed-rw-import-'.$tag;
        if (DB::table('import_reports')->where('request_id', $requestId)->exists()) {
            return;
        }

        [$status, $mode, $total, $duplicates, $failures] = match ($seq % 6) {
            1 => ['COMMITTED', 'COMMIT', 90, 4, 3],
            3 => ['REJECTED', 'VALIDATE', 45, 9, 8],
            default => ['VALID', 'VALIDATE', 60, 3, 2],
        };

        $rowErrors = [];
        $row = 2;
        for ($d = 0; $d < $duplicates; $d++, $row++) {
            $rowErrors[] = [
                'row' => $row,
                'code' => 'SKU_CODE_DUPLICATE',
                'message' => 'Mã SKU đã tồn tại trong hệ thống, dòng bị bỏ qua.',
                'field' => 'sku_code',
            ];
        }
        $failureCodes = [
            ['MISSING_REQUIRED_FIELD', 'Thiếu trường bắt buộc "Tên SKU".', 'sku_name'],
            ['INVALID_CATEGORY', 'Nhóm hàng không tồn tại trong danh mục.', 'category_code'],
            ['INVALID_BRAND', 'Hãng không tồn tại trong danh mục.', 'brand_code'],
            ['INVALID_UNIT', 'Đơn vị tính không hợp lệ.', 'unit'],
            ['INVALID_TRACKING_MODE', 'Cách theo dõi phải là SERIAL hoặc QUANTITY.', 'tracking_mode'],
        ];
        for ($f = 0; $f < $failures; $f++, $row++) {
            [$code, $message, $field] = $failureCodes[$f % count($failureCodes)];
            $rowErrors[] = ['row' => $row, 'code' => $code, 'message' => $message, 'field' => $field];
        }

        $errorRows = $duplicates + $failures;
        $validRows = $total - $errorRows;
        $inserted = $mode === 'COMMIT' ? (int) round($validRows * 0.8) : 0;
        $at = $day->copy()->setTime(10, 20);

        DB::table('import_reports')->insert([
            'import_report_id' => (string) Str::uuid(),
            'import_type' => 'SKU',
            'mode' => $mode,
            'status' => $status,
            'file_name' => 'danh-muc-sku-'.$tag.'.xlsx',
            'file_sha256' => hash('sha256', $requestId),
            'template_version' => 'v1',
            'total_rows' => $total,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'inserted_rows' => $inserted,
            'updated_rows' => $mode === 'COMMIT' ? $validRows - $inserted : 0,
            'row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE),
            'actor_id' => $this->actorFor('inbound', $seq),
            'request_id' => $requestId,
            'options_fingerprint' => hash('sha256', 'seed-rw-options-'.$tag),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Lô toa xuất (ngữ cảnh MATCH của cùng màn) — mỗi 2 ngày một lô.
     *
     * Bộ dữ liệu cũ chỉ sinh `SKU_NOT_FOUND` và `QTY_EXCEEDS_STOCK`, KHÔNG có mã
     * lỗi nào mang nghĩa "trùng", nên ô "Trùng" của ngữ cảnh MATCH luôn bằng 0
     * bất kể kỳ đang xem (FE nhận diện trùng qua mã chứa DUPLICATE /
     * ALREADY_EXISTS / ALREADY_MATCHED). Ở đây bổ sung đúng hai mã đó.
     */
    private function recentMatchImport(string $tag, Carbon $day, int $seq, int $back): void
    {
        if ($seq % 2 === 1 && $back > 1) {
            return;
        }
        $importNo = 'IMP-RW-'.$tag;
        if (DB::table('outbound_request_imports')->where('import_no', $importNo)->exists()) {
            return;
        }

        $skuCodes = DB::table('skus')->where('status', 'ACTIVE')->orderBy('sku_code')->limit(40)->pluck('sku_code');
        if ($skuCodes->isEmpty()) {
            return;
        }

        // [validation_status, error_code, error_message, error_field, số dòng]
        $mix = [
            ['VALID', null, null, null, 6 + ($seq % 4)],
            ['WARNING', 'QTY_EXCEEDS_STOCK', 'Số lượng yêu cầu lớn hơn tồn khả dụng hiện tại.', 'required_qty', 2],
            ['ERROR', 'SKU_NOT_FOUND', 'Mã SKU không tồn tại trong danh mục.', 'sku_code', 2 + ($seq % 3)],
            ['ERROR', 'SKU_ALREADY_MATCHED', 'Dòng toa xuất này đã được đối chiếu ở lô nhập trước, bỏ qua.', 'request_no', 2],
            ['ERROR', 'DUPLICATE_REQUEST_NO', 'Số toa xuất bị lặp trong cùng một tệp Excel.', 'request_no', 1 + ($seq % 2)],
        ];

        $status = match ($seq % 6) {
            0 => 'CONSUMED',
            2 => 'VALIDATED',
            default => 'REJECTED',
        };
        $at = $day->copy()->setTime(8, 50);
        $total = array_sum(array_column($mix, 4));
        $valid = $mix[0][4];
        $importId = (string) Str::uuid();
        $actorId = $this->actorFor('outbound', $seq);

        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => $importNo,
            'source_file_name' => 'toa-xuat-'.$tag.'.xlsx',
            'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
            'source_checksum' => hash('sha256', $importNo),
            'status' => $status,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => $total - $valid - $mix[1][4],
            'uploaded_at' => $at,
            'uploaded_by' => $actorId,
            'validated_at' => $at->copy()->addMinutes(12),
            'consumed_at' => $status === 'CONSUMED' ? $at->copy()->addHours(2) : null,
            'consumed_by' => $status === 'CONSUMED' ? $actorId : null,
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $rowNo = 0;
        foreach ($mix as [$validationStatus, $errorCode, $errorMessage, $errorField, $count]) {
            for ($n = 0; $n < $count; $n++) {
                $rowNo++;
                $skuCode = (string) $skuCodes[($rowNo + $seq) % $skuCodes->count()];
                $recipient = $this->recipients[$rowNo % max(1, count($this->recipients))] ?? [];
                $requiredQty = 1 + (($rowNo + $seq) % 12);
                // Dòng trùng cố ý lặp lại số toa của dòng đầu tiên để người đọc
                // nhìn ra ngay vì sao nó bị đánh dấu trùng.
                $requestNo = in_array($errorCode, ['SKU_ALREADY_MATCHED', 'DUPLICATE_REQUEST_NO'], true)
                    ? $importNo.'-R001'
                    : $importNo.'-R'.sprintf('%03d', $rowNo);

                DB::table('outbound_request_import_rows')->insert([
                    'id' => (string) Str::uuid(),
                    'import_id' => $importId,
                    'row_no' => $rowNo,
                    'request_no' => $requestNo,
                    'customer_name' => $recipient['name'] ?? 'Khách lẻ',
                    'customer_address' => $recipient['address'] ?? 'Việt Nam',
                    'sku_code' => $errorCode === 'SKU_NOT_FOUND' ? 'SKU-KHONG-TON-TAI-'.$rowNo : $skuCode,
                    'product_name_raw' => 'Dòng '.$rowNo.' — '.$skuCode,
                    'required_qty' => $requiredQty,
                    'note' => 'Dòng nhập từ tệp Excel '.$importNo.'.',
                    'validation_status' => $validationStatus,
                    'error_field' => $errorField,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'raw_payload' => json_encode(['sku_code' => $skuCode, 'qty' => $requiredQty, 'request_no' => $requestNo], JSON_UNESCAPED_UNICODE),
                    // AlignedImportMatchReportController đọc sku_code/required_qty/
                    // request_no TỪ normalized_payload (không đọc cột), nên payload
                    // phải mang đủ ba khoá này.
                    'normalized_payload' => json_encode(['sku_code' => $skuCode, 'required_qty' => $requiredQty, 'request_no' => $requestNo], JSON_UNESCAPED_UNICODE),
                    // Cột "Mã lỗi" của RPT-04 lấy `validation_errors[0].error_code`
                    // chứ không lấy cột `error_code`; thiếu khoá này thì ô "Trùng"
                    // của ngữ cảnh Đối chiếu luôn bằng 0 dù dòng đã đánh dấu lỗi.
                    'validation_errors' => $errorCode === null
                        ? json_encode([], JSON_UNESCAPED_UNICODE)
                        : json_encode([['code' => $errorCode, 'error_code' => $errorCode, 'message' => $errorMessage, 'field' => $errorField]], JSON_UNESCAPED_UNICODE),
                    'created_at' => $at,
                ]);
            }
        }
    }

    /**
     * Điều chỉnh tồn kho KHÔNG truy được chứng từ gốc ("kiểm kê tồn đầu kỳ").
     *
     * KPI "Ảnh hưởng hoàn tác (+/−)" của RPT-XNT-01 chính là `reversal_unlinked_net`
     * = phần REVERSAL mà `resolveCorrectionRootTypes()` không quy được về
     * RECEIPT / ISSUE / COMPONENT_ISSUE — theo mô tả contract là "dữ liệu
     * migrate/OPENING". Mọi reversal đi qua CurrentBaselineReversalService đều
     * gắn `original_movement_id` trỏ về đúng ba loại trên, mà service KHÔNG cho
     * đảo movement OPENING (REVERSIBLE_MOVEMENT_TYPES), nên nếu chỉ dùng service
     * thì KPI này bằng 0 vĩnh viễn và ô đó không bao giờ có dữ liệu.
     *
     * Vì vậy đây là chỗ DUY NHẤT trong seeder ghi thẳng sổ cái. Ba ràng buộc
     * được giữ nguyên để không đẻ chênh lệch giả ở RPT-INV-01:
     *  1. ghi `inventory_balances` và `stock_movements` trong CÙNG transaction,
     *     đúng thứ tự và đúng công thức của CurrentBaselineStockService::
     *     mutateBalance() ⇒ sổ cái = sổ tồn, phép kiểm `ledger_to_balance` vẫn MATCH;
     *  2. chỉ chọn SKU linh kiện theo SỐ LƯỢNG ⇒ không có sổ phụ serial để lệch
     *     (`serial_subledger` = NOT_APPLICABLE);
     *  3. chỉ điều chỉnh TĂNG tồn ⇒ `quantity_subledger` (box_qty <= on_hand) và
     *     `container_integrity` không thể chuyển sang MISMATCH.
     *
     * KHÔNG lọc theo stock_status: tuỳ thứ tự seeder, bucket AVAILABLE của nhóm
     * SKU này có thể đã bị seedStockStatusSpread() chuyển hết sang DEFECTIVE /
     * QUARANTINE / NON_SELLABLE, lọc cứng AVAILABLE thì pool rỗng và KPI lại về 0.
     * `available_qty` chỉ cộng thêm khi bucket đúng là AVAILABLE — đúng công thức
     * của mutateBalance().
     */
    private function recentOpeningAdjustment(string $tag, Carbon $day, int $seq): void
    {
        $businessKey = 'REVERSAL|OPENING-ADJ|'.$tag;
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            return;
        }

        $opening = DB::table('stock_movements as m')
            ->join('skus as s', 's.id', '=', 'm.product_id')
            ->where('m.movement_type', 'OPENING')
            ->where('m.warehouse_id', $this->warehouseId)
            ->where('s.sku_type', 'COMPONENT')
            ->where('s.tracking_mode', 'QUANTITY')
            ->orderBy('m.id')
            ->skip($seq % 8)
            ->first(['m.id', 'm.product_id', 'm.warehouse_id', 'm.stock_status']);
        if ($opening === null) {
            return;
        }

        $qty = 2 + ($seq % 4);
        $actorId = $this->actorFor('inbound', $seq + 3);
        $at = $day->copy()->setTime(17, 20);

        try {
            DB::transaction(function () use ($opening, $qty, $actorId, $at, $businessKey): void {
                $balance = DB::table('inventory_balances')
                    ->where('warehouse_id', $opening->warehouse_id)
                    ->where(fn ($q) => $q->where('sku_id', $opening->product_id)->orWhere('product_id', $opening->product_id))
                    ->where('stock_status', $opening->stock_status)
                    ->lockForUpdate()
                    ->first();
                if ($balance === null) {
                    return;
                }

                $before = (int) $balance->quantity;
                $after = $before + $qty;
                DB::table('inventory_balances')->where('id', $balance->id)->update([
                    'quantity' => $after,
                    'on_hand_qty' => $after,
                    'available_qty' => $opening->stock_status === 'AVAILABLE'
                        ? (int) $balance->available_qty + $qty
                        : (int) $balance->available_qty,
                    'version' => (int) $balance->version + 1,
                    'updated_at' => $at,
                ]);

                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(),
                    'movement_no' => 'MV-'.$at->format('Ymd').'-'.Str::upper(Str::random(8)),
                    'business_key' => $businessKey,
                    'movement_type' => 'REVERSAL',
                    'warehouse_id' => $opening->warehouse_id,
                    'product_id' => $opening->product_id,
                    'sku_id' => $opening->product_id,
                    'stock_status' => $opening->stock_status,
                    'quantity' => $qty,
                    'qty_before' => $before,
                    'qty_delta' => $qty,
                    'qty_after' => $after,
                    'source_type' => 'OPENING_ADJUSTMENT',
                    'original_movement_id' => $opening->id,
                    'correction_chain_id' => (string) Str::uuid(),
                    'created_by' => $actorId,
                    'occurred_at' => $at,
                    'created_at' => $at,
                ]);
            }, 3);
        } catch (Throwable $e) {
            $this->command?->warn('  điều chỉnh tồn đầu kỳ '.$tag.': '.$this->describe($e));
        }
    }

    private function quarterOf(Carbon $day): int
    {
        return (int) ceil($day->month / 3);
    }
}
