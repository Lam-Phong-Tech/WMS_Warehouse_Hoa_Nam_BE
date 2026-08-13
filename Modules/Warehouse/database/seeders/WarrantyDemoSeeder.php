<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ContainerItem;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;

/**
 * Seed demo cho luồng 04 · Bảo hành & linh kiện (dev/local), nối tiếp DemoDataSeeder.
 *
 * Bám nghiệp vụ docs (BP-WMS-05/06/08/09, ERD §45-§50):
 *   1. Máy phải được XUẤT KHO (Post Issue) trước khi tiếp nhận bảo hành:
 *      tạo phiếu xuất POSTED + movement ISSUE (-1) + trừ balance + item ISSUED.
 *   2. Warranty case stock-neutral: item ISSUED -> IN_WARRANTY (không movement);
 *      trả khách -> RETURNED_TO_CUSTOMER, không quay lại tồn bán.
 *   3. Component issue chỉ purpose=WARRANTY gắn case active; khi POSTED mới có
 *      movement COMPONENT_ISSUE trừ tồn + trừ subledger box (container_items).
 *   4. Ledger (movements) = Balance = Subledger sau seed — không sinh incident
 *      trong báo cáo đối soát BP-WMS-07.
 *   5. Event ERD §46: RECEIVE/CHECK/REPAIR/COMPLETE/RETURN/CANCEL; version case
 *      = 1 (tạo) + số lần chuyển trạng thái, khớp optimistic-locking If-Match.
 */
class WarrantyDemoSeeder extends Seeder
{
    private string $actorId;

    private string $warehouseId;

    public function run(): void
    {
        $this->call(DemoDataSeeder::class);

        if (DB::table('warranty_case')->where('warranty_case_code', 'WC-DEMO-0001')->exists()) {
            $this->command?->info('WarrantyDemoSeeder: dữ liệu demo bảo hành đã tồn tại, bỏ qua.');

            return;
        }

        DB::transaction(function (): void {
            $admin = User::query()->where('username', 'admin')->first()
                ?? User::query()->orderBy('created_at')->first();
            if (! $admin) {
                throw new \RuntimeException('WarrantyDemoSeeder cần ít nhất 1 user (chạy AuthDatabaseSeeder trước).');
            }
            $warehouse = Warehouse::query()->orderBy('created_at')->first();
            if (! $warehouse) {
                throw new \RuntimeException('WarrantyDemoSeeder cần warehouse (chạy WarehouseDatabaseSeeder trước).');
            }
            $this->actorId = (string) $admin->user_id;
            $this->warehouseId = (string) $warehouse->id;

            // BP-WMS-05: xuất kho 4 máy cho khách — điều kiện tiên quyết của BP-WMS-08.
            $this->issueMachines([
                'ISS-2026-0001' => [
                    ['serial' => 'KUB3301-SN-0001', 'sku' => 'KUB-L3301-TRAC'],
                    ['serial' => 'KUB4701-SN-0001', 'sku' => 'KUB-L4701-TRAC'],
                ],
                'ISS-2026-0002' => [
                    ['serial' => 'HONGX160-SN-0001', 'sku' => 'HON-GX160-ENG'],
                    ['serial' => 'YANYPB30-SN-0001', 'sku' => 'YAN-YPB30-PUMP'],
                ],
            ]);

            $defects = DB::table('defect_catalogs')->pluck('defect_id', 'defect_code');

            // Case 1 · IDENTIFIED · CHECKING (đang kiểm tra, có phiếu linh kiện SCANNING).
            $case1 = $this->makeCase([
                'code' => 'WC-DEMO-0001',
                'serial' => 'KUB3301-SN-0001',
                'defect_id' => $defects['DEF-004'] ?? null,
                'customer_name' => 'Nguyễn Văn Hùng',
                'customer_phone' => '0912345678',
                'customer_address' => 'Thôn 3, xã Ea Kar, Đắk Lắk',
                'purchase_date' => now()->subDays(65)->toDateString(),
                'reported_defect' => 'Máy không khởi động được, đề không quay dù bình điện mới sạc.',
                'accessories_received' => 'Chìa khóa, sách hướng dẫn, 1 bugi dự phòng',
                'received_condition' => 'Trầy xước nhẹ vỏ capo, bánh dính bùn',
                'transitions' => ['CHECKING'],
                'received_days_ago' => 3,
            ]);
            $this->makeComponentIssue($case1, 'CID-DEMO-0002', 'SCANNING', [
                ['sku' => 'KUB-L3310-BLADE', 'required' => 1, 'scanned' => 0],
            ], daysAgo: 1);

            // Case 2 · IDENTIFIED · REPAIRING (đã chẩn đoán, đã Post 2 piston từ BOX).
            $case2 = $this->makeCase([
                'code' => 'WC-DEMO-0002',
                'serial' => 'HONGX160-SN-0001',
                'defect_id' => $defects['DEF-004'] ?? null,
                'customer_name' => 'Trần Thị Mai',
                'customer_phone' => '0987654321',
                'customer_address' => '25 Lê Duẩn, TP. Buôn Ma Thuột',
                'purchase_date' => now()->subDays(120)->toDateString(),
                'reported_defect' => 'Động cơ nổ không đều rồi chết máy, kéo giật không nổ lại.',
                'confirmed_defect' => 'Piston bó kẹt do thiếu nhớt, xy-lanh trầy — cần thay piston.',
                'accessories_received' => 'Không kèm phụ kiện',
                'received_condition' => 'Vỏ máy nguyên vẹn, có mùi khét nhẹ',
                'transitions' => ['CHECKING', 'REPAIRING'],
                'received_days_ago' => 5,
            ]);
            $this->makeComponentIssue($case2, 'CID-DEMO-0001', 'POSTED', [
                ['sku' => 'HON-GX160-PISTON', 'required' => 2, 'scanned' => 2, 'from_box' => 'CT-BOX-001'],
            ], daysAgo: 2);

            // Case 3 · IDENTIFIED · COMPLETED (sửa xong, chờ khách nhận).
            $this->makeCase([
                'code' => 'WC-DEMO-0003',
                'serial' => 'KUB4701-SN-0001',
                'defect_id' => $defects['DEF-002'] ?? null,
                'customer_name' => 'Phạm Quốc Bảo',
                'customer_phone' => '0905112233',
                'customer_address' => 'Xã Cư Jút, Đắk Nông',
                'purchase_date' => now()->subDays(200)->toDateString(),
                'reported_defect' => 'Nứt vỏ hộp số sau va chạm, rò rỉ nhớt.',
                'confirmed_defect' => 'Nứt vỏ hộp số — đã hàn gia cố và thay ron, chạy thử đạt.',
                'accessories_received' => 'Chìa khóa',
                'received_condition' => 'Vỏ hộp số nứt dài ~5cm, nhớt rò',
                'transitions' => ['CHECKING', 'REPAIRING', 'COMPLETED'],
                'received_days_ago' => 7,
            ]);

            // Case 4 · IDENTIFIED · RETURNED (đã trả khách — item RETURNED_TO_CUSTOMER).
            $this->makeCase([
                'code' => 'WC-DEMO-0004',
                'serial' => 'YANYPB30-SN-0001',
                'defect_id' => $defects['DEF-001'] ?? null,
                'customer_name' => 'Lê Thành Long',
                'customer_phone' => '0938445566',
                'customer_address' => 'Huyện Krông Pắc, Đắk Lắk',
                'purchase_date' => now()->subDays(30)->toDateString(),
                'reported_defect' => 'Bơm kêu to bất thường khi chạy tải lớn.',
                'confirmed_defect' => 'Bạc đạn trục bơm mòn — đã thay và cân chỉnh lại cánh bơm.',
                'accessories_received' => 'Ống hút + bầu lọc',
                'received_condition' => 'Trầy xước ngoại quan nhẹ',
                'transitions' => ['CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED'],
                'received_days_ago' => 10,
            ]);

            // Case 5 · TEMP_ONLY · RECEIVED (mất tem mã — BP-WMS-09).
            $this->makeCase([
                'code' => 'WC-DEMO-0005',
                'serial' => null,
                'temporary_item_code' => 'TEMP-DEMO-0005',
                'defect_id' => $defects['DEF-004'] ?? null,
                'customer_name' => 'Võ Thị Cúc',
                'customer_phone' => '0916778899',
                'customer_address' => 'Thị trấn Phước An, Đắk Lắk',
                'reported_defect' => 'Máy không lên nguồn, khách không còn tem mã trên máy.',
                'missing_code_reason' => 'Tem mã dán trên máy bị bong tróc do để ngoài trời lâu ngày.',
                'manual_product_description' => 'Máy bơm nước cũ vỏ inox màu xanh, tay cầm quấn băng keo đen, không còn tem.',
                'accessories_received' => 'Không kèm phụ kiện',
                'received_condition' => 'Vỏ máy gỉ sét nhiều chỗ',
                'transitions' => [],
                'received_days_ago' => 1,
            ]);

            // Case 6 · TEMP_ONLY · CANCELLED (khách đổi ý — bắt buộc cancel_reason).
            $this->makeCase([
                'code' => 'WC-DEMO-0006',
                'serial' => null,
                'temporary_item_code' => 'TEMP-DEMO-0006',
                'defect_id' => $defects['DEF-005'] ?? null,
                'customer_name' => 'Đặng Minh Trí',
                'customer_phone' => '0977001122',
                'reported_defect' => 'Màn hình điều khiển báo lỗi E-3 liên tục.',
                'missing_code_reason' => 'Khách mua lại máy cũ, không có giấy tờ/tem mã.',
                'manual_product_description' => 'Bộ điều khiển máy gặt cũ, vỏ nhựa xám, màn LCD 4 dòng.',
                'cancel_reason' => 'Khách đổi ý xin nhận lại máy, không sửa vì chi phí dự kiến cao.',
                'transitions' => ['CANCELLED'],
                'received_days_ago' => 4,
            ]);
        }, 5);

        $this->command?->info('WarrantyDemoSeeder: đã seed 6 warranty case + 2 component issue + 2 phiếu xuất demo.');
    }

    /**
     * Xuất kho máy: phiếu issue POSTED + movement ISSUE + trừ balance + item ISSUED.
     *
     * @param  array<string, array<int, array{serial: string, sku: string}>>  $documents
     */
    private function issueMachines(array $documents): void
    {
        $now = now();
        foreach ($documents as $docNo => $lines) {
            if (StockDocument::query()->where('doc_no', $docNo)->exists()) {
                continue;
            }
            // recipient_* bắt buộc theo canonical outbound_documents (BR-MATCH:
            // người nhận đến từ toa xuất). forceCreate vì cột không nằm trong fillable.
            $document = (new StockDocument)->forceFill([
                'doc_type' => StockDocument::TYPE_ISSUE,
                'doc_no' => $docNo,
                'doc_date' => $now->copy()->subDays(12)->toDateString(),
                'status' => StockDocument::STATUS_POSTED,
                'src_warehouse_id' => $this->warehouseId,
                'recipient_name' => $docNo === 'ISS-2026-0001' ? 'Nguyễn Văn Hùng' : 'Trần Thị Mai',
                'recipient_address' => $docNo === 'ISS-2026-0001'
                    ? 'Thôn 3, xã Ea Kar, Đắk Lắk'
                    : '25 Lê Duẩn, TP. Buôn Ma Thuột',
                'note' => 'Xuất bán demo — phục vụ dữ liệu luồng bảo hành',
                'posted_by' => $this->actorId,
                'posted_at' => $now->copy()->subDays(12),
                'created_by' => $this->actorId,
            ]);
            $document->save();

            $lineNo = 1;
            foreach ($lines as $line) {
                $sku = Sku::query()->where('sku_code', $line['sku'])->firstOrFail();
                StockDocumentLine::create([
                    'stock_document_id' => $document->id,
                    'line_no' => $lineNo++,
                    'product_id' => $sku->sku_id,
                    'qty_planned' => 1,
                    'qty_actual' => 1,
                    'scanned_qty' => 1,
                ]);

                ItemInstance::query()
                    ->where('serial_number', $line['serial'])
                    ->update(['item_status' => 'ISSUED', 'updated_at' => $now]);

                $this->appendMovement(
                    businessKey: 'ISSUE:'.$line['sku'].':'.$this->warehouseId,
                    movementType: StockMovement::ISSUE_POSTED,
                    skuId: (string) $sku->sku_id,
                    qtyDelta: -1,
                    occurredAt: $now->copy()->subDays(12),
                );
            }
        }
    }

    /**
     * Movement append-only + cập nhật balance projection cùng lúc (DEC-INV-001):
     * qty_before/qty_after nối chuỗi theo movement gần nhất của SKU.
     */
    private function appendMovement(string $businessKey, string $movementType, string $skuId, int $qtyDelta, $occurredAt): void
    {
        if (StockMovement::query()->where('business_key', $businessKey)->exists()) {
            return;
        }

        $last = StockMovement::query()
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $skuId)
            ->orderByDesc('occurred_at')
            ->first();
        $before = (int) ($last->qty_after ?? 0);

        $payload = [
            'movement_no' => 'SM-'.strtoupper(Str::substr((string) Str::uuid(), 0, 12)),
            'business_key' => $businessKey,
            'movement_type' => $movementType,
            'warehouse_id' => $this->warehouseId,
            'product_id' => $skuId,
            'qty_before' => $before,
            'qty_delta' => $qtyDelta,
            'qty_after' => $before + $qtyDelta,
            'created_by' => $this->actorId,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'stock_status')) {
            $payload['stock_status'] = 'AVAILABLE';
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'quantity')) {
            // ERD: quantity là độ lớn (>0); chiều tăng/giảm nằm ở qty_delta.
            $payload['quantity'] = abs($qtyDelta);
        }
        $movement = new StockMovement;
        $movement->forceFill($payload)->save();

        InventoryBalance::query()
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $skuId)
            ->where('stock_status', 'AVAILABLE')
            ->update([
                'on_hand_qty' => DB::raw('on_hand_qty + ('.$qtyDelta.')'),
                'available_qty' => DB::raw('available_qty + ('.$qtyDelta.')'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Tạo warranty case + chuỗi event + cập nhật item_status theo state machine.
     *
     * @param  array<string, mixed>  $config
     */
    private function makeCase(array $config): string
    {
        $now = now();
        $receivedAt = $now->copy()->subDays((int) $config['received_days_ago']);
        $transitions = $config['transitions'];
        $finalStatus = $transitions === [] ? 'RECEIVED' : end($transitions);
        $version = 1 + count($transitions);

        $item = null;
        if (! empty($config['serial'])) {
            $item = ItemInstance::query()->where('serial_number', $config['serial'])->firstOrFail();
        }

        $isTerminalReturned = $finalStatus === 'RETURNED';
        $completedAt = in_array('COMPLETED', $transitions, true)
            ? $receivedAt->copy()->addDays(max(1, (int) $config['received_days_ago'] - 2))
            : null;
        $returnedAt = $isTerminalReturned
            ? $receivedAt->copy()->addDays(max(2, (int) $config['received_days_ago'] - 1))
            : null;
        $closedAt = in_array($finalStatus, ['RETURNED', 'CANCELLED'], true)
            ? ($returnedAt ?? $receivedAt->copy()->addDay())
            : null;

        $caseId = (string) Str::uuid();
        DB::table('warranty_case')->insert([
            'warranty_case_id' => $caseId,
            'warranty_case_code' => $config['code'],
            'item_id' => $item?->item_id,
            'inventory_serial_id' => null,
            'product_id' => $item?->sku_id,
            'temporary_item_code' => $config['temporary_item_code'] ?? null,
            'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
            'sales_trace_status' => $item ? 'TRACEABLE' : 'NOT_TRACEABLE',
            'missing_code_reason' => $config['missing_code_reason'] ?? null,
            'manual_product_description' => $config['manual_product_description'] ?? null,
            'defect_id' => $config['defect_id'],
            'customer_name' => $config['customer_name'],
            'customer_phone' => $config['customer_phone'],
            'customer_address' => $config['customer_address'] ?? null,
            'purchase_date' => $config['purchase_date'] ?? null,
            'status' => $finalStatus,
            'description' => $config['reported_defect'],
            'reported_defect' => $config['reported_defect'],
            'confirmed_defect' => $config['confirmed_defect'] ?? null,
            'accessories_received' => $config['accessories_received'] ?? null,
            'received_condition' => $config['received_condition'] ?? null,
            'cancel_reason' => $config['cancel_reason'] ?? null,
            'received_at' => $receivedAt,
            'completed_at' => $completedAt,
            'returned_at' => $returnedAt,
            'closed_at' => $closedAt,
            'created_by' => $this->actorId,
            'version' => $version,
            'legal_hold_flag' => false,
            'created_at' => $receivedAt,
            'updated_at' => $closedAt ?? $now,
        ]);

        // Event ERD §46: RECEIVE khi tạo + 1 event mỗi transition.
        $eventAt = $receivedAt->copy();
        $this->insertEvent($caseId, 'RECEIVE', null, 'RECEIVED', $config['reported_defect'], $eventAt);
        $from = 'RECEIVED';
        $statusEventTypes = [
            'CHECKING' => 'CHECK',
            'REPAIRING' => 'REPAIR',
            'COMPLETED' => 'COMPLETE',
            'RETURNED' => 'RETURN',
            'CANCELLED' => 'CANCEL',
        ];
        $notes = [
            'CHECKING' => 'Bắt đầu kiểm tra chẩn đoán.',
            'REPAIRING' => $config['confirmed_defect'] ?? 'Bắt đầu sửa chữa.',
            'COMPLETED' => 'Hoàn tất sửa chữa, chạy thử đạt yêu cầu.',
            'RETURNED' => 'Đã bàn giao máy cho khách, khách ký nhận.',
            'CANCELLED' => $config['cancel_reason'] ?? 'Hủy case.',
        ];
        foreach ($transitions as $target) {
            $eventAt = $eventAt->copy()->addDay();
            $this->insertEvent($caseId, $statusEventTypes[$target], $from, $target, $notes[$target], min($eventAt, $now));
            $from = $target;
        }

        // BP-WMS-08: item theo case — IN_WARRANTY khi đang xử lý,
        // RETURNED_TO_CUSTOMER khi đã trả; không bao giờ tự quay lại IN_STOCK.
        if ($item) {
            $item->update([
                'item_status' => $isTerminalReturned ? 'RETURNED_TO_CUSTOMER' : 'IN_WARRANTY',
            ]);
        }

        return $caseId;
    }

    private function insertEvent(string $caseId, string $type, ?string $from, string $to, string $note, $at): void
    {
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

    /**
     * Phiếu xuất linh kiện gắn case (BP-WMS-06). POSTED: movement COMPONENT_ISSUE
     * + trừ balance + trừ subledger box; SCANNING: hoàn toàn stock-neutral.
     *
     * @param  array<int, array{sku: string, required: int, scanned: int, from_box?: string}>  $lines
     */
    private function makeComponentIssue(string $caseId, string $docNo, string $status, array $lines, int $daysAgo): void
    {
        if (DB::table('component_issue_documents')->where('component_issue_no', $docNo)->exists()) {
            return;
        }
        $now = now();
        $docDate = $now->copy()->subDays($daysAgo);
        $posted = $status === 'POSTED';
        $documentId = (string) Str::uuid();

        // Guard DB hn_erd_guard_component_issue_post chặn POSTED khi chưa đủ lines
        // (đúng full-post) — nên tạo DRAFT trước, chèn lines/items rồi mới Post.
        DB::table('component_issue_documents')->insert([
            'id' => $documentId,
            'component_issue_no' => $docNo,
            'warehouse_id' => $this->warehouseId,
            'warranty_case_id' => $caseId,
            'document_date' => $docDate->toDateString(),
            'purpose' => 'WARRANTY',
            'status' => 'DRAFT',
            'posted_at' => null,
            'posted_by' => null,
            'note' => $posted ? 'Xuất linh kiện thay thế cho case bảo hành (demo)' : 'Đang gom linh kiện cho case bảo hành (demo)',
            'created_at' => $docDate,
            'created_by' => $this->actorId,
            'updated_at' => $docDate,
            'updated_by' => $this->actorId,
            'version' => 1,
        ]);

        $lineNo = 1;
        foreach ($lines as $line) {
            $sku = Sku::query()->where('sku_code', $line['sku'])->firstOrFail();
            $lineId = (string) Str::uuid();
            DB::table('component_issue_lines')->insert([
                'id' => $lineId,
                'component_issue_document_id' => $documentId,
                'line_no' => $lineNo++,
                'sku_id' => $sku->sku_id,
                'required_qty' => $line['required'],
                'scanned_qty' => $line['scanned'],
                'created_at' => $docDate,
                'created_by' => $this->actorId,
                'updated_at' => $docDate,
                'updated_by' => $this->actorId,
                'version' => 0,
            ]);

            if (($line['scanned'] ?? 0) > 0 && ! empty($line['from_box'])) {
                // Flow scan component-issue validate exists:containers,container_id
                // nên tham chiếu bằng container_id (không dùng cột id canonical).
                $container = DB::table('containers')->where('container_code', $line['from_box'])->first();
                $containerId = $container?->container_id ?? $container?->id;
                DB::table('component_issue_items')->insert([
                    'id' => (string) Str::uuid(),
                    'component_issue_line_id' => $lineId,
                    'item_id' => null,
                    'container_id' => $containerId,
                    'quantity' => $line['scanned'],
                    'status' => $posted ? 'POSTED' : 'ACTIVE',
                    'selected_at' => $docDate,
                    'selected_by' => $this->actorId,
                    'created_at' => $docDate,
                    'created_by' => $this->actorId,
                    'updated_at' => $docDate,
                    'updated_by' => $this->actorId,
                    'version' => 0,
                ]);

                if ($posted) {
                    // Trừ subledger box (FIFO đơn giản: dòng đầu tiên của SKU).
                    $subledger = ContainerItem::query()
                        ->where('container_id', $containerId)
                        ->where('sku_id', $sku->sku_id)
                        ->orderBy('created_at')
                        ->first();
                    $subledger?->update([
                        'quantity' => max(0, (int) $subledger->quantity - (int) $line['scanned']),
                    ]);
                }
            }

            if ($posted) {
                $this->appendMovement(
                    businessKey: 'COMPONENT_ISSUE:'.$docNo.':'.$line['sku'],
                    movementType: StockMovement::COMPONENT_ISSUE,
                    skuId: (string) $sku->sku_id,
                    qtyDelta: -$line['scanned'],
                    occurredAt: $docDate,
                );
            }
        }

        DB::table('component_issue_documents')->where('id', $documentId)->update([
            'status' => $status,
            'posted_at' => $posted ? $docDate : null,
            'posted_by' => $posted ? $this->actorId : null,
            'version' => $posted ? 3 : 2,
            'updated_at' => $docDate,
        ]);
    }
}
