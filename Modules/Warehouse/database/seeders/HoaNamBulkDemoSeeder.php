<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\StockDocumentService;

/**
 * Dữ liệu DEMO KHỐI LƯỢNG LỚN cho "Báo cáo truy vết hàng hoá" (TabTraceability) và
 * toàn bộ báo cáo vận hành — yêu cầu người dùng 2026-08-31: cần dữ liệu THẬT, trực
 * quan, trải đều tháng 7 & 8/2026, đủ MỌI trạng thái state-machine, mọi cột FE đều
 * có dữ liệu (không cột nào rỗng), map ~99% với catalog máy nông nghiệp thật.
 *
 * NGUYÊN TẮC (giống OperationalReportRangeSeeder — cố ý tách riêng, guard idempotency
 * độc lập):
 *  - Mọi hiệu ứng tồn kho đi qua CurrentBaselineStockService / ComponentContainer-
 *    SubledgerService / reverseStockDocument (KHÔNG ghi thẳng inventory_balances /
 *    stock_movements, trừ OPENING là bút toán tồn-đầu-kỳ chỉ có đường nạp trực tiếp).
 *  - Backdate bằng Carbon::setTestNow() bọc quanh từng thao tác (service hardcode
 *    now()), KHÔNG UPDATE ngược cột ngày sau khi POSTED (trigger immutable chặn).
 *  - Idempotent theo khoá tự nhiên (sku_code / doc_no / case_code) — chạy lại resume.
 *
 * Cấu hình qua ENV (để pilot nhỏ rồi scale):
 *  - HN_BULK_ITEMS          tổng số hiện vật serial (mặc định 200; full = 20000)
 *  - HN_BULK_SKUS_PER_CAT   số SKU mỗi category (mặc định 100)
 *  - HN_BULK_RECEIPT_BATCH  số serial mỗi phiếu nhập (mặc định 25)
 *  - HN_BULK_ISSUE_BATCH    số hiện vật mỗi phiếu xuất/component-issue (mặc định 25)
 *
 * Phân bổ (70% sản phẩm / 30% linh kiện; ~5:3 tháng 8:tháng 7):
 *  - Sản phẩm (PRODUCT, serial): ~30% tồn IN_STOCK, ~55% xuất bán ISSUED,
 *    ~5% IN_WARRANTY, ~5% RETURNED_TO_CUSTOMER, phần nhỏ ADJUSTED_OUT/LOST/NEW.
 *  - Linh kiện (COMPONENT, serial): ~40% tồn, ~55% COMPONENT_ISSUE bảo hành,
 *    phần nhỏ ADJUSTED_OUT.
 */
class HoaNamBulkDemoSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const START = '2026-07-01';

    private const END = '2026-08-31';

    /** Category machinery (PRODUCT) + phụ tùng (COMPONENT). */
    private const PRODUCT_CATEGORIES = ['PUMP', 'ENGINE', 'HARVESTER', 'TRACTOR'];

    private const COMPONENT_CATEGORY = 'SPARE_PART';

    private const BRANDS = ['KUBOTA', 'YANMAR', 'HONDA', 'HUSQVARNA', 'HOANAM'];

    private const POWER_BY_CATEGORY = [
        'PUMP' => ['GASOLINE', 'DIESEL', 'SOLAR', 'MAINS_DIRECT'],
        'ENGINE' => ['DIESEL', 'GASOLINE'],
        'HARVESTER' => ['DIESEL'],
        'TRACTOR' => ['DIESEL'],
    ];

    private const AGENCIES = [
        'AG-001' => ['Đại lý Hoa Nam Hà Nội', 'Số 10, Cầu Giấy, Hà Nội', 'MIEN_BAC', 'HA_NOI'],
        'AG-002' => ['Đại lý Hoa Nam Đà Nẵng', 'Số 5, Hải Châu, Đà Nẵng', 'MIEN_TRUNG', 'DA_NANG'],
        'AG-003' => ['Đại lý Hoa Nam Hồ Chí Minh', 'Số 1, Quận 7, TP.HCM', 'MIEN_NAM', 'HO_CHI_MINH'],
        'AG-004' => ['Đại lý Hoa Nam Hải Phòng', 'Số 3, Lê Chân, Hải Phòng', 'MIEN_BAC', 'HAI_PHONG'],
        'AG-005' => ['Đại lý Hoa Nam Cần Thơ', 'Số 8, Ninh Kiều, Cần Thơ', 'MIEN_NAM', 'CAN_THO'],
    ];

    private const WARRANTY_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    private const PART_TYPES = [
        'Bộ lọc dầu', 'Bộ lọc nhiên liệu', 'Dây curoa', 'Bơm nhiên liệu', 'Gioăng nắp máy',
        'Bạc đạn trục cơ', 'Piston + xéc-măng', 'Bugi sấy', 'Kim phun', 'Van hằng nhiệt',
    ];

    private const CONDITION_CYCLE = ['DEFECTIVE', 'NON_SELLABLE', 'UNKNOWN'];

    private CurrentBaselineStockService $stock;

    private string $actorId;

    private string $warehouseId;

    private int $seq = 0;

    /** @var array<string,string> code_type FK cache */
    private array $ref = [];

    public function run(): void
    {
        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            $this->command?->warn('HoaNamBulkDemoSeeder: thiếu kho/user seed; chạy Warehouse*/Auth*Seeder trước.');

            return;
        }

        $this->stock = app(CurrentBaselineStockService::class);
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

        $totalItems = (int) (getenv('HN_BULK_ITEMS') ?: 200);
        $skusPerCat = (int) (getenv('HN_BULK_SKUS_PER_CAT') ?: 100);
        $receiptBatch = max(1, (int) (getenv('HN_BULK_RECEIPT_BATCH') ?: 25));
        $issueBatch = max(1, (int) (getenv('HN_BULK_ISSUE_BATCH') ?: 25));

        $this->command?->info("HoaNamBulkDemoSeeder: bắt đầu — items={$totalItems}, sku/cat={$skusPerCat}, receiptBatch={$receiptBatch}.");

        $this->backfillAgencyMarketRegion();
        [$productSkus, $componentSkus] = $this->ensureCatalog($skusPerCat);
        $this->command?->info('HoaNamBulkDemoSeeder: catalog sẵn sàng — '.count($productSkus).' SKU sản phẩm, '.count($componentSkus).' SKU linh kiện.');

        // Tồn đầu kỳ (OPENING) 01/07 cho vài SKU — trạng thái "Tồn đầu kỳ".
        $this->seedOpeningBalances(array_slice($productSkus, 0, 3), array_slice($componentSkus, 0, 2));

        // ── Sinh hiện vật (RECEIPT) trải tháng 7 & 8 ──────────────────────
        $productTarget = (int) round($totalItems * 0.70);
        $componentTarget = $totalItems - $productTarget;

        $days = $this->businessDays();
        // 5:3 August:July — dồn nhiều hơn vào tháng 8.
        $productItems = $this->seedReceipts($productSkus, $productTarget, 'PN-BULK-P', $receiptBatch, $days, 'PRODUCT');
        $componentItems = $this->seedReceipts($componentSkus, $componentTarget, 'PN-BULK-C', $receiptBatch, $days, 'COMPONENT');
        $this->command?->info('HoaNamBulkDemoSeeder: đã nhập '.count($productItems).' sản phẩm + '.count($componentItems).' linh kiện (IN_STOCK).');

        // ── Xuất bán sản phẩm (ISSUE) ────────────────────────────────────
        // ~55% sản phẩm xuất bán; giữ ~30% tồn; ~15% để dành warranty/điều chỉnh.
        $pSell = (int) round(count($productItems) * 0.55);
        $sold = array_splice($productItems, 0, $pSell);
        $this->seedIssues($sold, $issueBatch);
        $this->command?->info('HoaNamBulkDemoSeeder: đã xuất bán '.count($sold).' sản phẩm (ISSUED + phiếu xuất có nơi nhận).');

        // ── Bảo hành (warranty) trên sản phẩm đã ISSUED ──────────────────
        // Cần item đã ISSUED → dùng ngay lô vừa bán.
        $warrantyProducts = array_splice($sold, 0, min(count($sold), (int) round(count($sold) * 0.18)));
        $warrantyCaseIds = $this->seedWarrantyCases($warrantyProducts);

        // ── COMPONENT_ISSUE bảo hành (linh kiện serial) ──────────────────
        $cIssue = (int) round(count($componentItems) * 0.55);
        $issuedComponents = array_splice($componentItems, 0, $cIssue);
        $this->seedComponentIssues($issuedComponents, $warrantyCaseIds, $issueBatch);
        $this->command?->info('HoaNamBulkDemoSeeder: đã xuất linh kiện bảo hành '.count($issuedComponents).' hiện vật (COMPONENT_ISSUE).');

        // ── Reversal (ADJUSTED_OUT + movement REVERSAL) ──────────────────
        // Dùng phiếu nhập RIÊNG 1-item để đảo sạch (đảo phiếu nhập chung sẽ vướng
        // guard DOWNSTREAM_DEPENDENCY khi có item đã Issue).
        $this->seedReversals(max(2, (int) round($totalItems * 0.01)), $productSkus, 'REVP');
        $this->seedReversals(max(1, (int) round($totalItems * 0.005)), $componentSkus, 'REVC');

        // ── Trạng thái terminal/điều kiện (demo-only, cập nhật trực tiếp) ──
        $this->markItems(array_splice($productItems, 0, min(count($productItems), max(1, (int) round($totalItems * 0.01)))), 'LOST', null);
        foreach (self::CONDITION_CYCLE as $i => $cond) {
            $batch = array_splice($componentItems, 0, min(count($componentItems), 3));
            if ($batch === []) {
                $batch = array_splice($productItems, 0, min(count($productItems), 3));
            }
            $this->markItems($batch, null, $cond);
        }

        // ── Phiếu ở các trạng thái DRAFT / SCANNING / CANCELLED ──────────
        $this->seedDocumentStateVariants($productSkus[0], $componentSkus[0]);

        // ── Vài item NEW (candidate chưa putaway) cho đủ enum ────────────
        $this->seedNewCandidates($productSkus[0]);

        $this->command?->info('HoaNamBulkDemoSeeder: HOÀN TẤT — dữ liệu demo khối lượng lớn tháng 7 & 8/2026 đã sẵn sàng.');
    }

    // ==================================================================
    //  CATALOG
    // ==================================================================

    /** @return array{0:list<string>,1:list<string>} [productSkuIds, componentSkuIds] */
    private function ensureCatalog(int $perCategory): array
    {
        $productSkus = [];
        $componentSkus = [];

        foreach (self::PRODUCT_CATEGORIES as $categoryCode) {
            for ($i = 1; $i <= $perCategory; $i++) {
                $brand = self::BRANDS[$i % count(self::BRANDS)];
                $powers = self::POWER_BY_CATEGORY[$categoryCode];
                $power = $powers[$i % count($powers)];
                [$code, $name] = $this->productNaming($categoryCode, $brand, $i);
                $productSkus[] = $this->ensureSku($code, $name, 'PRODUCT', true, $brand, $categoryCode, $this->modelForBrand($brand), $power, $i % 2 === 0 ? 'KIT' : 'BARE');
            }
        }

        for ($i = 1; $i <= $perCategory; $i++) {
            $brand = self::BRANDS[$i % count(self::BRANDS)];
            $part = self::PART_TYPES[$i % count(self::PART_TYPES)];
            $code = 'SPR-'.substr($brand, 0, 3).'-'.sprintf('%04d', $i);
            $name = $part.' '.self::brandName($brand).' — mã '.sprintf('%04d', $i);
            $componentSkus[] = $this->ensureSku($code, $name, 'COMPONENT', true, $brand, self::COMPONENT_CATEGORY, $this->modelForBrand($brand), null, 'BARE');
        }

        return [$productSkus, $componentSkus];
    }

    /** @return array{0:string,1:string} [code, name] */
    private function productNaming(string $categoryCode, string $brand, int $i): array
    {
        $b3 = substr($brand, 0, 3);
        $bn = self::brandName($brand);
        $hp = [3, 5, 8, 10, 12, 15, 20, 24][$i % 8];
        $series = strtoupper(Str::random(0)).chr(65 + ($i % 6)).sprintf('%02d', ($i % 90) + 10);

        return match ($categoryCode) {
            'PUMP' => ['PUMP-'.$b3.'-'.sprintf('%04d', $i), 'Máy bơm nước '.$bn.' '.$series.' '.$hp.'HP'],
            'ENGINE' => ['ENG-'.$b3.'-'.sprintf('%04d', $i), 'Động cơ '.$bn.' '.$series.' '.$hp.'HP'],
            'HARVESTER' => ['HARV-'.$b3.'-'.sprintf('%04d', $i), 'Máy gặt đập liên hợp '.$bn.' '.$series],
            'TRACTOR' => ['TRAC-'.$b3.'-'.sprintf('%04d', $i), 'Máy kéo '.$bn.' '.$series.' '.$hp.'HP'],
            default => ['SKU-'.$b3.'-'.sprintf('%04d', $i), 'Sản phẩm '.$bn.' '.$series],
        };
    }

    private function modelForBrand(string $brand): string
    {
        return [
            'KUBOTA' => 'MDL-KB-01', 'YANMAR' => 'MDL-YM-01', 'HONDA' => 'MDL-HD-01',
            'HUSQVARNA' => 'MDL-HQ-01', 'HOANAM' => 'MDL-HN-01',
        ][$brand] ?? 'MDL-HN-01';
    }

    private static function brandName(string $brand): string
    {
        return ['HOANAM' => 'Hoa Nam'][$brand] ?? ucfirst(strtolower($brand));
    }

    private function ensureSku(
        string $code,
        string $name,
        string $type,
        bool $serialTracked,
        ?string $brandCode,
        ?string $categoryCode,
        ?string $modelCode,
        ?string $powerCode,
        ?string $packageCode,
    ): string {
        $existing = Sku::query()->where('sku_code', $code)->value('sku_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $sku = Sku::query()->create([
            'sku_code' => $code,
            'sku_name' => mb_substr($name, 0, 200),
            'sku_type' => $type,
            'unit' => 'cái',
            'is_serial_tracked' => $serialTracked,
            'brand_id' => $this->refId('brands', 'brand_code', $brandCode),
            'category_id' => $this->refId('product_categories', 'category_code', $categoryCode),
            'model_id' => $this->refId('product_models', 'model_code', $modelCode),
            'power_source_id' => $this->refId('power_sources', 'power_source_code', $powerCode),
            'package_variant_id' => $this->refId('package_variants', 'package_code', $packageCode),
            'version_code' => 'V1',
            'warranty_applicable' => $type === 'PRODUCT',
            'published_to_app' => true,
            'status' => 'ACTIVE',
        ]);

        return (string) $sku->sku_id;
    }

    private function refId(string $table, string $col, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $k = $table.':'.$code;
        if (! array_key_exists($k, $this->ref)) {
            $idCol = $table === 'brands' || $table === 'product_categories' || $table === 'product_models'
                || $table === 'power_sources' || $table === 'package_variants' ? 'id' : 'id';
            $this->ref[$k] = DB::table($table)->where($col, $code)->value($idCol);
        }

        return $this->ref[$k] !== null ? (string) $this->ref[$k] : null;
    }

    // ==================================================================
    //  OPENING BALANCE (tồn đầu kỳ)
    // ==================================================================

    /**
     * @param  list<string>  $productSkus
     * @param  list<string>  $componentSkus
     */
    private function seedOpeningBalances(array $productSkus, array $componentSkus): void
    {
        $date = Carbon::parse(self::START)->setTime(0, 5, 0);
        Carbon::setTestNow($date);
        try {
            foreach (array_merge($productSkus, $componentSkus) as $idx => $skuId) {
                $qty = 20 + ($idx * 5);
                $businessKey = 'OPENING|BULK|'.$this->warehouseId.'|'.$skuId.'|AVAILABLE';
                if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
                    continue;
                }
                $balance = DB::table('inventory_balances')
                    ->where('warehouse_id', $this->warehouseId)
                    ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                    ->where('stock_status', 'AVAILABLE')->first();
                $before = $balance ? (int) $balance->quantity : 0;
                $after = $before + $qty;
                if ($balance === null) {
                    DB::table('inventory_balances')->insert([
                        'id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouseId,
                        'product_id' => $skuId, 'sku_id' => $skuId, 'stock_status' => 'AVAILABLE',
                        'quantity' => $after, 'on_hand_qty' => $after, 'available_qty' => $after,
                        'reserved_qty' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('inventory_balances')->where('id', $balance->id)->update([
                        'quantity' => $after, 'on_hand_qty' => $after, 'available_qty' => (int) $balance->available_qty + $qty,
                        'version' => (int) $balance->version + 1, 'updated_at' => now(),
                    ]);
                }
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(),
                    'movement_no' => 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                    'business_key' => $businessKey,
                    'movement_type' => 'OPENING',
                    'warehouse_id' => $this->warehouseId,
                    'product_id' => $skuId, 'sku_id' => $skuId, 'stock_status' => 'AVAILABLE',
                    'quantity' => $qty, 'qty_before' => $before, 'qty_delta' => $qty, 'qty_after' => $after,
                    'stock_document_id' => null, 'stock_document_line_id' => null,
                    'source_type' => 'OPENING_BALANCE', 'source_id' => null, 'source_line_id' => null,
                    'item_id' => null, 'original_movement_id' => null, 'correction_chain_id' => null,
                    'created_by' => $this->actorId, 'occurred_at' => now(), 'created_at' => now(),
                ]);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================================================================
    //  RECEIPTS (nhập kho → IN_STOCK)
    // ==================================================================

    /**
     * @param  list<string>  $skus
     * @param  list<Carbon>  $days
     * @return list<string> item ids IN_STOCK
     */
    private function seedReceipts(array $skus, int $target, string $prefix, int $batch, array $days, string $kind): array
    {
        $items = [];
        $skuCount = count($skus);
        $created = 0;
        $dayCount = count($days);
        $docIndex = 0;
        while ($created < $target) {
            $take = min($batch, $target - $created);
            // Ngày: nghiêng về tháng 8 (index cao) — dùng phân phối lệch.
            $day = $days[$this->skewedDayIndex($docIndex, $dayCount)];
            $skuId = $skus[$docIndex % $skuCount];
            $docNo = $prefix.'-'.sprintf('%05d', $docIndex + 1);
            $docIndex++;

            $ids = $this->receiptBatch($docNo, $skuId, $take, $day);
            foreach ($ids as $id) {
                $items[] = $id;
            }
            $created += $take;

            if ($docIndex % 25 === 0) {
                $this->command?->info("  {$prefix}: {$created}/{$target} hiện vật ({$docIndex} phiếu)...");
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function receiptBatch(string $docNo, string $skuId, int $qty, Carbon $day): array
    {
        // Resume: phiếu đã tồn tại (POSTED) → nạp lại item ids theo serial.
        $serials = [];
        for ($s = 1; $s <= $qty; $s++) {
            $serials[] = 'SN-'.$docNo.'-'.sprintf('%03d', $s);
        }

        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return array_values(array_filter(array_map(
                fn (string $sn) => DB::table('item_instances')->where('serial_number', $sn)->value('item_id'),
                $serials
            )));
        }

        Carbon::setTestNow($day);
        try {
            // Mỗi serial một dòng qty=1 → movement RECEIPT mang item_id (truy vết per-serial).
            $lines = array_map(fn () => ['product_id' => $skuId, 'qty_planned' => 1], $serials);
            [$docId, $lineIds] = $this->createDocument('receipt', $docNo, $lines, [
                'source_name' => 'Nhà máy Hoa Nam — lô nhập '.$docNo,
            ]);

            $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
            foreach ($serials as $i => $serial) {
                $this->stock->scanStockDocument($docId, 'receipt', [
                    'line_id' => $lineIds[$i],
                    'quantity' => 1,
                    'code_value' => "HN1|SKU={$skuCode}|ITEM={$serial}",
                ], $this->actorId);
            }
            $this->postDocument($docId, 'receipt');
        } finally {
            Carbon::setTestNow();
        }

        return array_values(array_filter(array_map(
            fn (string $sn) => DB::table('item_instances')->where('serial_number', $sn)->value('item_id'),
            $serials
        )));
    }

    // ==================================================================
    //  ISSUES (xuất bán sản phẩm → ISSUED, có nơi nhận)
    // ==================================================================

    /** @param list<string> $items */
    private function seedIssues(array $items, int $batch): void
    {
        // Mỗi phiếu xuất/dòng chỉ 1 SKU → gom theo SKU trước, rồi chia lô.
        $bySku = [];
        foreach ($items as $itemId) {
            $skuId = (string) DB::table('item_instances')->where('item_id', $itemId)->value('sku_id');
            $bySku[$skuId][] = $itemId;
        }

        // Đánh số tiếp SAU các phiếu PX-BULK đã có (tránh trùng số với phiếu cũ →
        // chunk bị skip oan khiến item không được xuất). Số mới luôn tự do.
        $docSeq = $this->maxDocSuffix('PX-BULK-');
        foreach ($bySku as $skuId => $skuItems) {
            foreach (array_chunk($skuItems, $batch) as $chunk) {
                $docSeq++;
                $docNo = 'PX-BULK-'.sprintf('%05d', $docSeq);
                // Chỉ xuất item còn IN_STOCK — mỗi item một dòng qty=1 (movement ISSUE
                // mang item_id → truy vết per-serial, có nơi nhận qua source_id).
                $eligible = array_values(array_filter($chunk, fn ($id) => DB::table('item_instances')->where('item_id', $id)->value('item_status') === 'IN_STOCK'));
                if ($eligible === []) {
                    continue;
                }
                $recv = DB::table('item_instances')->where('item_id', $eligible[0])->value('received_at');
                $day = $this->issueDay($recv, $docSeq);
                $agencyCode = array_keys(self::AGENCIES)[$docSeq % 5];
                [$agencyName, $agencyAddress] = self::AGENCIES[$agencyCode];
                $agencyId = DB::table('agencies')->where('agency_code', $agencyCode)->value('agency_id');

                Carbon::setTestNow($day);
                try {
                    $requestNo = 'TOA-BULK-'.sprintf('%05d', $docSeq);
                    $requestId = $this->createOutboundRequest($requestNo, $skuId, count($eligible), $agencyId);
                    $lines = array_map(fn () => ['product_id' => $skuId, 'qty_planned' => 1], $eligible);
                    [$docId, $lineIds] = $this->createDocument('issue', $docNo, $lines, [
                        'outbound_request_id' => $requestId,
                        'agency_id' => $agencyId,
                        'recipient_name' => $agencyName,
                        'recipient_address' => $agencyAddress,
                    ]);
                    foreach ($eligible as $i => $itemId) {
                        $this->stock->scanStockDocument($docId, 'issue', [
                            'line_id' => $lineIds[$i],
                            'quantity' => 1,
                            'item_id' => $itemId,
                        ], $this->actorId);
                    }
                    $this->markLabelPrinted($docId, $skuId);
                    $this->postDocument($docId, 'issue');
                } finally {
                    Carbon::setTestNow();
                }
            }
        }
    }

    // ==================================================================
    //  WARRANTY CASES (đủ 6 status + TEMP_ONLY/NOT_TRACEABLE)
    // ==================================================================

    /**
     * @param  list<string>  $issuedProductItems
     * @return list<string> active warranty case ids (cho component issue)
     */
    private function seedWarrantyCases(array $issuedProductItems): array
    {
        $activeCaseIds = [];
        $n = count($issuedProductItems);
        if ($n === 0) {
            return $activeCaseIds;
        }
        // Tạo nhiều case, xoay vòng đủ 6 status; ~1/6 TEMP_ONLY (CANCELLED).
        foreach ($issuedProductItems as $i => $itemId) {
            $status = self::WARRANTY_STATUSES[$i % count(self::WARRANTY_STATUSES)];
            $tempOnly = ($i % 12) === 11; // một phần nhỏ TEMP_ONLY / NOT_TRACEABLE
            $caseCode = 'WC-BULK-'.sprintf('%05d', $i + 1);
            $skuId = (string) DB::table('item_instances')->where('item_id', $itemId)->value('sku_id');
            $recv = DB::table('item_instances')->where('item_id', $itemId)->value('issued_at');
            $day = $this->issueDay($recv, $i, 2);

            $caseId = $this->seedWarrantyCaseVariant(
                $caseCode, $status,
                $tempOnly ? null : $itemId,
                $skuId, $tempOnly, $day
            );
            if (! in_array($status, ['RETURNED', 'CANCELLED'], true)) {
                $activeCaseIds[] = $caseId;
            }
        }

        return $activeCaseIds;
    }

    private function seedWarrantyCaseVariant(string $caseCode, string $status, ?string $itemId, ?string $skuId, bool $tempOnly, Carbon $day): string
    {
        $existing = DB::table('warranty_case')->where('warranty_case_code', $caseCode)->value('warranty_case_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        Carbon::setTestNow($day);
        try {
            $caseId = (string) Str::uuid();
            $defectCode = 'DEF-'.sprintf('%03d', ((crc32($caseCode) % 5) + 1));
            $defectId = DB::table('defect_catalogs')->where('defect_code', $defectCode)->value('defect_id');
            // "Kết quả kiểm tra" (confirmed_defect) chỉ có sau khi KTV mở máy — các
            // case đã qua bước CHECKING mới có, còn RECEIVED (chưa kiểm) / CANCELLED
            // để trống đúng nghiệp vụ. Nhờ vậy cột "Kết quả kiểm tra" ở màn bảo hành
            // có dữ liệu cho các trạng thái tiến xa, không còn "Chưa có" đồng loạt.
            $confirmedDefect = in_array($status, ['CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED'], true)
                ? 'KTV xác nhận lỗi ('.$defectCode.') sau khi mở máy — hồ sơ '.$caseCode.'.'
                : null;

            DB::table('warranty_case')->insert([
                'warranty_case_id' => $caseId,
                'warranty_case_code' => $caseCode,
                'item_id' => $itemId,
                'inventory_serial_id' => null,
                'product_id' => $skuId,
                'temporary_item_code' => $tempOnly ? 'TEMP-'.$caseCode : null,
                'identification_status' => $tempOnly ? 'TEMP_ONLY' : 'IDENTIFIED',
                'missing_code_reason' => $tempOnly ? 'Khách làm mất tem/mã vật lý, không tra được serial gốc.' : null,
                'manual_product_description' => $tempOnly ? 'Máy nông nghiệp Hoa Nam (mô tả thủ công do mất mã).' : null,
                'sales_trace_status' => $tempOnly ? 'NOT_TRACEABLE' : 'TRACEABLE',
                'defect_id' => $defectId,
                'customer_name' => 'Khách hàng '.$caseCode,
                'customer_phone' => '09'.substr(str_pad((string) crc32($caseCode), 8, '0', STR_PAD_LEFT), 0, 8),
                'customer_address' => 'Địa chỉ khách hàng '.$caseCode,
                'purchase_date' => now()->subMonths(2)->toDateString(),
                'status' => $status,
                'condition_status' => 'DEFECTIVE',
                'confirmed_defect' => $confirmedDefect,
                'description' => 'Hồ sơ bảo hành demo — trạng thái '.$status.'.',
                'reported_defect' => 'Khách báo lỗi cho case '.$caseCode.'.',
                'received_condition' => $tempOnly ? null : 'Nguyên vỏ, đủ phụ kiện',
                'accessories_received' => $tempOnly ? null : 'Dây nguồn, sách hướng dẫn',
                'received_at' => now(),
                'created_by' => $this->actorId,
                'version' => 1,
                'legal_hold_flag' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($itemId !== null) {
                DB::table('item_instances')->where('item_id', $itemId)->update([
                    'item_status' => in_array($status, ['COMPLETED', 'RETURNED'], true) ? 'RETURNED_TO_CUSTOMER' : 'IN_WARRANTY',
                    'updated_at' => now(),
                ]);
            }

            DB::table('warranty_event')->insert([
                'warranty_event_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'event_type' => 'RECEIVE',
                'from_status' => null,
                'to_status' => 'RECEIVED',
                'note' => 'Tiếp nhận bảo hành.',
                'created_by' => $this->actorId,
                'event_at' => now(),
            ]);
            if ($status !== 'RECEIVED') {
                DB::table('warranty_event')->insert([
                    'warranty_event_id' => (string) Str::uuid(),
                    'warranty_case_id' => $caseId,
                    'event_type' => 'UPDATE_STATUS',
                    'from_status' => 'RECEIVED',
                    'to_status' => $status,
                    'note' => 'Cập nhật trạng thái sang '.$status.'.',
                    'created_by' => $this->actorId,
                    'event_at' => now(),
                ]);
            }

            AuditLogger::record(
                action: 'WARRANTY_CASE_RECEIVED',
                objectType: 'warranty_case',
                objectId: $caseId,
                actorId: $this->actorId,
                requestId: 'seed-bulk-warranty-'.strtolower($caseCode),
                afterData: ['status' => $status, 'item_id' => $itemId, 'stock_effect' => 'NONE'],
            );

            if (Schema::hasTable('warranty_case_defects') && $defectId !== null) {
                DB::table('warranty_case_defects')->insert([
                    'warranty_case_defect_id' => (string) Str::uuid(),
                    'warranty_case_id' => $caseId,
                    'defect_id' => $defectId,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $caseId;
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================================================================
    //  COMPONENT ISSUE (xuất linh kiện bảo hành, serial → item_id)
    // ==================================================================

    /**
     * @param  list<string>  $componentItems
     * @param  list<string>  $activeCaseIds
     */
    private function seedComponentIssues(array $componentItems, array $activeCaseIds, int $batch): void
    {
        if ($activeCaseIds === [] || $componentItems === []) {
            return;
        }
        $seq = $this->maxDocSuffix('CID-BULK-', 'component_issue_documents', 'component_issue_no');
        foreach (array_chunk($componentItems, $batch) as $ci => $chunk) {
            $seq++;
            $issueNo = 'CID-BULK-'.sprintf('%05d', $seq);
            $caseId = $activeCaseIds[$ci % count($activeCaseIds)];
            $recv = DB::table('item_instances')->where('item_id', $chunk[0])->value('received_at');
            $day = $this->issueDay($recv, $ci, 4);

            Carbon::setTestNow($day);
            try {
                $documentId = (string) Str::uuid();
                DB::table('component_issue_documents')->insert([
                    'id' => $documentId,
                    'component_issue_no' => $issueNo,
                    'warehouse_id' => $this->warehouseId,
                    'warranty_case_id' => $caseId,
                    'document_date' => now()->toDateString(),
                    'purpose' => 'WARRANTY',
                    'status' => 'DRAFT',
                    // Điền ngay lúc tạo: chứng từ POSTED là immutable, không vá được sau.
                    'note' => 'Xuất linh kiện thay thế cho hồ sơ bảo hành (dữ liệu demo).',
                    'created_at' => now(), 'created_by' => $this->actorId,
                    'updated_at' => now(), 'updated_by' => $this->actorId,
                    'version' => 0,
                ]);

                $lineNo = 0;
                foreach ($chunk as $itemId) {
                    $row = DB::table('item_instances')->where('item_id', $itemId)->first();
                    if ($row === null || $row->item_status !== 'IN_STOCK') {
                        continue;
                    }
                    $lineNo++;
                    $lineId = (string) Str::uuid();
                    DB::table('component_issue_lines')->insert([
                        'id' => $lineId,
                        'component_issue_document_id' => $documentId,
                        'line_no' => $lineNo,
                        'sku_id' => (string) $row->sku_id,
                        'required_qty' => 1,
                        'scanned_qty' => 1,
                        'created_at' => now(), 'created_by' => $this->actorId,
                        'updated_at' => now(), 'updated_by' => $this->actorId,
                        'version' => 0,
                    ]);
                    DB::table('component_issue_items')->insert([
                        'id' => (string) Str::uuid(),
                        'component_issue_line_id' => $lineId,
                        'item_id' => $itemId,
                        'container_id' => null,
                        'quantity' => 1,
                        'status' => 'ACTIVE',
                        'created_at' => now(), 'created_by' => $this->actorId,
                        'updated_at' => now(), 'updated_by' => $this->actorId,
                        'version' => 0,
                    ]);
                }

                if ($lineNo === 0) {
                    // Không có item hợp lệ → xoá document rỗng để không kẹt DRAFT.
                    DB::table('component_issue_documents')->where('id', $documentId)->delete();

                    continue;
                }

                AuditLogger::record(
                    action: 'CREATE_COMPONENT_ISSUE',
                    objectType: 'component_issue_document',
                    objectId: $documentId,
                    actorId: $this->actorId,
                    requestId: 'seed-bulk-'.strtolower($issueNo),
                    afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'lines' => $lineNo],
                );

                app(ComponentContainerSubledgerService::class)->consumeDocument($documentId);
                $key = 'seed-bulk-component-issue-'.$documentId;
                $this->stock->postComponentIssue($documentId, $this->actorId, $key, sha1($key));
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  REVERSAL (ADJUSTED_OUT + movement REVERSAL)
    // ==================================================================

    /** @param list<string> $skus */
    private function seedReversals(int $count, array $skus, string $prefix): void
    {
        $days = $this->businessDays();
        for ($i = 1; $i <= $count; $i++) {
            $docNo = 'PN-BULK-'.$prefix.'-'.sprintf('%04d', $i);
            $skuId = $skus[$i % count($skus)];
            $serial = 'SN-'.$docNo.'-001';
            $day = $days[($i * 9) % count($days)]->copy()->setTime(11, 0, 0);

            Carbon::setTestNow($day);
            try {
                // Phiếu nhập 1-item riêng (idempotent theo doc_no).
                if (! DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
                    [$docId, $lineIds] = $this->createDocument('receipt', $docNo, [
                        ['product_id' => $skuId, 'qty_planned' => 1],
                    ], ['source_name' => 'Lô nhập để minh hoạ điều chỉnh tồn kho']);
                    $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
                    $this->stock->scanStockDocument($docId, 'receipt', [
                        'line_id' => $lineIds[0], 'quantity' => 1,
                        'code_value' => "HN1|SKU={$skuCode}|ITEM={$serial}",
                    ], $this->actorId);
                    $this->postDocument($docId, 'receipt');
                }

                $docId = (string) DB::table('stock_documents')->where('doc_no', $docNo)->value('id');
                $movement = DB::table('stock_movements')
                    ->where('stock_document_id', $docId)
                    ->where('movement_type', 'RECEIPT')->first();
                if ($movement === null) {
                    continue;
                }
                // Đã đảo rồi thì bỏ qua (idempotent).
                $alreadyReversed = DB::table('reversal_line as rl')
                    ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
                    ->where('rl.original_movement_id', $movement->id)
                    ->where('rd.status', 'POSTED')->exists();
                if ($alreadyReversed) {
                    continue;
                }

                $key = 'seed-bulk-reversal-'.$docNo;
                $this->stock->reverseStockDocument(
                    $docId, 'receipt',
                    [
                        'reason' => 'Điều chỉnh tồn kho demo — hoàn nhập phiếu '.$docNo,
                        'condition_status' => 'SELLABLE',
                        'lines' => [['movement_id' => $movement->id, 'quantity' => 1]],
                    ],
                    $this->actorId, $key, sha1($key),
                );
            } catch (\Throwable $e) {
                $this->command?->warn('  reversal bỏ qua '.$docNo.': '.$e->getMessage());
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    // ==================================================================
    //  TERMINAL STATES (LOST / condition) — cập nhật trực tiếp (demo-only)
    // ==================================================================

    /** @param list<string> $items */
    private function markItems(array $items, ?string $status, ?string $condition): void
    {
        foreach ($items as $itemId) {
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

    // ==================================================================
    //  DOCUMENT STATE VARIANTS (DRAFT / SCANNING / CANCELLED)
    // ==================================================================

    private function seedDocumentStateVariants(string $productSku, string $componentSku): void
    {
        $day = Carbon::parse(self::END)->setTime(9, 0, 0);
        Carbon::setTestNow($day);
        try {
            // DRAFT — chỉ tạo, không scan.
            if (! DB::table('stock_documents')->where('doc_no', 'PN-BULK-DRAFT-01')->exists()) {
                $this->createDocument('receipt', 'PN-BULK-DRAFT-01', [
                    ['product_id' => $productSku, 'qty_planned' => 5],
                ], ['source_name' => 'Phiếu nháp chờ nhập kho']);
            }

            // SCANNING — scan 1 candidate, không post.
            if (! DB::table('stock_documents')->where('doc_no', 'PN-BULK-SCAN-01')->exists()) {
                [$docId, $lineIds] = $this->createDocument('receipt', 'PN-BULK-SCAN-01', [
                    ['product_id' => $productSku, 'qty_planned' => 1],
                    ['product_id' => $productSku, 'qty_planned' => 1],
                    ['product_id' => $productSku, 'qty_planned' => 1],
                ], ['source_name' => 'Phiếu đang quét dở']);
                $skuCode = (string) DB::table('sku')->where('sku_id', $productSku)->value('sku_code');
                $this->stock->scanStockDocument($docId, 'receipt', [
                    'line_id' => $lineIds[0], 'quantity' => 1,
                    'code_value' => "HN1|SKU={$skuCode}|ITEM=SN-BULK-SCAN-01-001",
                ], $this->actorId);
            }

            // CANCELLED — tạo DRAFT rồi cancel qua service.
            if (! DB::table('stock_documents')->where('doc_no', 'PN-BULK-CANCEL-01')->exists()) {
                [$docId] = $this->createDocument('receipt', 'PN-BULK-CANCEL-01', [
                    ['product_id' => $componentSku, 'qty_planned' => 4],
                ], ['source_name' => 'Phiếu bị huỷ demo']);
                $doc = StockDocument::query()->find($docId);
                if ($doc !== null) {
                    app(StockDocumentService::class)
                        ->cancel($doc, $this->actorId, 'Huỷ phiếu demo — nhập nhầm nhà cung cấp.');
                }
            }
        } catch (\Throwable $e) {
            $this->command?->warn('  document-state-variants: '.$e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================================================================
    //  NEW candidates (item chưa putaway) — đủ enum NEW
    // ==================================================================

    private function seedNewCandidates(string $productSku): void
    {
        $day = Carbon::parse(self::END)->setTime(10, 0, 0);
        Carbon::setTestNow($day);
        try {
            for ($i = 1; $i <= 3; $i++) {
                $serial = 'SN-BULK-NEW-'.sprintf('%03d', $i);
                if (DB::table('item_instances')->where('serial_number', $serial)->exists()) {
                    continue;
                }
                $itemId = (string) Str::uuid();
                $pcId = (string) Str::uuid();
                // item_instances trước (physical_codes.item_id FK trỏ về item), sau
                // đó mới ghi physical_codes rồi link ngược physical_code_id.
                DB::table('item_instances')->insert([
                    'item_id' => $itemId,
                    'id' => $itemId,
                    'item_code' => $serial,
                    'sku_id' => $productSku,
                    'physical_code_id' => null,
                    'serial_number' => $serial,
                    'item_status' => 'NEW', 'status' => 'NEW',
                    'item_condition' => 'NORMAL', 'condition' => 'NORMAL',
                    'version' => 1,
                    'received_at' => now(),
                    'created_by' => $this->actorId, 'updated_by' => $this->actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('physical_codes')->insert([
                    'physical_code_id' => $pcId,
                    'code_value' => 'HN1|SKU='.DB::table('sku')->where('sku_id', $productSku)->value('sku_code').'|ITEM='.$serial,
                    'code_type' => 'ITEM', 'carrier_type' => 'QR',
                    'status' => 'ACTIVE', 'assigned_object_type' => 'ITEM',
                    'assigned_object_id' => $itemId, 'item_id' => $itemId, 'is_primary' => true,
                    'assigned_by' => $this->actorId, 'created_by' => $this->actorId, 'updated_by' => $this->actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('item_instances')->where('item_id', $itemId)->update(['physical_code_id' => $pcId, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            $this->command?->warn('  new-candidates: '.$e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================================================================
    //  HELPERS (shape copy từ OperationalReportRangeSeeder)
    // ==================================================================

    private function backfillAgencyMarketRegion(): void
    {
        foreach (self::AGENCIES as $code => [$name, $address, $market, $region]) {
            DB::table('agencies')->where('agency_code', $code)->whereNull('market')
                ->update(['market' => $market, 'region' => $region, 'updated_at' => now()]);
        }
    }

    /** @param list<array{product_id:string,qty_planned:int}> $lines */
    private function createDocument(string $type, string $docNo, array $lines, array $extra = []): array
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
            'note' => $extra['note'] ?? 'Dữ liệu demo khối lượng lớn (seeder)',
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
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
                'updated_by' => $this->actorId,
            ]);
        }

        AuditLogger::record(
            action: $type === 'receipt' ? 'CREATE_INBOUND' : 'CREATE_OUTBOUND',
            objectType: 'stock_document',
            objectId: $documentId,
            actorId: $this->actorId,
            requestId: 'seed-bulk-'.strtolower($docNo),
            afterData: ['doc_no' => $docNo, 'status' => 'DRAFT', 'version' => 1],
        );

        // Trả về [docId, danh sách line-id]. Truy vết per-serial cần movement mang
        // item_id → mỗi hiện vật một dòng qty=1 (resolveLineItemId chỉ gán item_id
        // khi line có đúng 1 item).
        return [$documentId, $lineIds];
    }

    private function postDocument(string $documentId, string $type): void
    {
        $version = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');
        DB::table('stock_documents')->where('id', $documentId)->update([
            'version' => $version + 1,
            'updated_by' => $this->actorId,
            'updated_at' => now(),
        ]);
        $key = 'seed-bulk-'.$type.'-'.$documentId;
        $this->stock->postStockDocument($documentId, $type, $this->actorId, $key, sha1($key));
    }

    private function createOutboundRequest(string $requestNo, string $skuId, int $qty, ?string $agencyId): string
    {
        $existing = DB::table('outbound_requests')->where('request_no', $requestNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $importId = $this->ensureImport('IMP-BULK-'.substr(md5($requestNo), 0, 8));
        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => $requestNo,
            'import_id' => $importId,
            'agency_id' => $agencyId,
            'recipient_name' => 'Đại lý Hoa Nam',
            'recipient_address' => 'Việt Nam',
            'request_date' => now()->toDateString(),
            'status' => 'OPEN',
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('outbound_request_lines')->insert([
            'id' => (string) Str::uuid(),
            'outbound_request_id' => $requestId,
            'line_no' => 1,
            'sku_id' => $skuId,
            'required_qty' => $qty,
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $requestId;
    }

    private function ensureImport(string $importNo): string
    {
        $existing = DB::table('outbound_request_imports')->where('import_no', $importNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => $importNo,
            'source_file_name' => 'toa-xuat-bulk.xlsx',
            'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
            'source_checksum' => hash('sha256', $importNo),
            'status' => 'CONSUMED',
            'total_rows' => 1, 'valid_rows' => 1, 'error_rows' => 0,
            ...(Schema::hasColumn('outbound_request_imports', 'uploaded_at') ? [
                'uploaded_at' => now(), 'uploaded_by' => $this->actorId,
            ] : []),
            'validated_at' => now(),
            'consumed_at' => now(),
            'consumed_by' => $this->actorId,
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $importId;
    }

    private function markLabelPrinted(string $documentId, string $skuId): void
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
        $insert = [
            'id' => (string) Str::uuid(),
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            // PHẢI gán ngay lúc INSERT: trigger LABEL_SNAPSHOT_IMMUTABLE chặn mọi
            // UPDATE lên sku_id/item_id sau khi nhãn đã chốt, nên không backfill
            // được về sau. Để null thì cột SKU ở màn RPT-05 trống vĩnh viễn.
            'sku_id' => $skuId, 'item_id' => $items[0] ?? null,
            'label_payload' => json_encode([
                'source' => 'seeder-bulk', 'label_code' => $labelCode, 'outbound_no' => $docNo,
                'outbound_document_id' => $documentId, 'sku_id' => $skuId, 'item_ids' => $items,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'PRINTED', 'applied_at' => now(), 'applied_by' => $this->actorId,
            'version' => 1, 'created_by' => $this->actorId, 'updated_by' => $this->actorId,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('label_snapshots', 'label_code')) {
            $insert['label_code'] = $labelCode;
        }
        DB::table('label_snapshots')->insert($insert);

        $printerId = DB::table('printer_configs')->value('id');
        if ($printerId !== null) {
            DB::table('label_print_events')->insert([
                'id' => (string) Str::uuid(),
                'label_snapshot_id' => $insert['id'],
                'printer_config_id' => $printerId,
                'status' => 'PRINTED', 'error_message' => null, 'printed_at' => now(),
                'created_at' => now(), 'created_by' => $this->actorId,
                'updated_at' => now(), 'updated_by' => $this->actorId, 'version' => 0,
            ]);
        }
    }

    // ==================================================================
    //  DATE HELPERS
    // ==================================================================

    /** Số thứ tự lớn nhất đang có của doc_no dạng {prefix}NNNNN (0 nếu chưa có). */
    private function maxDocSuffix(string $prefix, string $table = 'stock_documents', string $col = 'doc_no'): int
    {
        $max = DB::table($table)
            ->where($col, 'like', $prefix.'%')
            ->selectRaw("COALESCE(MAX(CAST(NULLIF(regexp_replace($col, '^.*[^0-9]', ''), '') AS INTEGER)), 0) AS m")
            ->value('m');

        return (int) $max;
    }

    /** @return list<Carbon> */
    private function businessDays(): array
    {
        $days = [];
        $cursor = Carbon::parse(self::START);
        $end = Carbon::parse(self::END);
        while ($cursor->lte($end)) {
            $days[] = $cursor->copy()->setTime(8, 30, 0);
            $cursor->addDay();
        }

        return $days;
    }

    /** Phân phối lệch về cuối kỳ (tháng 8) ~5:3. */
    private function skewedDayIndex(int $docIndex, int $dayCount): int
    {
        // 62 ngày: ngày 0..30 = tháng 7 (37.5%), 31..61 = tháng 8 (62.5%).
        $julyDays = 31;
        $inAugust = ($docIndex % 8) >= 3; // 5/8 vào tháng 8
        if ($inAugust && $dayCount > $julyDays) {
            return $julyDays + (($docIndex * 7) % max(1, $dayCount - $julyDays));
        }

        return ($docIndex * 5) % min($julyDays, $dayCount);
    }

    private function issueDay(?string $receivedAt, int $salt, int $minDays = 3): Carbon
    {
        $base = $receivedAt !== null ? Carbon::parse($receivedAt) : Carbon::parse(self::START);
        $day = $base->copy()->addDays($minDays + ($salt % 8))->setTime(14, 0, 0);
        $end = Carbon::parse(self::END)->setTime(16, 0, 0);
        if ($day->gt($end)) {
            $day = $end;
        }
        if ($day->lt($base)) {
            $day = $base->copy()->addHours(6);
        }

        return $day;
    }
}
