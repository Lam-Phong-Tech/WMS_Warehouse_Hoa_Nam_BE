<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\DocumentStatusHistory;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\Warehouse;

class StockDocumentService
{
    private const PREFIX = [
        StockDocument::TYPE_RECEIPT => 'PN',
        StockDocument::TYPE_ISSUE => 'PX',
        StockDocument::TYPE_TRANSFER => 'DC',
    ];

    public function __construct(private readonly StockLedger $ledger, private readonly ScanResolver $scanResolver) {}

    public function create(string $type, array $data, string $userId): StockDocument
    {
        return DB::transaction(function () use ($type, $data, $userId) {
            $src = $data['src_warehouse_id'] ?? null;
            $dst = $data['dst_warehouse_id'] ?? null;

            $this->assertWarehouseActive($src);
            $this->assertWarehouseActive($dst);
            if (isset($data['doc_no'])) {
                app(InboundDocumentNumber::class)->guardExplicit($data['doc_no']);
                app(OutboundDocumentNumber::class)->guardExplicit($data['doc_no']);
            }

            $doc = StockDocument::create([
                'doc_type' => $type,
                'doc_no' => $data['doc_no'] ?? $this->genCode($type),
                'doc_date' => $data['doc_date'] ?? now()->format('Y-m-d'),
                'status' => StockDocument::STATUS_DRAFT,
                'src_warehouse_id' => $src,
                'dst_warehouse_id' => $dst,
                'note' => $data['note'] ?? null,
                'create_idempotency_key' => $data['create_idempotency_key'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncLines($doc, $data['lines']);
            $this->logStatusHistory($doc, null, StockDocument::STATUS_DRAFT, 'create', $userId);
            AuditLogger::record('CREATE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), afterData: ['doc_no' => $doc->doc_no, 'doc_type' => $doc->doc_type]);

            return $doc->load('lines');
        });
    }

    public function update(StockDocument $doc, array $data, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $data, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT], 'update');
            if (isset($data['doc_no']) && $data['doc_no'] !== $doc->doc_no) {
                app(InboundDocumentNumber::class)->guardExplicit($data['doc_no']);
                app(OutboundDocumentNumber::class)->guardExplicit($data['doc_no']);
            }

            $doc->update(array_filter([
                'doc_no' => $data['doc_no'] ?? null,
                'doc_date' => $data['doc_date'] ?? null,
                'note' => $data['note'] ?? $doc->note,
                'updated_by' => $userId,
            ], fn ($value) => $value !== null));

            if (array_key_exists('lines', $data)) {
                $doc->lines()->delete();
                $this->syncLines($doc, $data['lines']);
            }

            return $doc->fresh('lines');
        });
    }

    public function submit(StockDocument $doc, string $userId): StockDocument
    {
        return $this->startScanning($doc, $userId, 'submit');
    }

    public function approve(StockDocument $doc, string $userId): StockDocument
    {
        return $this->startScanning($doc, $userId, 'approve');
    }

    public function reject(StockDocument $doc, ?string $reason, string $userId): StockDocument
    {
        WarehouseException::invalidStatus('Reject approval is outside the latest MVP baseline.');
    }

    public function cancel(StockDocument $doc, string $userId, ?string $reason): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $reason) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], 'cancel');

            $from = $doc->status;
            $doc->update([
                'status' => StockDocument::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_CANCELLED, 'cancel', $userId, $reason);
            AuditLogger::record('CANCEL', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $doc->fresh();
        });
    }

    public function postReceipt(StockDocument $doc, string $userId, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $idempotencyKey, $requestHash, $operation) {
            $doc = $this->lockDocumentForStockEffect($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_SCANNING], 'post receipt');
            $this->assertWarehouseActive($doc->dst_warehouse_id);
            $this->assertFullPostReady($doc);

            foreach ($doc->lines as $line) {
                $this->ledger->inbound($doc, $line, $userId);
                $this->markInboundItemReceived($line);
                $line->update(['scanned_qty' => $line->qty_planned]);
            }

            $fresh = $this->markPosted($doc, $userId, 'post_receipt');
            $this->recordPostingEvent($operation ?? "post_receipt_{$doc->id}", $idempotencyKey, $requestHash, $fresh, $userId);

            return $fresh;
        });
    }

    public function postIssue(StockDocument $doc, string $userId, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $idempotencyKey, $requestHash, $operation) {
            $doc = $this->lockDocumentForStockEffect($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_SCANNING], 'post issue');
            $this->assertWarehouseActive($doc->src_warehouse_id);
            $this->assertFullPostReady($doc);
            $this->assertProductRfidsReady($doc);

            foreach ($doc->lines as $line) {
                $this->ledger->outbound($doc, $line, $userId);
            }

            $fresh = $this->markPosted($doc, $userId, 'post_issue');
            $this->recordPostingEvent($operation ?? "post_issue_{$doc->id}", $idempotencyKey, $requestHash, $fresh, $userId);

            return $fresh;
        });
    }

    public function validateScanLine(StockDocument $doc, string $lineId, string $qty): array
    {
        return DB::transaction(function () use ($doc, $lineId, $qty) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], 'scan');

            if ($doc->status === StockDocument::STATUS_DRAFT) {
                $this->startScanning($doc, (string) auth('api')->id(), 'scan_start');
                $doc->refresh();
            }

            $line = $doc->lines()->where('id', $lineId)->lockForUpdate()->firstOrFail();
            if (bccomp($qty, '0', 3) <= 0) {
                WarehouseException::invalidStatus('Scan quantity must be greater than zero.');
            }

            $scannedBefore = (string) $line->scanned_qty;
            $scannedAfter = bcadd($scannedBefore, $qty, 3);
            $remainingBefore = bcsub((string) $line->qty_planned, $scannedBefore, 3);
            if (bccomp($qty, $remainingBefore, 3) > 0) {
                WarehouseException::invalidStatus("Scan quantity exceeds remaining quantity on line {$line->line_no}.");
            }

            if ($doc->doc_type === StockDocument::TYPE_RECEIPT) {
                $this->assertWarehouseActive($doc->dst_warehouse_id);
            } else {
                $this->assertWarehouseActive($doc->src_warehouse_id);
                $available = (string) (InventoryBalance::where('warehouse_id', $doc->src_warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->value('available_qty') ?? '0');
                if (bccomp($available, $qty, 3) < 0) {
                    WarehouseException::insufficientStock($line->product->code ?? (string) $line->product_id);
                }
            }

            $line->update(['scanned_qty' => $scannedAfter]);

            return [
                'document_id' => $doc->id,
                'line_id' => $line->id,
                'doc_type' => $doc->doc_type,
                'status' => StockDocument::STATUS_SCANNING,
                'product_id' => $line->product_id,
                'qty' => (float) $qty,
                'qty_planned' => (float) $line->qty_planned,
                'scanned_qty_before' => (float) $scannedBefore,
                'scanned_qty_after' => (float) $scannedAfter,
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'next_stock_action' => $doc->doc_type === StockDocument::TYPE_RECEIPT ? 'post-receipt' : 'post-issue',
            ];
        });
    }

    public function closeRemainder(StockDocument $doc, string $userId, ?string $reason): StockDocument
    {
        WarehouseException::invalidStatus('close_remainder is outside the latest MVP baseline.');
    }

    /**
     * SUPERSEDED — không được dùng lại.
     *
     * Bản cũ đặt stock_documents.status = 'REVERSED' và tự đảo toàn bộ movement gốc.
     * Cả hai đều trái baseline hiện hành:
     *  - BR-REV-01: chứng từ gốc phải GIỮ NGUYÊN POSTED, sửa sai bằng reversal
     *    append-only. SRS §6 cũng không có state 'REVERSED' cho stock document.
     *  - BR-REV-05 / §5.5: mỗi reversal phải chỉ rõ movement + qty_to_reverse > 0.
     *
     * Đường chạy đúng: CurrentBaselineStockFlowController::reverseInbound /
     * reverseOutbound -> CurrentBaselineReversalService (giữ POSTED, bắt buộc lines,
     * có downstream-dependency guard và reversible_qty recheck).
     */
    public function reverseDocument(StockDocument $doc, string $userId, ?string $reason, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        WarehouseException::invalidStatus(
            'reverse_document is outside the latest MVP baseline: BR-REV-01 giữ chứng từ gốc ở POSTED. '
            .'Dùng POST /inbound-documents/{id}/reversals hoặc /outbound-documents/{id}/reversals.'
        );
    }

    private function startScanning(StockDocument $doc, string $userId, string $action): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $action) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], $action);
            if ($doc->lines()->count() < 1) {
                WarehouseException::invalidStatus('Document must have at least one line.');
            }

            if ($doc->status !== StockDocument::STATUS_SCANNING) {
                $from = $doc->status;
                $doc->update(['status' => StockDocument::STATUS_SCANNING, 'submitted_by' => $userId, 'submitted_at' => now()]);
                $this->logStatusHistory($doc, $from, StockDocument::STATUS_SCANNING, $action, $userId);
            }

            return $doc->fresh('lines');
        });
    }

    private function markPosted(StockDocument $doc, string $userId, string $action): StockDocument
    {
        $from = $doc->status;
        $doc->update(['status' => StockDocument::STATUS_POSTED, 'posted_by' => $userId, 'posted_at' => now()]);
        $this->logStatusHistory($doc, $from, StockDocument::STATUS_POSTED, $action, $userId);
        AuditLogger::record('POST', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), beforeData: ['status' => $from], afterData: ['status' => StockDocument::STATUS_POSTED]);

        return $doc->fresh(['lines', 'movements']);
    }

    private function lockDocumentForStockEffect(StockDocument $doc): StockDocument
    {
        return StockDocument::query()
            ->whereKey($doc->getKey())
            ->lockForUpdate()
            ->firstOrFail()
            ->load(['lines.product', 'movements']);
    }

    private function syncLines(StockDocument $doc, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            StockDocumentLine::create([
                'stock_document_id' => $doc->id,
                'line_no' => $index + 1,
                'product_id' => $line['product_id'],
                'item_instance_id' => $line['item_instance_id'] ?? null,
                'qty_planned' => $line['qty_planned'],
                'qty_actual' => $line['qty_planned'],
                'scanned_qty' => 0,
            ]);
        }
    }

    private function assertFullPostReady(StockDocument $doc): void
    {
        foreach ($doc->lines as $line) {
            if (bccomp((string) $line->scanned_qty, (string) $line->qty_planned, 3) !== 0) {
                WarehouseException::invalidStatus("Full-post guard failed on line {$line->line_no}: scanned_qty must equal planned quantity.");
            }
        }
    }

    private function assertProductRfidsReady(StockDocument $doc): void
    {
        foreach ($doc->lines as $line) {
            $skuType = strtoupper((string) ($line->product?->sku_type ?? ''));
            if ($skuType !== 'PRODUCT') {
                continue;
            }

            $hasActiveRfid = PhysicalCode::query()
                ->where('assigned_object_type', 'SKU')
                ->where('assigned_object_id', $line->product_id)
                ->where('carrier_type', 'RFID')
                ->where('status', 'ACTIVE')
                ->whereNotNull('hardware_uid')
                ->exists();

            if (! $hasActiveRfid) {
                WarehouseException::invalidStatus("PRODUCT SKU {$line->product_id} requires active RFID before Post Issue.");
            }
        }
    }

    private function validateInboundScan(StockDocumentLine $line, string $qty, ?string $rawCode): void
    {
        if ($line->item_instance_id !== null) {
            if ($rawCode === null || trim($rawCode) === '') {
                ApiError::abort(422, 'SERIAL_SCAN_REQUIRED', 'Dòng item serial bắt buộc có raw_code khi scan.');
            }
            if (bccomp($qty, '1', 3) !== 0) {
                ApiError::abort(422, 'SERIAL_SCAN_QUANTITY_INVALID', 'Dòng item serial chỉ được ghi nhận số lượng 1.');
            }

            $resolution = $this->scanResolver->resolveActive($rawCode);
            if (! $resolution['item'] || $resolution['item']->item_id !== $line->item_instance_id) {
                ApiError::abort(422, 'SCAN_ITEM_MISMATCH', 'Mã scan không thuộc item instance của dòng chứng từ.');
            }
            if ($resolution['item']->sku_id !== $line->product_id) {
                ApiError::abort(422, 'SCAN_SKU_MISMATCH', 'Item scan không thuộc SKU của dòng chứng từ.');
            }
            if ($resolution['item']->item_status !== 'NEW') {
                ApiError::abort(409, 'SCAN_ITEM_ALREADY_RECEIVED', 'Item scan đã được ghi nhận hoặc không ở trạng thái NEW.');
            }

            return;
        }

        if ($rawCode === null || trim($rawCode) === '') {
            return;
        }

        $resolution = $this->scanResolver->resolveActive($rawCode);
        if ($resolution['sku_id'] !== null && $resolution['sku_id'] !== $line->product_id) {
            ApiError::abort(422, 'SCAN_SKU_MISMATCH', 'Mã scan không thuộc SKU của dòng chứng từ.');
        }
    }

    private function markInboundItemReceived(StockDocumentLine $line): void
    {
        if ($line->item_instance_id === null) {
            return;
        }

        $item = $line->itemInstance()->lockForUpdate()->first();
        if (! $item || $item->item_status !== 'NEW') {
            WarehouseException::invalidStatus('Item của dòng nhập không còn ở trạng thái NEW.');
        }

        $item->update(['item_status' => 'IN_STOCK', 'version' => $item->version + 1]);
    }

    private function recordInboundScanEvent(StockDocument $doc, StockDocumentLine $line, ?string $rawCode, string $userId): void
    {
        if ($rawCode === null || trim($rawCode) === '') {
            return;
        }

        $resolution = $this->scanResolver->resolveActive($rawCode);
        $batchId = (string) Str::uuid();
        DB::table('scan_batches')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => 'INBOUND',
            'source_id' => $doc->id,
            'warehouse_id' => $doc->dst_warehouse_id,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
        DB::table('scan_record_events')->insert([
            'scan_event_id' => (string) Str::uuid(),
            'scan_batch_id' => $batchId,
            'raw_code' => trim($rawCode),
            'physical_code_id' => $resolution['physical_code']->physical_code_id,
            'resolved_object_type' => $resolution['item'] ? 'ITEM_INSTANCE' : 'CONTAINER',
            'resolved_object_id' => $resolution['item']?->item_id ?? $resolution['container']?->container_id,
            'result_status' => 'ACCEPTED',
            'message' => "Inbound document line {$line->line_no}",
            'created_at' => now(),
        ]);
    }

    private function assertStatus(StockDocument $doc, array $allowed, string $action): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            WarehouseException::invalidStatus("Cannot {$action} when document status is {$doc->status}.");
        }
    }

    private function assertWarehouseActive(?string $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }

        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse || $warehouse->status !== 'ACTIVE') {
            WarehouseException::invalidStatus('Warehouse is not active.');
        }
    }

    private function logStatusHistory(StockDocument $doc, ?string $from, string $to, string $action, string $userId, ?string $note = null): void
    {
        DocumentStatusHistory::create([
            'stock_document_id' => $doc->id,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'changed_by' => $userId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function genCode(string $type): string
    {
        if ($type === StockDocument::TYPE_ISSUE) {
            return app(OutboundDocumentNumber::class)->next();
        }
        $prefix = self::PREFIX[$type] ?? 'PK';
        $prefixWithDate = $prefix.'-'.now()->format('Ymd').'-';
        $count = StockDocument::where('doc_no', 'like', $prefixWithDate.'%')->count();

        return $prefixWithDate.sprintf('%04d', $count + 1);
    }

    private function recordPostingEvent(string $operation, ?string $idempotencyKey, ?string $requestHash, StockDocument $doc, string $userId): void
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return;
        }

        DB::table('posting_event')->updateOrInsert(
            ['operation' => $operation, 'idempotency_key' => $idempotencyKey],
            [
                'posting_event_id' => (string) Str::uuid(),
                'request_hash' => $requestHash ?? sha1($operation.'|'.$idempotencyKey),
                'status' => 'COMMITTED',
                'document_id' => $doc->id,
                'movement_id' => $doc->movements->last()?->id,
                'response_snapshot' => json_encode([
                    'document_id' => $doc->id,
                    'status' => $doc->status,
                    'movement_ids' => $doc->movements->pluck('id')->values()->all(),
                ], JSON_UNESCAPED_UNICODE),
                'created_by' => $userId,
                'created_at' => now(),
            ]
        );
    }
}
