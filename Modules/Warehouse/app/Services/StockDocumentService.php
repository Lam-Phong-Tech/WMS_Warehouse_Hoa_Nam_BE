<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\DocumentStatusHistory;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;

/**
 * Điều phối vòng đời chứng từ Nhập/Xuất kho — port từ StockDocumentService của ERP-Backend M2,
 * giữ nguyên state machine và business rule, chỉ bỏ phần ERP-specific: multi-branch warehouse_type
 * check, EventOutbox, lot_no/expiry_date, WarehouseOutboxLogger, và toàn bộ doc_type điều chuyển
 * (Hoà Nam chỉ vận hành 1 kho tổng nên không cần transfer).
 *
 * draft → pending → approved → post-receipt|post-issue (received|issued, đổi tồn 1 lần cho toàn bộ)
 *                             → scan (validate/classify only, stock_effect=NONE, không ghi movement)
 *                → reverse (từ received|issued|closed → reversed, đảo ngược toàn bộ movement đã ghi)
 * cancelled: chỉ từ draft/pending/approved. rejected: chỉ từ pending.
 */
class StockDocumentService
{
    private const PREFIX = [
        StockDocument::TYPE_RECEIPT => 'PN',
        StockDocument::TYPE_ISSUE => 'PX',
        StockDocument::TYPE_TRANSFER => 'DC',
    ];

    public function __construct(private readonly StockLedger $ledger) {}

    public function create(string $type, array $data, string $userId): StockDocument
    {
        return DB::transaction(function () use ($type, $data, $userId) {
            $src = $data['src_warehouse_id'] ?? null;
            $dst = $data['dst_warehouse_id'] ?? null;

            $this->assertWarehouseActive($src);
            $this->assertWarehouseActive($dst);

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

    /** Chỉ cho phép sửa khi phiếu còn ở draft (chưa gửi duyệt). */
    public function update(StockDocument $doc, array $data, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $data, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT], 'sửa');

            $doc->update(array_filter([
                'doc_no' => $data['doc_no'] ?? null,
                'doc_date' => $data['doc_date'] ?? null,
                'note' => $data['note'] ?? $doc->note,
                'updated_by' => $userId,
            ], fn ($v) => $v !== null));

            if (array_key_exists('lines', $data)) {
                $doc->lines()->delete();
                $this->syncLines($doc, $data['lines']);
            }

            return $doc->fresh('lines');
        });
    }

    public function submit(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT], 'gửi duyệt');
            if ($doc->lines()->count() < 1) {
                WarehouseException::invalidStatus('Phiếu phải có ít nhất 1 dòng sản phẩm.');
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_PENDING, 'submitted_by' => $userId, 'submitted_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_PENDING, 'submit', $userId);

            return $doc->fresh('lines');
        });
    }

    public function approve(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_PENDING], 'duyệt');

            if (in_array($doc->doc_type, [StockDocument::TYPE_ISSUE, StockDocument::TYPE_TRANSFER], true)) {
                $this->assertWarehouseActive($doc->src_warehouse_id);
                $this->precheckStock($doc, $doc->src_warehouse_id);
            }
            if (in_array($doc->doc_type, [StockDocument::TYPE_RECEIPT, StockDocument::TYPE_TRANSFER], true)) {
                $this->assertWarehouseActive($doc->dst_warehouse_id);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_APPROVED, 'approved_by' => $userId, 'approved_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_APPROVED, 'approve', $userId);
            AuditLogger::record('APPROVE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'));

            return $doc->fresh(['lines', 'srcWarehouse', 'dstWarehouse']);
        });
    }

    public function reject(StockDocument $doc, ?string $reason, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $reason, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_PENDING], 'từ chối duyệt');

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_REJECTED, 'reject_reason' => $reason, 'rejected_by' => $userId, 'rejected_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_REJECTED, 'reject', $userId, $reason);

            return $doc->fresh('lines');
        });
    }

    public function cancel(StockDocument $doc, string $userId, ?string $reason): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $reason) {
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_PENDING, StockDocument::STATUS_APPROVED], 'huỷ');

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_CANCELLED, 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_CANCELLED, 'cancel', $userId, $reason);
            AuditLogger::record('CANCEL', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $doc->fresh();
        });
    }

    /** Thực hiện Nhập kho toàn bộ trong 1 lần — chứng từ phải ở APPROVED. */
    public function postReceipt(StockDocument $doc, string $userId, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $idempotencyKey, $requestHash, $operation) {
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED], 'thực hiện nhập kho');
            $this->assertWarehouseActive($doc->dst_warehouse_id);

            foreach ($doc->lines as $line) {
                $this->ledger->inbound($doc, $line, $userId);
                $line->update(['scanned_qty' => $line->qty_planned]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_RECEIVED, 'posted_by' => $userId, 'posted_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_RECEIVED, 'post_receipt', $userId);
            AuditLogger::record('POST', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), beforeData: ['status' => $from], afterData: ['status' => StockDocument::STATUS_RECEIVED]);

            $fresh = $doc->fresh(['lines', 'movements']);
            $this->recordPostingEvent($operation ?? "post_receipt_{$doc->id}", $idempotencyKey, $requestHash, $fresh, $userId);

            return $fresh;
        });
    }

    /** Thực hiện Xuất kho toàn bộ trong 1 lần — chứng từ phải ở APPROVED. */
    public function postIssue(StockDocument $doc, string $userId, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $idempotencyKey, $requestHash, $operation) {
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED], 'thực hiện xuất kho');
            $this->assertWarehouseActive($doc->src_warehouse_id);

            foreach ($doc->lines as $line) {
                $this->ledger->outbound($doc, $line, $userId);
                $line->update(['scanned_qty' => $line->qty_planned]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_ISSUED, 'posted_by' => $userId, 'posted_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_ISSUED, 'post_issue', $userId);
            AuditLogger::record('POST', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), beforeData: ['status' => $from], afterData: ['status' => StockDocument::STATUS_ISSUED]);

            $fresh = $doc->fresh(['lines', 'movements']);
            $this->recordPostingEvent($operation ?? "post_issue_{$doc->id}", $idempotencyKey, $requestHash, $fresh, $userId);

            return $fresh;
        });
    }

    /**
     * Validate scan theo dòng và ghi nhận scanned state trên line. Contract G1 khóa scan là classify/validate only:
     * stock_effect=NONE, không ghi stock_movements, không đổi inventory_balances, không đổi trạng thái chứng từ.
     */
    public function validateScanLine(StockDocument $doc, string $lineId, string $qty): array
    {
        return DB::transaction(function () use ($doc, $lineId, $qty) {
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED], 'scan');

            $line = $doc->lines()->where('id', $lineId)->lockForUpdate()->firstOrFail();

            if (bccomp($qty, '0', 3) <= 0) {
                WarehouseException::invalidStatus('Số lượng scan phải lớn hơn 0.');
            }
            $scannedBefore = (string) $line->scanned_qty;
            $scannedAfter = bcadd($scannedBefore, $qty, 3);
            $remainingBefore = bcsub((string) $line->qty_planned, $scannedBefore, 3);

            if (bccomp($qty, $remainingBefore, 3) > 0) {
                WarehouseException::invalidStatus("Số lượng scan không được vượt quá phần còn lại của dòng {$line->line_no}.");
            }

            if ($doc->doc_type === StockDocument::TYPE_RECEIPT) {
                $this->assertWarehouseActive($doc->dst_warehouse_id);
            } else {
                $this->assertWarehouseActive($doc->src_warehouse_id);
                $available = (string) InventoryBalance::where('warehouse_id', $doc->src_warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->value('available_qty') ?? '0';

                if (bccomp($available, $qty, 3) < 0) {
                    WarehouseException::insufficientStock($line->product->code ?? (string) $line->product_id);
                }
            }

            $line->update(['scanned_qty' => $scannedAfter]);

            return [
                'document_id' => $doc->id,
                'line_id' => $line->id,
                'doc_type' => $doc->doc_type,
                'status' => $doc->status,
                'product_id' => $line->product_id,
                'qty' => (float) $qty,
                'qty_planned' => (float) $line->qty_planned,
                'scanned_qty_before' => (float) $scannedBefore,
                'scanned_qty_after' => (float) $scannedAfter,
                'remaining_qty' => (float) bcsub((string) $line->qty_planned, $scannedAfter, 3),
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'next_stock_action' => $doc->doc_type === StockDocument::TYPE_RECEIPT ? 'post-receipt' : 'post-issue',
            ];
        });
    }

    /**
     * Đóng phần còn lại chưa scan đủ — đánh dấu shortage trên từng dòng còn thiếu, chuyển
     * phiếu sang closed. Chỉ áp dụng khi phiếu đang approved (chưa scan gì) hoặc partially_posted.
     */
    public function closeRemainder(StockDocument $doc, string $userId, ?string $reason): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $reason) {
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED, StockDocument::STATUS_PARTIALLY_POSTED], 'đóng phần còn thiếu');

            foreach ($doc->lines as $line) {
                $remaining = bcsub((string) $line->qty_planned, (string) $line->scanned_qty, 3);
                if (bccomp($remaining, '0', 3) > 0) {
                    $line->update([
                        'qty_actual' => $line->scanned_qty,
                        'completion_type' => 'shortage',
                        'shortage_reason' => $reason,
                    ]);
                }
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_CLOSED, 'closed_by' => $userId, 'closed_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_CLOSED, 'close_remainder', $userId, $reason);
            AuditLogger::record('CLOSE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $doc->fresh('lines');
        });
    }

    /** Đảo ngược toàn bộ movement đã ghi cho phiếu (reverse/correction). */
    public function reverseDocument(StockDocument $doc, string $userId, ?string $reason, ?string $idempotencyKey = null, ?string $requestHash = null, ?string $operation = null): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $reason, $idempotencyKey, $requestHash, $operation) {
            $this->assertStatus($doc, [StockDocument::STATUS_RECEIVED, StockDocument::STATUS_ISSUED, StockDocument::STATUS_CLOSED], 'đảo ngược');

            $movements = StockMovement::where('stock_document_id', $doc->id)
                ->whereNull('original_movement_id')
                ->get();

            foreach ($movements as $movement) {
                $alreadyReversed = StockMovement::where('original_movement_id', $movement->id)->exists();
                if ($alreadyReversed) {
                    continue;
                }
                $this->ledger->reverse($movement, $userId);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_REVERSED, 'reversed_by' => $userId, 'reversed_at' => now(), 'reverse_reason' => $reason]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_REVERSED, 'reverse', $userId, $reason);
            AuditLogger::record('REVERSE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            $fresh = $doc->fresh(['lines', 'movements']);
            $this->recordPostingEvent($operation ?? "reverse_stock_document_{$doc->id}", $idempotencyKey, $requestHash, $fresh, $userId);

            return $fresh;
        });
    }

    private function syncLines(StockDocument $doc, array $lines): void
    {
        foreach (array_values($lines) as $index => $l) {
            StockDocumentLine::create([
                'stock_document_id' => $doc->id,
                'line_no' => $index + 1,
                'product_id' => $l['product_id'],
                'qty_planned' => $l['qty_planned'],
                'qty_actual' => $l['qty_planned'],
            ]);
        }
    }

    private function assertStatus(StockDocument $doc, array $allowed, string $action): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            WarehouseException::invalidStatus("Không thể {$action} khi phiếu đang ở trạng thái \"{$doc->status}\".");
        }
    }

    private function assertWarehouseActive(?string $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            WarehouseException::invalidStatus('Kho không tồn tại.');
        }
        if ($warehouse->status !== 'ACTIVE') {
            WarehouseException::invalidStatus("Kho \"{$warehouse->name}\" đang ngừng hoạt động, không thể thực hiện nghiệp vụ.");
        }
    }

    private function precheckStock(StockDocument $doc, string $warehouseId): void
    {
        foreach ($doc->lines as $line) {
            $available = (string) InventoryBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $line->product_id)
                ->value('available_qty') ?? '0';

            if (bccomp($available, (string) $line->qty_planned, 3) < 0) {
                WarehouseException::insufficientStock($line->product->code ?? (string) $line->product_id);
            }
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
