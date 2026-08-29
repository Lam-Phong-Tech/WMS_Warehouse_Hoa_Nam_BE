<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
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
 * Dữ liệu mẫu BỔ SUNG cho "Báo cáo vận hành" (8 tab, xem AlignedReportsPage) —
 * yêu cầu người dùng 2026-08-29: cần dữ liệu trải đều 2026-08-16..2026-08-29
 * (14 ngày) thay vì dồn cục vào 1-2 ngày như WarehouseBusinessDataSeeder gốc,
 * và có đủ biến thiên enum (status khác nhau) để mọi tab có gì để xem/lọc.
 *
 * Nguyên tắc — GIỐNG HỆT WarehouseBusinessDataSeeder (seeder này CHỦ Ý tách
 * riêng, không sửa file gốc, để guard idempotency của 2 bên độc lập nhau):
 *  - Hiệu ứng tồn kho luôn qua CurrentBaselineStockService/
 *    ComponentContainerSubledgerService/CurrentBaselineReversalService.
 *  - Để bản ghi có ngày trong quá khứ (16-29/08) mà vẫn đi đúng service thật
 *    (service hardcode `now()` cho occurred_at/created_at), seeder dùng
 *    Carbon::setTestNow() bọc quanh từng thao tác trong ngày rồi reset ngay —
 *    KHÔNG UPDATE ngược cột ngày sau khi POSTED (trigger
 *    trg_stock_documents_posted_immutable chặn mọi UPDATE sau đó).
 *  - Idempotent theo doc_no cố định 'PN-RPT-01'.
 *
 * Dữ liệu sinh ra (khớp 8 tab báo cáo):
 *  - 14 phiếu nhập (4 phiếu PRODUCT serial ngày 1-4 => 16 item; 10 phiếu
 *    COMPONENT ngày 5-14 vào 1 BOX dùng chung) + 14 phiếu xuất POSTED (1
 *    item/ngày, xoay vòng 5 đại lý) => Tab1 (XNT/chứng từ), Tab2/3 (tồn/trace).
 *  - 3 import đứng riêng (UPLOADED/VALIDATED/REJECTED) + dòng VALID/ERROR/
 *    WARNING => Tab4 (import/match & lỗi dữ liệu).
 *  - 2 label_snapshots biến thể (PENDING_PRINT, PRINT_FAILED) => Tab5.
 *  - 6 warranty case đủ 6 status (RECEIVED/CHECKING/REPAIRING/COMPLETED/
 *    RETURNED/CANCELLED, 1 case TEMP_ONLY) + 3 component issue (WARRANTY)
 *    => Tab6.
 *  - Cấp market/region cho 5 agency sẵn có + gắn agency_id vào phiếu xuất
 *    => Tab7 (xuất theo đại lý) lọc được market/region.
 *  - 2 phiếu nhập DRAFT thiếu evidence => Tab8 (chứng từ cần bổ sung).
 *  - 2 reversal (đảo phiếu nhập) trên 2 item còn IN_STOCK => Tab1 dòng Reversal.
 */
class OperationalReportRangeSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const PRODUCT_SKU = 'HN-PRD-SEED-RPT-001';

    private const COMPONENT_SKU = 'HN-COMP-SEED-RPT-001';

    private const START_DATE = '2026-08-16';

    private const DAYS = 14;

    /** Idempotency guard — doc đầu tiên seeder này tạo. */
    private const GUARD_DOC_NO = 'PN-RPT-01';

    private const AGENCY_NAMES = [
        'AG-001' => ['Đại lý Hoa Nam Hà Nội', 'Số 10, Cầu Giấy, Hà Nội', 'MIEN_BAC', 'HA_NOI'],
        'AG-002' => ['Đại lý Hoa Nam Đà Nẵng', 'Số 5, Hải Châu, Đà Nẵng', 'MIEN_TRUNG', 'DA_NANG'],
        'AG-003' => ['Đại lý Hoa Nam Hồ Chí Minh', 'Số 1, Quận 7, TP.HCM', 'MIEN_NAM', 'HO_CHI_MINH'],
        'AG-004' => ['Đại lý Hoa Nam Hải Phòng', 'Số 3, Lê Chân, Hải Phòng', 'MIEN_BAC', 'HAI_PHONG'],
        'AG-005' => ['Đại lý Hoa Nam Cần Thơ', 'Số 8, Ninh Kiều, Cần Thơ', 'MIEN_NAM', 'CAN_THO'],
    ];

    private const DEFECT_CYCLE = ['DEF-001', 'DEF-002', 'DEF-003', 'DEF-004', 'DEF-005', 'DEF-001'];

    private const WARRANTY_STATUS_CYCLE = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    private CurrentBaselineStockService $stock;

    private string $actorId;

    private string $warehouseId;

    /** @var list<string> item_id theo đúng thứ tự tạo (16 item PRODUCT ngày 1-4). */
    private array $itemPool = [];

    public function run(): void
    {
        // KHÔNG dùng 1 guard chặn toàn bộ ở đây — mỗi bước con (receipt/issue/
        // warranty/import/label/reversal) tự guard theo khóa tự nhiên riêng
        // (doc_no/case_code/import_no), để 1 lần chạy lỗi giữa chừng vẫn resume
        // đúng ở bước còn thiếu khi `db:seed` chạy lại, thay vì bị early-return
        // vĩnh viễn chỉ vì PN-RPT-01 (bước đầu tiên) đã tồn tại.
        $alreadyFullySeeded = DB::table('stock_documents')->where('doc_no', self::GUARD_DOC_NO)->exists()
            && DB::table('warranty_case')->where('warranty_case_code', 'WC-RPT-06')->exists();
        if ($alreadyFullySeeded) {
            $this->command?->info('OperationalReportRangeSeeder: dữ liệu báo cáo vận hành mẫu đã tồn tại đủ, bỏ qua.');

            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            $this->command?->warn('OperationalReportRangeSeeder: thiếu kho/user seed; chạy Warehouse*/Auth*Seeder trước.');

            return;
        }

        $this->stock = app(CurrentBaselineStockService::class);
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

        $productSku = $this->ensureSku(self::PRODUCT_SKU, 'Máy bơm PV mẫu báo cáo vận hành', 'PRODUCT', true, 'HOANAM', 'PUMP', 'MDL-HN-01', 'SOLAR', 'KIT');
        $componentSku = $this->ensureSku(self::COMPONENT_SKU, 'Bo mạch điều khiển mẫu báo cáo vận hành', 'COMPONENT', false, 'HOANAM', 'SPARE_PART', 'MDL-HN-01', null, 'BARE');

        $this->backfillAgencyMarketRegion();

        $boxId = $this->ensureContainer('BOX-SEED-RPT-001', 'BOX', 'EMPTY', $componentSku);

        // 3 import đứng riêng (KHÔNG gắn outbound_request nào) để Tab4 có đủ
        // trạng thái UPLOADED/VALIDATED/REJECTED (dữ liệu gốc chỉ có CONSUMED).
        $this->seedStandaloneImport('IMP-RPT-STANDALONE-01', 'UPLOADED', $productSku, 0);
        $this->seedStandaloneImport('IMP-RPT-STANDALONE-02', 'VALIDATED', $productSku, 4);
        $this->seedStandaloneImport('IMP-RPT-STANDALONE-03', 'REJECTED', $productSku, 8);

        $warrantyCaseIds = [];
        $warrantyCaseIndex = 0;
        $importRotation = ['IMP-RPT-ROTATE-01', 'IMP-RPT-ROTATE-02', 'IMP-RPT-ROTATE-03', 'IMP-RPT-ROTATE-04'];

        for ($i = 0; $i < self::DAYS; $i++) {
            $date = Carbon::parse(self::START_DATE)->addDays($i)->setTime(8 + ($i % 9), 30, 0);
            Carbon::setTestNow($date);

            try {
                $docNo = 'PN-RPT-'.sprintf('%02d', $i + 1);
                $docAlreadyExists = DB::table('stock_documents')->where('doc_no', $docNo)->exists();

                if ($i < 4) {
                    $serials = [];
                    for ($s = 1; $s <= 4; $s++) {
                        $serials[] = 'SN-HN-SEED-RPT-'.sprintf('%02d', $i + 1).'-'.sprintf('%02d', $s);
                    }

                    if ($docAlreadyExists) {
                        // Resume: chứng từ ngày này đã tạo/post ở lần chạy trước
                        // (lỗi xảy ra ở bước sau trong cùng lần chạy đó) — chỉ cần
                        // nạp lại item_id theo đúng thứ tự serial để itemPool khớp
                        // với lần chạy gốc, KHÔNG được scan/post lại (document đã
                        // POSTED, trigger immutable sẽ chặn mọi thao tác ghi thêm).
                        foreach ($serials as $serial) {
                            $this->itemPool[] = (string) DB::table('item_instances')->where('serial_number', $serial)->value('item_id');
                        }
                    } else {
                        [$docId, $lineId] = $this->createDocument('receipt', $docNo, [
                            ['product_id' => $productSku, 'qty_planned' => count($serials)],
                        ], ['source_name' => 'Nhà máy Hoa Nam — lô báo cáo vận hành ngày '.($i + 1)]);
                        $itemIds = $this->scanCandidateSerials($docId, $lineId, $productSku, $serials);
                        foreach ($itemIds as $idx => $itemId) {
                            $this->ensureRfid($itemId, 'RFID-HN-SEED-RPT-'.sprintf('%02d', $i + 1).'-'.sprintf('%02d', $idx + 1));
                            $this->itemPool[] = $itemId;
                        }
                    }
                } elseif (! $docAlreadyExists) {
                    $qty = 8 + $i;
                    [$docId, $lineId] = $this->createDocument('receipt', $docNo, [
                        ['product_id' => $componentSku, 'qty_planned' => $qty],
                    ], ['source_name' => 'Nhà cung cấp linh kiện Hoa Nam — lô báo cáo vận hành ngày '.($i + 1)]);
                    $this->stock->scanStockDocument($docId, 'receipt', [
                        'line_id' => $lineId,
                        'quantity' => $qty,
                        'container_id' => $boxId,
                    ], $this->actorId);
                    $this->postDocument($docId, 'receipt');

                    DB::table('container_items')->insert([
                        'container_item_id' => (string) Str::uuid(),
                        'container_id' => $boxId,
                        'item_id' => null,
                        'sku_id' => $componentSku,
                        'quantity' => $qty,
                        'status' => 'ACTIVE',
                        'is_active' => true,
                        'source_type' => 'STOCK_DOCUMENT',
                        'source_id' => $docId,
                        'placed_at' => now(),
                        'placed_by' => $this->actorId,
                        'created_by' => $this->actorId,
                        'updated_by' => $this->actorId,
                        'version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Phiếu xuất trong ngày — chỉ 14 item đầu (index 0..13) được xuất,
                // 2 item cuối (14,15) CỐ Ý để nguyên IN_STOCK cho khối reversal cuối.
                // Guard resume: bỏ qua nếu PX-RPT-xx của ngày này đã POSTED rồi.
                if (isset($this->itemPool[$i]) && ! DB::table('stock_documents')->where('doc_no', 'PX-RPT-'.sprintf('%02d', $i + 1))->exists()) {
                    $agencyCode = array_keys(self::AGENCY_NAMES)[$i % 5];
                    [$agencyName, $agencyAddress] = self::AGENCY_NAMES[$agencyCode];
                    $agencyId = DB::table('agencies')->where('agency_code', $agencyCode)->value('agency_id');
                    $importNo = $importRotation[$i % 4];
                    $this->ensureRotationImport($importNo);

                    $requestNo = 'TOA-RPT-'.sprintf('%02d', $i + 1);
                    $requestId = $this->createOutboundRequest($requestNo, $productSku, 1, $importNo, $agencyId);
                    [$issueDocId, $issueLineId] = $this->createDocument('issue', 'PX-RPT-'.sprintf('%02d', $i + 1), [
                        ['product_id' => $productSku, 'qty_planned' => 1],
                    ], [
                        'outbound_request_id' => $requestId,
                        'agency_id' => $agencyId,
                        'recipient_name' => $agencyName,
                        'recipient_address' => $agencyAddress,
                    ]);

                    $this->stock->scanStockDocument($issueDocId, 'issue', [
                        'line_id' => $issueLineId,
                        'quantity' => 1,
                        'item_id' => $this->itemPool[$i],
                    ], $this->actorId);

                    $this->markLabelPrinted($issueDocId, $productSku);
                    $this->postDocument($issueDocId, 'issue');
                }

                // Warranty — 6 case, 1 mỗi ~2 ngày, dùng item đã ISSUED trước đó.
                if (in_array($i, [2, 5, 7, 9, 11, 13], true) && $warrantyCaseIndex < 6) {
                    $caseNo = $warrantyCaseIndex + 1;
                    $useItemDayIndex = [2 => 0, 5 => 1, 7 => 2, 9 => 3, 11 => 4][$i] ?? null;
                    $defectCode = self::DEFECT_CYCLE[$warrantyCaseIndex];
                    $status = self::WARRANTY_STATUS_CYCLE[$warrantyCaseIndex];
                    $isTempOnly = $status === 'CANCELLED';

                    $caseId = $this->seedWarrantyCaseVariant(
                        'WC-RPT-'.sprintf('%02d', $caseNo),
                        $status,
                        $defectCode,
                        $isTempOnly ? null : ($useItemDayIndex !== null ? ($this->itemPool[$useItemDayIndex] ?? null) : null),
                        $isTempOnly ? null : $productSku,
                        $isTempOnly,
                    );
                    $warrantyCaseIds[] = $caseId;
                    $warrantyCaseIndex++;

                    // Component Issue (WARRANTY) cho 3 case đầu — nối tiếp warranty
                    // case ngay khi vừa tạo, box đã có đủ tồn từ các ngày trước.
                    if (count($warrantyCaseIds) <= 3 && $i >= 5) {
                        $componentIssueNo = 'CID-RPT-'.sprintf('%02d', count($warrantyCaseIds));
                        $this->seedComponentIssue($componentIssueNo, $caseId, $componentSku, $boxId, count($warrantyCaseIds));
                    }
                }

                // Tab5 — biến thể label chưa in / in lỗi (document DRAFT, không post).
                if ($i === 6) {
                    $this->seedExtraLabelVariant('PX-RPT-LBL-01', 'TOA-RPT-LBL-01', $productSku, 'PENDING_PRINT', false);
                }
                if ($i === 10) {
                    $this->seedExtraLabelVariant('PX-RPT-LBL-02', 'TOA-RPT-LBL-02', $productSku, 'PRINT_FAILED', true);
                }

                // Tab8 — phiếu nhập DRAFT thiếu evidence (source_name rỗng, không
                // attachment) để "Chứng từ cần bổ sung" có dữ liệu.
                if ($i === 3) {
                    $this->seedPendingEvidenceReceipt('PN-RPT-PENDING-01', $componentSku);
                }
                if ($i === 9) {
                    $this->seedPendingEvidenceReceipt('PN-RPT-PENDING-02', $componentSku);
                }
            } finally {
                Carbon::setTestNow();
            }
        }

        // Reversal — 2 item PRODUCT cuối (index 14,15) CỐ Ý chưa Issue/Warranty ở
        // trên, còn IN_STOCK nên đảo được qua đúng CurrentBaselineReversalService.
        $lastDate = Carbon::parse(self::START_DATE)->addDays(self::DAYS - 1)->setTime(16, 0, 0);
        Carbon::setTestNow($lastDate);
        try {
            if (isset($this->itemPool[14])) {
                $this->seedReversalVariant('PN-RPT-04', $this->itemPool[14], 'Đảo phiếu mẫu báo cáo vận hành — hiệu chỉnh #1.');
            }
            if (isset($this->itemPool[15])) {
                $this->seedReversalVariant('PN-RPT-04', $this->itemPool[15], 'Đảo phiếu mẫu báo cáo vận hành — hiệu chỉnh #2.');
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info('OperationalReportRangeSeeder: đã bổ sung dữ liệu báo cáo vận hành trải 2026-08-16..2026-08-29.');
    }

    // ------------------------------------------------------------------
    // Agencies (Tab7)
    // ------------------------------------------------------------------

    private function backfillAgencyMarketRegion(): void
    {
        foreach (self::AGENCY_NAMES as $code => [$name, $address, $market, $region]) {
            DB::table('agencies')
                ->where('agency_code', $code)
                ->whereNull('market')
                ->update(['market' => $market, 'region' => $region, 'updated_at' => now()]);
        }
    }

    // ------------------------------------------------------------------
    // Import quality (Tab4)
    // ------------------------------------------------------------------

    private function seedStandaloneImport(string $importNo, string $status, string $skuId, int $dayOffset): void
    {
        if (DB::table('outbound_request_imports')->where('import_no', $importNo)->exists()) {
            return;
        }

        $date = Carbon::parse(self::START_DATE)->addDays($dayOffset)->setTime(10, 0, 0);
        Carbon::setTestNow($date);
        try {
            $importId = (string) Str::uuid();
            DB::table('outbound_request_imports')->insert([
                'id' => $importId,
                'import_no' => $importNo,
                'source_file_name' => 'toa-xuat-bao-cao-van-hanh.xlsx',
                'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
                'source_checksum' => hash('sha256', 'toa-xuat-bao-cao-van-hanh-'.$importNo),
                'status' => $status,
                'total_rows' => 3,
                'valid_rows' => $status === 'REJECTED' ? 1 : 3,
                'error_rows' => $status === 'REJECTED' ? 2 : 0,
                ...(Schema::hasColumn('outbound_request_imports', 'uploaded_at') ? [
                    'uploaded_at' => now(),
                    'uploaded_by' => $this->actorId,
                ] : []),
                'validated_at' => $status !== 'UPLOADED' ? now() : null,
                'consumed_at' => null,
                'consumed_by' => null,
                'version' => 1,
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rowStatuses = ['VALID', 'ERROR', 'WARNING'];
            foreach ($rowStatuses as $idx => $rowStatus) {
                DB::table('outbound_request_import_rows')->insert([
                    'id' => (string) Str::uuid(),
                    'import_id' => $importId,
                    'row_no' => $idx + 1,
                    'raw_payload' => json_encode(['sku_id' => $skuId, 'required_qty' => $idx + 1], JSON_UNESCAPED_UNICODE),
                    'normalized_payload' => json_encode([
                        'import_no' => $importNo,
                        'sku_id' => $skuId,
                        'required_qty' => $idx + 1,
                        'recipient_name' => 'Khách hàng mẫu báo cáo vận hành '.($idx + 1),
                    ], JSON_UNESCAPED_UNICODE),
                    'validation_status' => $rowStatus,
                    'validation_errors' => $rowStatus === 'ERROR'
                        ? json_encode(['recipient_name' => 'Thiếu tên người nhận'], JSON_UNESCAPED_UNICODE)
                        : json_encode([], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function ensureRotationImport(string $importNo): string
    {
        $existing = DB::table('outbound_request_imports')->where('import_no', $importNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = (string) Str::uuid();
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => $importNo,
            'source_file_name' => 'toa-xuat-bao-cao-van-hanh.xlsx',
            'source_key' => 'seed/'.strtolower($importNo).'.xlsx',
            'source_checksum' => hash('sha256', 'toa-xuat-bao-cao-van-hanh-'.$importNo),
            'status' => 'CONSUMED',
            'total_rows' => 4,
            'valid_rows' => 4,
            'error_rows' => 0,
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

    // ------------------------------------------------------------------
    // Warranty (Tab6)
    // ------------------------------------------------------------------

    private function seedWarrantyCaseVariant(
        string $caseCode,
        string $status,
        string $defectCode,
        ?string $itemId,
        ?string $skuId,
        bool $tempOnly,
    ): string {
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
            'temporary_item_code' => $tempOnly ? 'TEMP-'.$caseCode : null,
            'identification_status' => $tempOnly ? 'TEMP_ONLY' : 'IDENTIFIED',
            'missing_code_reason' => $tempOnly ? 'Khách làm mất tem/mã vật lý, không tra được serial gốc.' : null,
            'manual_product_description' => $tempOnly ? 'Máy bơm PV mẫu báo cáo vận hành (mô tả thủ công do mất mã).' : null,
            'sales_trace_status' => $tempOnly ? 'NOT_TRACEABLE' : 'TRACEABLE',
            'defect_id' => $defectId,
            'customer_name' => 'Khách hàng báo cáo vận hành '.$caseCode,
            'customer_phone' => '09'.substr(str_pad((string) crc32($caseCode), 8, '0', STR_PAD_LEFT), 0, 8),
            'customer_address' => 'Địa chỉ khách hàng mẫu '.$caseCode,
            'purchase_date' => now()->subMonths(2)->toDateString(),
            'status' => $status,
            'condition_status' => 'DEFECTIVE',
            'description' => 'Case mẫu báo cáo vận hành — trạng thái '.$status.'.',
            'reported_defect' => 'Khách báo lỗi mẫu cho case '.$caseCode.'.',
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
            'note' => 'Tiếp nhận mẫu báo cáo vận hành.',
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
                'note' => 'Cập nhật trạng thái mẫu báo cáo vận hành sang '.$status.'.',
                'created_by' => $this->actorId,
                'event_at' => now(),
            ]);
        }

        AuditLogger::record(
            action: 'WARRANTY_CASE_RECEIVED',
            objectType: 'warranty_case',
            objectId: $caseId,
            actorId: $this->actorId,
            requestId: 'seed-rpt-warranty-'.strtolower($caseCode),
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
    }

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
            requestId: 'seed-rpt-'.strtolower($componentIssueNo),
            afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'stock_effect' => 'NONE'],
        );

        app(ComponentContainerSubledgerService::class)->consumeDocument($documentId);
        $key = 'seed-rpt-component-issue-'.$documentId;
        $this->stock->postComponentIssue($documentId, $this->actorId, $key, sha1($key));
    }

    // ------------------------------------------------------------------
    // Packing label variants (Tab5)
    // ------------------------------------------------------------------

    private function seedExtraLabelVariant(string $docNo, string $requestNo, string $skuId, string $labelStatus, bool $withFailedPrintEvent): void
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }

        $agencyCode = 'AG-001';
        [$agencyName, $agencyAddress] = self::AGENCY_NAMES[$agencyCode];
        $agencyId = DB::table('agencies')->where('agency_code', $agencyCode)->value('agency_id');
        $requestId = $this->createOutboundRequest($requestNo, $skuId, 1, $this->ensureRotationImport('IMP-RPT-ROTATE-01'), $agencyId);

        [$documentId] = $this->createDocument('issue', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 1],
        ], [
            'outbound_request_id' => $requestId,
            'agency_id' => $agencyId,
            'recipient_name' => $agencyName,
            'recipient_address' => $agencyAddress,
        ]);

        $labelId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $labelId,
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode([
                'source' => 'seeder-rpt',
                'label_code' => 'PL-'.$docNo,
                'outbound_no' => $docNo,
                'outbound_document_id' => $documentId,
                'sku_id' => $skuId,
                'item_ids' => [],
            ], JSON_UNESCAPED_UNICODE),
            'status' => $labelStatus,
            'applied_at' => null,
            'applied_by' => null,
            'version' => 1,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withFailedPrintEvent) {
            $printerId = DB::table('printer_configs')->value('id');
            DB::table('label_print_events')->insert([
                'id' => (string) Str::uuid(),
                'label_snapshot_id' => $labelId,
                'printer_config_id' => $printerId,
                'status' => 'FAILED',
                'error_message' => 'Máy in mẫu báo cáo vận hành hết giấy (dữ liệu demo).',
                'printed_at' => null,
                'created_at' => now(),
                'created_by' => $this->actorId,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
                'version' => 0,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Pending evidence (Tab8)
    // ------------------------------------------------------------------

    private function seedPendingEvidenceReceipt(string $docNo, string $skuId): void
    {
        if (DB::table('stock_documents')->where('doc_no', $docNo)->exists()) {
            return;
        }

        $this->createDocument('receipt', $docNo, [
            ['product_id' => $skuId, 'qty_planned' => 5],
        ], ['source_name' => null]);
    }

    // ------------------------------------------------------------------
    // Reversal (Tab1)
    // ------------------------------------------------------------------

    private function seedReversalVariant(string $receiptDocNo, string $itemId, string $reason): void
    {
        $receiptId = DB::table('stock_documents')->where('doc_no', $receiptDocNo)->value('id');
        if ($receiptId === null) {
            return;
        }

        if (DB::table('reversal_lines')->where('item_id', $itemId)->exists()) {
            return;
        }

        if (DB::table('item_instances')->where('item_id', $itemId)->value('item_status') !== 'IN_STOCK') {
            return;
        }

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $receiptId)
            ->where('movement_type', 'RECEIPT')
            ->first();
        if ($movement === null) {
            return;
        }

        $version = (int) DB::table('stock_documents')->where('id', $receiptId)->value('version');
        $key = 'seed-rpt-reversal-'.$itemId;

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
    // Helpers dùng chung (copy shape từ WarehouseBusinessDataSeeder — cố ý
    // không tái sử dụng trực tiếp để 2 seeder độc lập guard idempotency).
    // ------------------------------------------------------------------

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
            'note' => $extra['note'] ?? 'Dữ liệu mẫu báo cáo vận hành (seeder)',
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
            requestId: 'seed-rpt-'.strtolower($docNo),
            afterData: ['doc_no' => $docNo, 'status' => 'DRAFT', 'version' => 1],
        );

        return [$documentId, $lineIds[0] ?? null];
    }

    private function postDocument(string $documentId, string $type): void
    {
        $version = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');
        DB::table('stock_documents')->where('id', $documentId)->update([
            'version' => $version + 1,
            'updated_by' => $this->actorId,
            'updated_at' => now(),
        ]);

        $key = 'seed-rpt-'.$type.'-'.$documentId;
        $this->stock->postStockDocument($documentId, $type, $this->actorId, $key, sha1($key));
    }

    /**
     * @param  list<string>  $serials
     * @return list<string>
     */
    private function scanCandidateSerials(string $documentId, string $lineId, string $skuId, array $serials): array
    {
        $skuCode = (string) DB::table('sku')->where('sku_id', $skuId)->value('sku_code');
        foreach ($serials as $serial) {
            $this->stock->scanStockDocument($documentId, 'receipt', [
                'line_id' => $lineId,
                'quantity' => 1,
                'code_value' => "HN1|SKU={$skuCode}|ITEM={$serial}",
            ], $this->actorId);
        }

        $this->postDocument($documentId, 'receipt');

        return array_map(
            fn (string $serial): string => (string) DB::table('item_instances')
                ->where('serial_number', $serial)
                ->value('item_id'),
            $serials
        );
    }

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

    /** RFID ACTIVE + hardware_uid bắt buộc cho PRODUCT trước Post Issue. */
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
            'hardware_uid' => 'HW-RPT-'.strtoupper(Str::random(10)),
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

    /** Toa xuất (outbound_requests) — nguồn bắt buộc của mọi phiếu xuất theo BP-WMS-04. */
    private function createOutboundRequest(string $requestNo, string $skuId, int $qty, string $importNo, ?string $agencyId = null): string
    {
        $existing = DB::table('outbound_requests')->where('request_no', $requestNo)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $importId = $this->ensureRotationImport($importNo);
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

    /**
     * Packing Label document-level đã in — cùng quy ước 'PL-'.doc_no với
     * AlignedOutboundFlowController::createPackingLabelSnapshot().
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

        $docNo = (string) (DB::table('stock_documents')->where('id', $documentId)->value('doc_no') ?? $documentId);
        $labelCode = 'PL-'.$docNo;

        $insert = [
            'id' => (string) Str::uuid(),
            'outbound_item_match_id' => null,
            'outbound_document_id' => $documentId,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode([
                'source' => 'seeder-rpt',
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
                'status' => 'PRINTED',
                'error_message' => null,
                'printed_at' => now(),
                'created_at' => now(),
                'created_by' => $this->actorId,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
                'version' => 0,
            ]);
        }
    }
}
