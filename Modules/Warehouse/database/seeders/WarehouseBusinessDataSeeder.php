<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Dữ liệu nghiệp vụ mẫu cho dev/QA test UI + API ngay sau `migrate:fresh --seed`.
 *
 * Nguyên tắc:
 *  - Mọi hiệu ứng tồn kho (inventory_balances + stock_movements) đều đi qua
 *    CurrentBaselineStockService — đúng service mà tầng HTTP dùng. Seeder KHÔNG
 *    tự UPDATE inventory_balances hay INSERT stock_movements, nên số liệu luôn
 *    khớp logic full-post (scanned_qty == qty_planned) và không thể âm kho.
 *  - Master/evidence data đơn giản (sku, item_instances, physical_codes,
 *    containers, outbound_requests, warranty_case...) được insert thẳng theo
 *    đúng shape mà controller thật dùng; các trigger `trg_erd_normalize_runtime_*`
 *    sẽ tự điền cột mirror (id/item_code/status/condition/customer_name...).
 *  - Idempotent: chạy lại `db:seed` không tạo trùng và không ném exception.
 *    Toàn bộ khối nghiệp vụ được bỏ qua nếu doc mẫu đã tồn tại, vì
 *    stock_movements/audit_log là append-only và stock_documents POSTED là
 *    immutable ở tầng DB trigger.
 *
 * Bộ dữ liệu sinh ra:
 *  - 1 phiếu nhập COMPONENT đã POSTED (kèm BOX -> RECEIVED_PENDING_PUTAWAY).
 *  - 1 phiếu nhập PRODUCT đã POSTED (2 item serial vào kho).
 *  - 1 phiếu nhập DRAFT (để test màn hình soạn phiếu).
 *  - 1 phiếu xuất PRODUCT đã POSTED (trừ kho 1 item, khớp balance).
 *  - 1 phiếu xuất DRAFT sinh từ toa (chưa scan).
 *  - 1 warranty case cho item đã ISSUED + chuỗi warranty_event.
 */
class WarehouseBusinessDataSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const PRODUCT_SKU = 'HN-PRD-SEED-001';

    private const COMPONENT_SKU = 'HN-COMP-SEED-001';

    /** Doc no cố định để seeder nhận biết dữ liệu đã tồn tại (idempotency guard). */
    private const RECEIPT_COMPONENT_NO = 'PN-SEED-0001';

    private const RECEIPT_PRODUCT_NO = 'PN-SEED-0002';

    private const RECEIPT_DRAFT_NO = 'PN-SEED-0003';

    private const ISSUE_POSTED_NO = 'PX-SEED-0001';

    private const ISSUE_DRAFT_NO = 'PX-SEED-0002';

    private const WARRANTY_CASE_CODE = 'WC-SEED-0001';

    /**
     * Khối mở rộng "đủ 5 dòng/bảng" (WWHN — yêu cầu demo/QA có dữ liệu mẫu phong
     * phú). Doc no cố định riêng, độc lập với khối gốc phía trên để guard
     * idempotency không giẫm lên nhau.
     */
    private const PRODUCT_SKU_2 = 'HN-PRD-SEED-002';

    private const PRODUCT_SKU_3 = 'HN-PRD-SEED-003';

    private const PRODUCT_SKU_4 = 'HN-PRD-SEED-004';

    private const COMPONENT_SKU_2 = 'HN-COMP-SEED-002';

    private const RECEIPT_PRODUCT_NO_2 = 'PN-SEED-0004';

    private const RECEIPT_PRODUCT_NO_3 = 'PN-SEED-0005';

    private const RECEIPT_PRODUCT_NO_4 = 'PN-SEED-0007';

    private const ISSUE_POSTED_NO_2 = 'PX-SEED-0003';

    private const ISSUE_POSTED_NO_3 = 'PX-SEED-0004';

    private const ISSUE_POSTED_NO_4 = 'PX-SEED-0005';

    private const ISSUE_POSTED_NO_5 = 'PX-SEED-0006';

    private const WARRANTY_CASE_CODE_2 = 'WC-SEED-0002';

    private const WARRANTY_CASE_CODE_3 = 'WC-SEED-0003';

    private const WARRANTY_CASE_CODE_4 = 'WC-SEED-0004';

    private const WARRANTY_CASE_CODE_5 = 'WC-SEED-0005';

    private const COMPONENT_ISSUE_NOS = ['CID-SEED-0001', 'CID-SEED-0002', 'CID-SEED-0003'];

    private const OUTBOUND_IMPORT_NOS = ['IMP-SEED-0002', 'IMP-SEED-0003', 'IMP-SEED-0004', 'IMP-SEED-0005'];

    private CurrentBaselineStockService $stock;

    private string $actorId;

    private string $warehouseId;

    public function run(): void
    {
        if (DB::table('stock_documents')->where('doc_no', self::RECEIPT_PRODUCT_NO)->exists()) {
            $this->command?->info('WarehouseBusinessDataSeeder: dữ liệu nghiệp vụ mẫu đã tồn tại, bỏ qua.');

            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first();
        if ($warehouse === null) {
            $this->command?->warn('WarehouseBusinessDataSeeder: thiếu kho '.self::WAREHOUSE_CODE.'; chạy WarehouseDatabaseSeeder trước.');

            return;
        }

        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
        if ($actorId === null) {
            $this->command?->warn('WarehouseBusinessDataSeeder: thiếu user seed; chạy AuthDatabaseSeeder trước.');

            return;
        }

        $this->stock = app(CurrentBaselineStockService::class);
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

        $productSku = $this->ensureSku(self::PRODUCT_SKU, 'Máy bơm PV mẫu Hòa Nam', 'PRODUCT', true, 'HOANAM', 'PUMP', 'MDL-HN-01', 'SOLAR', 'KIT');
        $componentSku = $this->ensureSku(self::COMPONENT_SKU, 'Bo mạch điều khiển mẫu', 'COMPONENT', false, 'HOANAM', 'SPARE_PART', 'MDL-HN-01', 'BATTERY', 'BARE');

        $this->seedComponentReceipt($componentSku);
        $issuableItems = $this->seedProductReceipt($productSku);
        $this->seedDraftReceipt($componentSku);
        $issuedItemId = $this->seedPostedIssue($productSku, $issuableItems[0]);
        $this->seedDraftIssue($productSku);
        $this->seedWarrantyCase($issuedItemId, $productSku);

        $this->seedExtraBusinessData();

        $this->command?->info('WarehouseBusinessDataSeeder: đã tạo dữ liệu nghiệp vụ inbound/outbound/putaway/warranty.');
    }

    // ------------------------------------------------------------------
    // Inbound
    // ------------------------------------------------------------------

    /**
     * Phiếu nhập COMPONENT: BOX bắt buộc theo EnsureInboundComponentBox. Sau Post
     * Receipt, trigger trg_materialize_receipt_putaway_status đưa BOX sang
     * RECEIVED_PENDING_PUTAWAY nên màn hình /putaway/tasks có việc để làm.
     */
    private function seedComponentReceipt(string $skuId): void
    {
        $qty = 20;
        [$documentId, $lineId] = $this->createDocument('receipt', self::RECEIPT_COMPONENT_NO, [
            ['product_id' => $skuId, 'qty_planned' => $qty],
        ], ['source_name' => 'Nhà cung cấp linh kiện Hòa Nam']);

        $boxId = $this->ensureContainer('BOX-SEED-0001', 'BOX', 'EMPTY', $skuId);

        $this->stock->scanStockDocument($documentId, 'receipt', [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $this->actorId);

        $this->postDocument($documentId, 'receipt');

        // container_items: ánh xạ số lượng COMPONENT trong BOX (sku-level, không serial).
        DB::table('container_items')->insert([
            'container_item_id' => (string) Str::uuid(),
            'container_id' => $boxId,
            'item_id' => null,
            'sku_id' => $skuId,
            'quantity' => $qty,
            'status' => 'ACTIVE',
            'is_active' => true,
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => $documentId,
            'placed_at' => now(),
            'placed_by' => $this->actorId,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Putaway: đưa BOX vào TRAY để có lịch sử lưu kho (stock-neutral).
        $trayId = $this->ensureContainer('TRAY-SEED-0001', 'TRAY', 'EMPTY', null);
        $this->confirmPutaway($documentId, $boxId, $trayId);
    }

    /**
     * Phiếu nhập PRODUCT serial-tracked: mỗi unit là 1 item_instance có RFID.
     *
     * @return list<string> item_id đã IN_STOCK sau khi post
     */
    private function seedProductReceipt(string $skuId): array
    {
        $serials = ['SN-HN-SEED-0001', 'SN-HN-SEED-0002', 'SN-HN-SEED-0003'];
        $itemIds = [];
        foreach ($serials as $index => $serial) {
            $itemId = $this->ensureItemInstance($skuId, $serial);
            $this->ensureRfid($itemId, 'RFID-HN-SEED-'.sprintf('%04d', $index + 1));
            $itemIds[] = $itemId;
        }

        [$documentId, $lineId] = $this->createDocument('receipt', self::RECEIPT_PRODUCT_NO, [
            ['product_id' => $skuId, 'qty_planned' => count($itemIds)],
        ], ['source_name' => 'Nhà máy Hòa Nam']);

        foreach ($itemIds as $itemId) {
            $this->stock->scanStockDocument($documentId, 'receipt', [
                'line_id' => $lineId,
                'quantity' => 1,
                'item_id' => $itemId,
            ], $this->actorId);
        }

        $this->postDocument($documentId, 'receipt');

        return $itemIds;
    }

    /** Phiếu nhập DRAFT — chưa scan, để dev test màn hình soạn/sửa phiếu. */
    private function seedDraftReceipt(string $skuId): void
    {
        $this->createDocument('receipt', self::RECEIPT_DRAFT_NO, [
            ['product_id' => $skuId, 'qty_planned' => 10],
        ], ['source_name' => 'Phiếu nháp chờ nhận hàng']);
    }

    // ------------------------------------------------------------------
    // Outbound
    // ------------------------------------------------------------------

    /**
     * Phiếu xuất PRODUCT đã POSTED: 1 item ra khỏi kho. Balance giảm đúng 1 và
     * item chuyển ISSUED (điều kiện tiên quyết để mở warranty case).
     */
    private function seedPostedIssue(string $skuId, string $itemId): string
    {
        $requestId = $this->createOutboundRequest('TOA-SEED-0001', $skuId, 1);
        [$documentId, $lineId] = $this->createDocument('issue', self::ISSUE_POSTED_NO, [
            ['product_id' => $skuId, 'qty_planned' => 1],
        ], [
            'outbound_request_id' => $requestId,
            'recipient_name' => 'Đại lý Hòa Nam Hà Nội',
            'recipient_address' => 'Số 10, Cầu Giấy, Hà Nội',
        ]);

        $this->stock->scanStockDocument($documentId, 'issue', [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $this->actorId);

        // Label PRINTED là tiền điều kiện Post Issue ở tầng HTTP; seeder tạo
        // snapshot đã in để dữ liệu mẫu phản ánh đúng trạng thái sau khi in tem.
        $this->markLabelPrinted($documentId, $skuId);

        $this->postDocument($documentId, 'issue');

        return $itemId;
    }

    /** Phiếu xuất DRAFT sinh từ toa — chưa scan, để test màn hình pick/scan. */
    private function seedDraftIssue(string $skuId): void
    {
        $requestId = $this->createOutboundRequest('TOA-SEED-0002', $skuId, 1);
        $this->createDocument('issue', self::ISSUE_DRAFT_NO, [
            ['product_id' => $skuId, 'qty_planned' => 1],
        ], [
            'outbound_request_id' => $requestId,
            'recipient_name' => 'Đại lý Hòa Nam Đà Nẵng',
            'recipient_address' => 'Số 5, Hải Châu, Đà Nẵng',
        ]);
    }

    // ------------------------------------------------------------------
    // Warranty
    // ------------------------------------------------------------------

    /**
     * Warranty case cho item đã xuất cho khách (item_status=ISSUED) — đúng guard
     * WARRANTY_ITEM_NOT_ELIGIBLE của CurrentBaselineWarrantyController.
     */
    private function seedWarrantyCase(string $itemId, string $skuId, string $caseCode = self::WARRANTY_CASE_CODE, string $defectCode = 'DEF-004'): string
    {
        $existingId = DB::table('warranty_case')->where('warranty_case_code', $caseCode)->value('warranty_case_id');
        if ($existingId !== null) {
            return (string) $existingId;
        }

        $caseId = (string) Str::uuid();
        $defectId = DB::table('defect_catalogs')->where('defect_code', $defectCode)->value('defect_id');

        DB::table('warranty_case')->insert([
            'warranty_case_id' => $caseId,
            'warranty_case_code' => $caseCode,
            'item_id' => $itemId,
            'inventory_serial_id' => null,
            'product_id' => $skuId,
            'temporary_item_code' => null,
            'identification_status' => 'IDENTIFIED',
            'sales_trace_status' => 'TRACEABLE',
            'defect_id' => $defectId,
            'customer_name' => 'Trần Văn Khách',
            'customer_phone' => '0987654321',
            'customer_address' => 'Số 20, Thanh Xuân, Hà Nội',
            'purchase_date' => now()->subMonths(3)->toDateString(),
            'status' => 'CHECKING',
            'condition_status' => 'DEFECTIVE',
            'description' => 'Máy không lên nguồn sau 3 tháng sử dụng.',
            'reported_defect' => 'Khách báo máy không khởi động.',
            'received_condition' => 'Nguyên vỏ, đủ phụ kiện',
            'accessories_received' => 'Dây nguồn, sách hướng dẫn',
            'received_at' => now()->subDays(2),
            'created_by' => $this->actorId,
            'version' => 2,
            'legal_hold_flag' => false,
            'created_at' => now()->subDays(2),
            'updated_at' => now(),
        ]);

        DB::table('item_instances')->where('item_id', $itemId)->update([
            'item_status' => 'IN_WARRANTY',
            'updated_at' => now(),
        ]);

        $events = [
            ['RECEIVE', null, 'RECEIVED', 'Tiếp nhận máy bảo hành từ khách.', now()->subDays(2)],
            ['CHECK', 'RECEIVED', 'CHECKING', 'Kỹ thuật đang kiểm tra bo nguồn.', now()->subDay()],
        ];
        foreach ($events as [$type, $from, $to, $note, $at]) {
            DB::table('warranty_event')->insert([
                'warranty_event_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'event_type' => $type,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'created_by' => $this->actorId,
                'event_at' => $at,
            ]);
        }

        AuditLogger::record(
            action: 'WARRANTY_CASE_RECEIVED',
            objectType: 'warranty_case',
            objectId: $caseId,
            actorId: $this->actorId,
            requestId: 'seed-warranty-'.strtolower($caseCode),
            afterData: ['status' => 'CHECKING', 'item_id' => $itemId, 'stock_effect' => 'NONE'],
        );

        return $caseId;
    }

    // ------------------------------------------------------------------
    // Mở rộng — bảo đảm mỗi bảng nghiệp vụ có tối thiểu 5 dòng dữ liệu mẫu
    // ------------------------------------------------------------------

    /**
     * Chạy sau khối gốc phía trên (guard idempotency riêng theo doc_no cố định
     * của từng chứng từ). Mọi hiệu ứng tồn kho vẫn đi qua CurrentBaselineStockService/
     * ComponentContainerSubledgerService/CurrentBaselineReversalService — không tự
     * UPDATE inventory_balances/INSERT stock_movements.
     */
    private function seedExtraBusinessData(): void
    {
        $productSku2 = $this->ensureSku(self::PRODUCT_SKU_2, 'Máy phát điện PV mẫu Hòa Nam 002', 'PRODUCT', true, 'KUBOTA', 'ENGINE', 'MDL-KB-01', 'DIESEL', 'SET');
        $productSku3 = $this->ensureSku(self::PRODUCT_SKU_3, 'Máy cắt cỏ PV mẫu Hòa Nam 003', 'PRODUCT', true, 'HUSQVARNA', 'ENGINE', 'MDL-HQ-01', 'GASOLINE', 'BARE');
        $productSku4 = $this->ensureSku(self::PRODUCT_SKU_4, 'Máy xới đất PV mẫu Hòa Nam 004', 'PRODUCT', true, 'YANMAR', 'TRACTOR', 'MDL-YM-01', 'DIESEL', 'SET');
        $componentSku2 = $this->ensureSku(self::COMPONENT_SKU_2, 'Dây curoa mẫu Hòa Nam 002', 'COMPONENT', false, 'HOANAM', 'SPARE_PART', 'MDL-HN-01', null, 'BARE');

        // Mỗi bước dưới đây tự guard theo doc_no/code riêng (idempotent độc lập) —
        // không có transaction bọc toàn seedExtraBusinessData, nên nếu 1 bước lỗi
        // giữa chừng, lần chạy sau vẫn phải tiếp tục đúng từ bước còn thiếu.
        $items2 = $this->seedProductReceiptVariant(self::RECEIPT_PRODUCT_NO_2, $productSku2, 'SN-HN-SEED-EXT-01', 'RFID-HN-SEED-EXT-01', 2, 'Nhà máy Hòa Nam — lô mẫu 2');
        $items3 = $this->seedProductReceiptVariant(self::RECEIPT_PRODUCT_NO_3, $productSku3, 'SN-HN-SEED-EXT-02', 'RFID-HN-SEED-EXT-02', 2, 'Nhà máy Hòa Nam — lô mẫu 3');
        $items4 = $this->seedProductReceiptVariant(self::RECEIPT_PRODUCT_NO_4, $productSku4, 'SN-HN-SEED-EXT-03', 'RFID-HN-SEED-EXT-03', 6, 'Nhà máy Hòa Nam — lô mẫu 4');

        $issuedItem2 = $this->seedPostedIssueVariant(self::ISSUE_POSTED_NO_2, 'TOA-SEED-0003', $productSku2, $items2[0], 'Đại lý Hòa Nam Hồ Chí Minh', 'Số 1, Quận 7, TP.HCM', self::OUTBOUND_IMPORT_NOS[0]);
        $issuedItem3 = $this->seedPostedIssueVariant(self::ISSUE_POSTED_NO_3, 'TOA-SEED-0004', $productSku3, $items3[0], 'Đại lý Hòa Nam Hải Phòng', 'Số 3, Lê Chân, Hải Phòng', self::OUTBOUND_IMPORT_NOS[1]);
        $issuedItem4 = $this->seedPostedIssueVariant(self::ISSUE_POSTED_NO_4, 'TOA-SEED-0005', $productSku4, $items4[0], 'Đại lý Hòa Nam Cần Thơ', 'Số 8, Ninh Kiều, Cần Thơ', self::OUTBOUND_IMPORT_NOS[2]);
        $issuedItem5 = $this->seedPostedIssueVariant(self::ISSUE_POSTED_NO_5, 'TOA-SEED-0006', $productSku4, $items4[1], 'Đại lý Hòa Nam Hà Nội', 'Số 2, Cầu Giấy, Hà Nội', self::OUTBOUND_IMPORT_NOS[2]);

        $this->seedWarrantyCase($issuedItem2, $productSku2, self::WARRANTY_CASE_CODE_2, 'DEF-001');
        $this->seedWarrantyCase($issuedItem3, $productSku3, self::WARRANTY_CASE_CODE_3, 'DEF-002');
        $this->seedWarrantyCase($issuedItem4, $productSku4, self::WARRANTY_CASE_CODE_4, 'DEF-005');
        $this->seedWarrantyCase($issuedItem5, $productSku4, self::WARRANTY_CASE_CODE_5, 'DEF-003');
        $warrantyCaseId4 = DB::table('warranty_case')->where('warranty_case_code', self::WARRANTY_CASE_CODE_4)->value('warranty_case_id');

        // Phiếu xuất thứ 6-7 (mỗi phiếu 1 import riêng) + warranty case thêm dùng
        // items4[3]/items4[4] để outbound_request_imports/warranty_case đạt ≥5 dòng.
        $issuedItem6 = $this->seedPostedIssueVariant('PX-SEED-0007', 'TOA-SEED-0007', $productSku4, $items4[3], 'Đại lý Hòa Nam Đà Nẵng', 'Số 12, Hải Châu, Đà Nẵng', self::OUTBOUND_IMPORT_NOS[2]);
        $this->seedWarrantyCase($issuedItem6, $productSku4, 'WC-SEED-0006', 'DEF-004');
        $this->seedPostedIssueVariant('PX-SEED-0008', 'TOA-SEED-0008', $productSku4, $items4[4], 'Đại lý Hòa Nam Cần Thơ', 'Số 20, Ninh Kiều, Cần Thơ', self::OUTBOUND_IMPORT_NOS[3]);

        // BOX riêng cho componentSku2: assertContainerAvailable đòi container.primary_sku_id
        // khớp đúng SKU của Component Issue line, không dùng chung BOX-SEED-0001 (SKU khác).
        // Putaway BOX-SEED-0002 vào TRAY thứ 2 -> containers/putaway_events tăng thêm.
        $boxId2 = $this->seedComponentReceiptVariant('PN-SEED-0006', $componentSku2, 'BOX-SEED-0002', 10);
        $this->seedPutawayVariant('PN-SEED-0006', $boxId2, 'TRAY-SEED-0002', 'A-01-02');

        // BOX thứ 3-5 cho componentSku2 (lô riêng mỗi cái) -> containers/
        // container_items/putaway_events đạt tối thiểu 5 dòng.
        $boxId3 = $this->seedComponentReceiptVariant('PN-SEED-0008', $componentSku2, 'BOX-SEED-0003', 8);
        $this->seedPutawayVariant('PN-SEED-0008', $boxId3, 'TRAY-SEED-0001', 'A-01-01');
        $boxId4 = $this->seedComponentReceiptVariant('PN-SEED-0009', $componentSku2, 'BOX-SEED-0004', 6);
        $this->seedPutawayVariant('PN-SEED-0009', $boxId4, 'TRAY-SEED-0001', 'A-01-01');
        $boxId5 = $this->seedComponentReceiptVariant('PN-SEED-0010', $componentSku2, 'BOX-SEED-0005', 4);
        $this->seedPutawayVariant('PN-SEED-0010', $boxId5, 'TRAY-SEED-0002', 'A-01-02');

        // Component Issue (WARRANTY) — nối tiếp warranty case #4, dùng đúng service
        // thật qua ComponentContainerSubledgerService + CurrentBaselineStockService.
        $this->seedComponentIssue(self::COMPONENT_ISSUE_NOS[0], $warrantyCaseId4, $componentSku2, $boxId2, 3);
        $this->seedComponentIssue(self::COMPONENT_ISSUE_NOS[1], $warrantyCaseId4, $componentSku2, $boxId2, 2);
        $this->seedComponentIssue(self::COMPONENT_ISSUE_NOS[2], $warrantyCaseId4, $componentSku2, $boxId2, 1);
        $this->seedComponentIssue('CID-SEED-0004', $warrantyCaseId4, $componentSku2, $boxId3, 2);
        $this->seedComponentIssue('CID-SEED-0005', $warrantyCaseId4, $componentSku2, $boxId3, 1);

        // Reversal — sửa sai các phiếu nhập PRODUCT đã POSTED, mỗi phiếu 1 item còn
        // IN_STOCK (chưa Issue/Warranty), qua đúng CurrentBaselineReversalService.
        $this->seedReversalVariant(self::RECEIPT_PRODUCT_NO_3, 'SN-HN-SEED-EXT-02-02', 'Seed reversal — hiệu chỉnh mẫu QA/dev #1.');
        $this->seedReversalVariant(self::RECEIPT_PRODUCT_NO_4, 'SN-HN-SEED-EXT-03-03', 'Seed reversal — hiệu chỉnh mẫu QA/dev #2.');
        $this->seedReversalVariant(self::RECEIPT_PRODUCT_NO_4, 'SN-HN-SEED-EXT-03-06', 'Seed reversal — hiệu chỉnh mẫu QA/dev #3.');
        $this->seedReversalVariant(self::RECEIPT_PRODUCT_NO, 'SN-HN-SEED-0002', 'Seed reversal — hiệu chỉnh mẫu QA/dev #4.');
        $this->seedReversalVariant(self::RECEIPT_PRODUCT_NO, 'SN-HN-SEED-0003', 'Seed reversal — hiệu chỉnh mẫu QA/dev #5.');

        // BOX-SEED-0006: nhận COMPONENT rồi POST nhưng CỐ Ý không gọi
        // seedPutawayVariant() — mọi BOX component khác ở trên đều được putaway
        // xác nhận ngay, nên "/putaway/tasks" (tab Chờ xếp khay) luôn trống dù
        // trigger trg_materialize_receipt_putaway_status đã đưa BOX này về
        // RECEIVED_PENDING_PUTAWAY. Giữ đúng 1 BOX ở trạng thái chờ để màn hình
        // có việc để làm ngay sau khi seed.
        $this->seedComponentReceiptVariant('PN-SEED-0011', $componentSku2, 'BOX-SEED-0006', 5);
    }

    /**
     * Biến thể tham số hóa của seedProductReceipt(): thêm 1 phiếu nhập PRODUCT
     * POSTED với serial/RFID prefix riêng để không đụng dữ liệu khối gốc.
     *
     * @return list<string> item_id đã IN_STOCK sau khi post
     */
    private function seedProductReceiptVariant(string $docNo, string $skuId, string $serialPrefix, string $rfidPrefix, int $qty, string $sourceName): array
    {
        $itemIds = [];
        for ($i = 1; $i <= $qty; $i++) {
            $itemId = $this->ensureItemInstance($skuId, $serialPrefix.'-'.sprintf('%02d', $i));
            $this->ensureRfid($itemId, $rfidPrefix.'-'.sprintf('%02d', $i));
            $itemIds[] = $itemId;
        }

        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return $itemIds;
        }

        [$documentId, $lineId] = $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => count($itemIds)],
        ], ['source_name' => $sourceName]);

        foreach ($itemIds as $itemId) {
            $this->stock->scanStockDocument($documentId, 'receipt', [
                'line_id' => $lineId,
                'quantity' => 1,
                'item_id' => $itemId,
            ], $this->actorId);
        }

        $this->postDocument($documentId, 'receipt');

        return $itemIds;
    }

    /**
     * Biến thể tham số hóa của seedPostedIssue(): thêm 1 phiếu xuất PRODUCT
     * POSTED với toa/đại lý riêng.
     *
     * @return string item_id đã ISSUED
     */
    private function seedPostedIssueVariant(string $docNo, string $requestNo, string $skuId, string $itemId, string $recipientName, string $recipientAddress, string $importNo = 'IMP-SEED-0001'): string
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return $itemId;
        }

        $requestId = $this->createOutboundRequest($requestNo, $skuId, 1, $importNo);
        [$documentId, $lineId] = $this->createDocument('issue', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 1],
        ], [
            'outbound_request_id' => $requestId,
            'recipient_name' => $recipientName,
            'recipient_address' => $recipientAddress,
        ]);

        $this->stock->scanStockDocument($documentId, 'issue', [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $this->actorId);

        $this->markLabelPrinted($documentId, $skuId);
        $this->postDocument($documentId, 'issue');

        return $itemId;
    }

    /**
     * Biến thể tham số hóa của seedComponentReceipt(): phiếu nhập COMPONENT
     * POSTED vào 1 BOX riêng (primary_sku_id = $skuId), không đụng BOX-SEED-0001.
     *
     * @return string container_id của BOX đã nhận hàng
     */
    private function seedComponentReceiptVariant(string $docNo, string $skuId, string $boxCode, int $qty): string
    {
        $boxId = $this->ensureContainer($boxCode, 'BOX', 'EMPTY', $skuId);
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return $boxId;
        }

        [$documentId, $lineId] = $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => $qty],
        ], ['source_name' => 'Nhà cung cấp linh kiện Hòa Nam — lô mẫu 2']);

        $this->stock->scanStockDocument($documentId, 'receipt', [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $this->actorId);

        $this->postDocument($documentId, 'receipt');

        DB::table('container_items')->insert([
            'container_item_id' => (string) Str::uuid(),
            'container_id' => $boxId,
            'item_id' => null,
            'sku_id' => $skuId,
            'quantity' => $qty,
            'status' => 'ACTIVE',
            'is_active' => true,
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => $documentId,
            'placed_at' => now(),
            'placed_by' => $this->actorId,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $boxId;
    }

    /**
     * Component Issue (purpose=WARRANTY) qua đúng service thật —
     * ComponentContainerSubledgerService::consumeDocument() +
     * CurrentBaselineStockService::postComponentIssue(), giống hệt
     * CurrentBaselineComponentIssueController::store()/post().
     */
    private function seedComponentIssue(string $componentIssueNo, string $warrantyCaseId, string $componentSkuId, string $boxId, int $qty): void
    {
        if (DB::table('component_issue_documents')->where('component_issue_no', $componentIssueNo)->exists()) {
            return;
        }

        $documentId = (string) Str::uuid();
        DB::table('component_issue_documents')->insert([
            'id' => $documentId,
            'component_issue_no' => $componentIssueNo,
            'warehouse_id' => $this->warehouseId,
            'warranty_case_id' => $warrantyCaseId,
            'document_date' => now()->toDateString(),
            'purpose' => 'WARRANTY',
            'status' => 'DRAFT',
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        $lineId = (string) Str::uuid();
        DB::table('component_issue_lines')->insert([
            'id' => $lineId,
            'component_issue_document_id' => $documentId,
            'line_no' => 1,
            'sku_id' => $componentSkuId,
            'required_qty' => $qty,
            'scanned_qty' => $qty,
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        DB::table('component_issue_items')->insert([
            'id' => (string) Str::uuid(),
            'component_issue_line_id' => $lineId,
            'item_id' => null,
            'container_id' => $boxId,
            'quantity' => $qty,
            'created_at' => now(),
            'created_by' => $this->actorId,
            'updated_at' => now(),
            'updated_by' => $this->actorId,
            'version' => 0,
        ]);

        AuditLogger::record(
            action: 'CREATE_COMPONENT_ISSUE',
            objectType: 'component_issue_document',
            objectId: $documentId,
            actorId: $this->actorId,
            requestId: 'seed-'.strtolower($componentIssueNo),
            afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'stock_effect' => 'NONE'],
        );

        app(ComponentContainerSubledgerService::class)->consumeDocument($documentId);
        $key = 'seed-component-issue-'.$documentId;
        $this->stock->postComponentIssue($documentId, $this->actorId, $key, sha1($key));
    }

    /**
     * Putaway DRAFT->CONFIRMED biến thể cho 1 BOX bổ sung (tham số hóa
     * confirmPutaway() theo doc_no cụ thể thay vì hằng số cố định).
     */
    private function seedPutawayVariant(string $receiptDocNo, string $boxId, string $trayCode, string $locationHint): void
    {
        if (DB::table('putaway_events')->where('box_container_id', $boxId)->exists()) {
            return;
        }

        $documentId = DB::table('stock_documents')->where('doc_no', $receiptDocNo)->value('id');
        if ($documentId === null) {
            return;
        }

        $trayId = $this->ensureContainer($trayCode, 'TRAY', 'EMPTY', null);

        $eventId = (string) Str::uuid();
        DB::table('putaway_events')->insert([
            'id' => $eventId,
            'box_container_id' => $boxId,
            'container_id' => $boxId,
            'stock_document_id' => $documentId,
            'warehouse_id' => $this->warehouseId,
            'from_tray_id' => null,
            'to_tray_id' => $trayId,
            'from_parent_container_id' => null,
            'to_parent_container_id' => $trayId,
            'to_location_hint' => $locationHint,
            'status' => 'CONFIRMED',
            'location_hint' => $locationHint,
            'note' => 'Putaway mẫu từ seeder (biến thể)',
            'confirmed_at' => now(),
            'confirmed_by' => $this->actorId,
            'version' => 2,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('containers')->where('container_id', $boxId)->update([
            'parent_container_id' => $trayId,
            'status' => 'STORED',
            'location_hint' => $locationHint,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        DB::table('containers')->where('container_id', $trayId)->update([
            'status' => 'PARTIAL',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);

        AuditLogger::record(
            action: 'PUTAWAY_CONFIRM',
            objectType: 'putaway_event',
            objectId: $eventId,
            actorId: $this->actorId,
            requestId: 'seed-putaway-'.strtolower($boxId),
            afterData: ['status' => 'CONFIRMED', 'box_status' => 'STORED', 'stock_effect' => 'NONE'],
        );
    }

    /**
     * Reversal DRAFT->POSTED biến thể — tham số hóa doc_no/serial/reason để gọi
     * nhiều lần với chứng từ khác nhau, qua đúng
     * CurrentBaselineReversalService::execute() (cùng code path mà
     * ReversalDocumentController::store() dùng, không tự tay ghi movement).
     *
     * BR-REV: PRODUCT/serial reversal bắt buộc item_id + quantity=1/line, và
     * assertItemDependency() chỉ chấp nhận item còn IN_STOCK/NEW cho receipt —
     * item đã bị Issue/Warranty không đảo được, phải dùng item còn nguyên trong
     * kho (chưa động tới ở nơi khác của seedExtraBusinessData()).
     */
    private function seedReversalVariant(string $receiptDocNo, string $serialNumber, string $reason): void
    {
        $receiptId = DB::table('stock_documents')->where('doc_no', $receiptDocNo)->value('id');
        if ($receiptId === null) {
            return;
        }

        $itemId = DB::table('item_instances')->where('serial_number', $serialNumber)->value('item_id');
        if ($itemId === null) {
            return;
        }

        if (DB::table('reversal_lines')->where('item_id', $itemId)->exists()) {
            return;
        }

        if (DB::table('item_instances')->where('item_id', $itemId)->value('item_status') !== 'IN_STOCK') {
            return;
        }

        // Movement RECEIPT được ghi 1 dòng/line chứng từ (gộp cả line nhiều serial),
        // không phải 1 dòng/item — item_id cụ thể đi trong reversal_line, không lọc
        // ở đây.
        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $receiptId)
            ->where('movement_type', 'RECEIPT')
            ->first();
        if ($movement === null) {
            return;
        }

        $version = (int) DB::table('stock_documents')->where('id', $receiptId)->value('version');
        $key = 'seed-reversal-'.$itemId;

        app(CurrentBaselineReversalService::class)->execute(
            $receiptId,
            'receipt',
            [
                'reason' => $reason,
                'reason_code' => 'CORRECTION',
                'lines' => [
                    ['movement_id' => $movement->id, 'quantity' => 1, 'item_id' => $itemId],
                ],
            ],
            $this->actorId,
            $key,
            sha1($key),
            $version
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Tạo stock document + lines theo đúng shape của CurrentBaselineDocumentController.
     *
     * @param  list<array{product_id:string,qty_planned:int}>  $lines
     * @return array{0:string,1:string} [document_id, line_id đầu tiên]
     */
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
            'note' => $extra['note'] ?? 'Dữ liệu mẫu seeder',
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
            requestId: 'seed-'.strtolower($docNo),
            afterData: ['doc_no' => $docNo, 'status' => 'DRAFT', 'version' => 1],
        );

        return [$documentId, $lineIds[0]];
    }

    /**
     * Post qua service thật: service tự bump balance, ghi stock_movement, cập nhật
     * subledger item và đổi status SCANNING -> POSTED. Version của document được
     * bump trước khi POSTED vì trigger trg_stock_documents_posted_immutable chặn
     * mọi UPDATE sau đó.
     */
    private function postDocument(string $documentId, string $type): void
    {
        $version = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');
        DB::table('stock_documents')->where('id', $documentId)->update([
            'version' => $version + 1,
            'updated_by' => $this->actorId,
            'updated_at' => now(),
        ]);

        $key = 'seed-'.$type.'-'.$documentId;
        $this->stock->postStockDocument($documentId, $type, $this->actorId, $key, sha1($key));
    }

    /**
     * $brandCode/$categoryCode/$modelCode/$powerSourceCode/$packageCode tham
     * chiếu các mã đã seed bởi WarehouseDatabaseSeeder (BRANDS/PRODUCT_CATEGORIES/
     * PRODUCT_MODELS/POWER_SOURCES/PACKAGE_VARIANTS) — seeder này chạy sau nên
     * các bảng catalog đã tồn tại.
     */
    private function ensureSku(
        string $code,
        string $name,
        string $type,
        bool $serialTracked,
        ?string $brandCode = null,
        ?string $categoryCode = null,
        ?string $modelCode = null,
        ?string $powerSourceCode = null,
        ?string $packageCode = null,
    ): string {
        $existing = Sku::query()->where('sku_code', $code)->value('sku_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $sku = Sku::query()->create([
            'sku_code' => $code,
            'sku_name' => $name,
            'sku_type' => $type,
            'unit' => 'cái',
            'is_serial_tracked' => $serialTracked,
            'brand_id' => $brandCode !== null ? DB::table('brands')->where('brand_code', $brandCode)->value('id') : null,
            'category_id' => $categoryCode !== null ? DB::table('product_categories')->where('category_code', $categoryCode)->value('id') : null,
            'model_id' => $modelCode !== null ? DB::table('product_models')->where('model_code', $modelCode)->value('id') : null,
            'power_source_id' => $powerSourceCode !== null ? DB::table('power_sources')->where('power_source_code', $powerSourceCode)->value('id') : null,
            'package_variant_id' => $packageCode !== null ? DB::table('package_variants')->where('package_code', $packageCode)->value('id') : null,
            'version_code' => 'V1',
            'warranty_applicable' => $type === 'PRODUCT',
            'published_to_app' => true,
            'status' => 'ACTIVE',
        ]);

        return (string) $sku->sku_id;
    }

    private function ensureItemInstance(string $skuId, string $serial): string
    {
        $existing = DB::table('item_instances')->where('serial_number', $serial)->value('item_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => $serial,
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    /** RFID ACTIVE + hardware_uid là bắt buộc cho PRODUCT trước Post Issue. */
    private function ensureRfid(string $itemId, string $codeValue): void
    {
        if (DB::table('physical_codes')->where('code_value', $codeValue)->exists()) {
            return;
        }

        DB::table('physical_codes')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'RFID',
            'hardware_uid' => 'HW-'.strtoupper(Str::random(10)),
            'status' => 'ACTIVE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'item_id' => $itemId,
            'is_primary' => true,
            'assigned_by' => $this->actorId,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureContainer(string $code, string $type, string $status, ?string $primarySkuId): string
    {
        $existing = DB::table('containers')->where('container_code', $code)->value('container_id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $containerId = (string) Str::uuid();
        DB::table('containers')->insert([
            // Trên Postgres, containers có cột id mirror + trigger normalize
            // riêng (không tự set như các bảng khác) nên phải set thủ công.
            // Trên SQLite (test), bảng containers không có cột này.
            'container_id' => $containerId,
            ...(Schema::hasColumn('containers', 'id') ? ['id' => $containerId] : []),
            'container_code' => $code,
            'container_type' => $type,
            'primary_sku_id' => $primarySkuId,
            'status' => $status,
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $containerId;
    }

    /**
     * Putaway DRAFT -> CONFIRMED (stock-neutral): BOX vào TRAY, status STORED.
     * putaway_events là append-only sau CONFIRMED nên seeder ghi 1 lần duy nhất.
     */
    private function confirmPutaway(string $documentId, string $boxId, string $trayId): void
    {
        $eventId = (string) Str::uuid();
        DB::table('putaway_events')->insert([
            'id' => $eventId,
            'box_container_id' => $boxId,
            'container_id' => $boxId,
            'stock_document_id' => $documentId,
            'warehouse_id' => $this->warehouseId,
            'from_tray_id' => null,
            'to_tray_id' => $trayId,
            'from_parent_container_id' => null,
            'to_parent_container_id' => $trayId,
            'to_location_hint' => 'A-01-01',
            'status' => 'CONFIRMED',
            'location_hint' => 'A-01-01',
            'note' => 'Putaway mẫu từ seeder',
            'confirmed_at' => now(),
            'confirmed_by' => $this->actorId,
            'version' => 2,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('containers')->where('container_id', $boxId)->update([
            'parent_container_id' => $trayId,
            'status' => 'STORED',
            'location_hint' => 'A-01-01',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        DB::table('containers')->where('container_id', $trayId)->update([
            'status' => 'PARTIAL',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);

        AuditLogger::record(
            action: 'PUTAWAY_CONFIRM',
            objectType: 'putaway_event',
            objectId: $eventId,
            actorId: $this->actorId,
            requestId: 'seed-putaway-001',
            afterData: ['status' => 'CONFIRMED', 'box_status' => 'STORED', 'stock_effect' => 'NONE'],
        );
    }

    /** Toa xuất (outbound_requests) — nguồn bắt buộc của mọi phiếu xuất theo BP-WMS-04. */
    private function createOutboundRequest(string $requestNo, string $skuId, int $qty, string $importNo = 'IMP-SEED-0001'): string
    {
        $existing = DB::table('outbound_requests')->where('request_no', $requestNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = $this->ensureOutboundImport($importNo);
        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => $requestNo,
            'import_id' => $importId,
            'recipient_name' => 'Đại lý Hòa Nam',
            'recipient_address' => 'Hà Nội',
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

    private function ensureOutboundImport(string $importNo = 'IMP-SEED-0001'): string
    {
        $existing = DB::table('outbound_request_imports')->where('import_no', $importNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => $importNo,
            'source_file_name' => 'toa-xuat-mau.xlsx',
            'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
            'source_checksum' => hash('sha256', 'toa-xuat-mau-seed-'.$importNo),
            'status' => 'CONSUMED',
            'total_rows' => 2,
            'valid_rows' => 2,
            'error_rows' => 0,
            // uploaded_at/uploaded_by chỉ tồn tại trên Postgres (ERD 1.0
            // hardening, pgsql-only); SQLite (test) không có 2 cột này.
            ...(Schema::hasColumn('outbound_request_imports', 'uploaded_at') ? [
                'uploaded_at' => now(),
                'uploaded_by' => $this->actorId,
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

    /**
     * Packing Label đã in cho phiếu xuất.
     *
     * Từ migration 2026_08_15_140000 (flow06), label là document-level: đúng một
     * label_snapshots cho mỗi outbound_document (unique uk_label_snapshots_outbound_document),
     * còn outbound_item_match_id/sku_id chỉ giữ lại cho snapshot lịch sử nên để null.
     */
    private function markLabelPrinted(string $documentId, string $skuId): void
    {
        if (DB::table('label_snapshots')->where('outbound_document_id', $documentId)->exists()) {
            return;
        }

        $items = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('m.item_id')
            ->pluck('m.item_id')
            ->all();

        // Cùng quy ước với AlignedOutboundFlowController::createPackingLabelSnapshot()
        // ('PL-'.doc_no) để nhãn seed không lệch contract với nhãn tạo qua API thật.
        $docNo = (string) (DB::table('stock_documents')->where('id', $documentId)->value('doc_no') ?? $documentId);
        $labelCode = 'PL-'.$docNo;

        $insert = [
            'id' => (string) Str::uuid(),
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode([
                'source' => 'seeder',
                'label_code' => $labelCode,
                'outbound_no' => $docNo,
                'outbound_document_id' => $documentId,
                'sku_id' => $skuId,
                'item_ids' => $items,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'PRINTED',
            'applied_at' => now(),
            'applied_by' => $this->actorId,
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        // Phòng hờ môi trường đang có cột vật lý label_snapshots.label_code (khác
        // schema hiện tại của repo — bảng canonical không khai cột này ở bất kỳ
        // migration nào tính đến 2026_08_16_137000) mà seeder chưa gán được giá trị
        // gây NOT NULL violation. Ghi thêm khi cột thật sự tồn tại, không giả định.
        if (Schema::hasColumn('label_snapshots', 'label_code')) {
            $insert['label_code'] = $labelCode;
        }

        DB::table('label_snapshots')->insert($insert);
    }
}
