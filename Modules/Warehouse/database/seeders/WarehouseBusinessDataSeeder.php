<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
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

        $productSku = $this->ensureSku(self::PRODUCT_SKU, 'Máy bơm PV mẫu Hòa Nam', 'PRODUCT', true);
        $componentSku = $this->ensureSku(self::COMPONENT_SKU, 'Bo mạch điều khiển mẫu', 'COMPONENT', false);

        $this->seedComponentReceipt($componentSku);
        $issuableItems = $this->seedProductReceipt($productSku);
        $this->seedDraftReceipt($componentSku);
        $issuedItemId = $this->seedPostedIssue($productSku, $issuableItems[0]);
        $this->seedDraftIssue($productSku);
        $this->seedWarrantyCase($issuedItemId, $productSku);

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
    private function seedWarrantyCase(string $itemId, string $skuId): void
    {
        $caseId = (string) Str::uuid();
        $defectId = DB::table('defect_catalogs')->where('defect_code', 'DEF-004')->value('defect_id');

        DB::table('warranty_case')->insert([
            'warranty_case_id' => $caseId,
            'warranty_case_code' => self::WARRANTY_CASE_CODE,
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
            requestId: 'seed-warranty-001',
            afterData: ['status' => 'CHECKING', 'item_id' => $itemId, 'stock_effect' => 'NONE'],
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

    private function ensureSku(string $code, string $name, string $type, bool $serialTracked): string
    {
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
    private function createOutboundRequest(string $requestNo, string $skuId, int $qty): string
    {
        $existing = DB::table('outbound_requests')->where('request_no', $requestNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = $this->ensureOutboundImport();
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

    private function ensureOutboundImport(): string
    {
        $existing = DB::table('outbound_request_imports')->where('import_no', 'IMP-SEED-0001')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => 'IMP-SEED-0001',
            'source_file_name' => 'toa-xuat-mau.xlsx',
            'source_key' => 'seed/toa-xuat-mau.xlsx',
            'source_checksum' => hash('sha256', 'toa-xuat-mau-seed'),
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

        DB::table('label_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode([
                'source' => 'seeder',
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
        ]);
    }
}
