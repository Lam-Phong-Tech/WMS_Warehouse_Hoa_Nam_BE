<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\DocumentStatusHistory;
use Modules\Warehouse\Models\InTransitLedger;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\Warehouse;

/**
 * Điều phối vòng đời chứng từ kho (Nhập/Xuất/Điều chuyển) — port từ StockDocumentService của
 * ERP-Backend M2, giữ nguyên state machine và business rule, chỉ bỏ phần ERP-specific:
 * multi-branch warehouse_type check, EventOutbox, lot_no/expiry_date, WarehouseOutboxLogger.
 *
 * draft → pending → approved → received|issued (receipt/issue)
 *                             → in_transit → completed|completed_with_loss (transfer)
 *                                          → return_in_transit → returned
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

            if ($type === StockDocument::TYPE_TRANSFER && $src && $dst && $src === $dst) {
                WarehouseException::invalidStatus('Kho nguồn và kho đích không được trùng nhau.');
            }

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

            foreach (array_values($data['lines']) as $index => $l) {
                StockDocumentLine::create([
                    'stock_document_id' => $doc->id,
                    'line_no' => $index + 1,
                    'product_id' => $l['product_id'],
                    'qty_planned' => $l['qty_planned'],
                    'qty_actual' => 0,
                    'qty_posted' => 0,
                ]);
            }

            $this->logStatusHistory($doc, null, StockDocument::STATUS_DRAFT, 'create', $userId);
            AuditLogger::record('CREATE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), afterData: ['doc_no' => $doc->doc_no, 'doc_type' => $doc->doc_type]);

            return $doc->load('lines');
        });
    }

    public function submit(StockDocument $doc, string $userId): StockDocument
    {
        if (in_array($doc->doc_type, [StockDocument::TYPE_RECEIPT, StockDocument::TYPE_ISSUE], true)) {
            WarehouseException::invalidStatus('Phiếu nhập/xuất Hoa Nam không có bước gửi duyệt; hãy scan hoặc post trực tiếp.');
        }

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
        if (in_array($doc->doc_type, [StockDocument::TYPE_RECEIPT, StockDocument::TYPE_ISSUE], true)) {
            WarehouseException::invalidStatus('Phiếu nhập/xuất Hoa Nam không có bước duyệt; tồn chỉ thay đổi khi Post.');
        }

        return DB::transaction(function () use ($doc, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_PENDING], 'duyệt');

            if (in_array($doc->doc_type, [StockDocument::TYPE_ISSUE, StockDocument::TYPE_TRANSFER], true)) {
                $this->assertWarehouseActive($doc->src_warehouse_id);
                $this->precheckStock($doc, $doc->src_warehouse_id);
            }
            if ($doc->doc_type === StockDocument::TYPE_RECEIPT) {
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
            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], 'huỷ');

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_CANCELLED, 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_CANCELLED, 'cancel', $userId, $reason);
            AuditLogger::record('CANCEL', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $doc->fresh();
        });
    }

    /** Thực hiện Nhập kho — chứng từ phải ở APPROVED. */
    public function postReceipt(StockDocument $doc, string $userId, array $linesInput = []): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $linesInput) {
            if ($doc->status === StockDocument::STATUS_POSTED) {
                return $doc->fresh(['lines', 'movements']);
            }

            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING, StockDocument::STATUS_PARTIALLY_POSTED], 'post receipt');
            $this->assertWarehouseActive($doc->dst_warehouse_id);

            foreach ($doc->lines as $line) {
                $qtyToPost = $this->qtyToPost($line, $linesInput, $linesInput !== []);
                if (bccomp($qtyToPost, '0', 3) <= 0) {
                    continue;
                }

                $targetPosted = bcadd((string) $line->qty_posted, $qtyToPost, 3);
                $this->ledger->inbound($doc, $line, $userId, $qtyToPost, $targetPosted);
                $line->update(['qty_posted' => $targetPosted, 'qty_actual' => $targetPosted]);
            }

            $from = $doc->status;
            $newStatus = $this->allLinesFullyPosted($doc) ? StockDocument::STATUS_POSTED : StockDocument::STATUS_PARTIALLY_POSTED;
            $doc->update(['status' => $newStatus, 'posted_by' => $userId, 'posted_at' => now()]);
            $this->logStatusHistory($doc, $from, $newStatus, 'post_receipt', $userId);
            AuditLogger::record('POST', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), beforeData: ['status' => $from], afterData: ['status' => $newStatus]);

            return $doc->fresh(['lines', 'movements']);
        });
    }

    /** Thực hiện Xuất kho — chứng từ phải ở APPROVED. */
    public function postIssue(StockDocument $doc, string $userId, array $linesInput = []): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId, $linesInput) {
            if ($doc->status === StockDocument::STATUS_POSTED) {
                return $doc->fresh(['lines', 'movements']);
            }

            $this->assertStatus($doc, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING, StockDocument::STATUS_PARTIALLY_POSTED], 'post issue');
            $this->assertWarehouseActive($doc->src_warehouse_id);

            foreach ($doc->lines as $line) {
                $qtyToPost = $this->qtyToPost($line, $linesInput, $linesInput !== []);
                if (bccomp($qtyToPost, '0', 3) <= 0) {
                    continue;
                }

                $targetPosted = bcadd((string) $line->qty_posted, $qtyToPost, 3);
                $this->ledger->outbound($doc, $line, $userId, $qtyToPost, $targetPosted);
                $line->update(['qty_posted' => $targetPosted, 'qty_actual' => $targetPosted]);
            }

            $from = $doc->status;
            $newStatus = $this->allLinesFullyPosted($doc) ? StockDocument::STATUS_POSTED : StockDocument::STATUS_PARTIALLY_POSTED;
            $doc->update(['status' => $newStatus, 'posted_by' => $userId, 'posted_at' => now()]);
            $this->logStatusHistory($doc, $from, $newStatus, 'post_issue', $userId);
            AuditLogger::record('POST', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), beforeData: ['status' => $from], afterData: ['status' => $newStatus]);

            return $doc->fresh(['lines', 'movements']);
        });
    }

    private function qtyToPost(StockDocumentLine $line, array $linesInput, bool $partialMode): string
    {
        $remaining = bcsub((string) $line->qty_planned, (string) $line->qty_posted, 3);
        if ($partialMode && ! array_key_exists($line->id, $linesInput)) {
            return '0';
        }

        $requested = array_key_exists($line->id, $linesInput) ? (string) $linesInput[$line->id] : $remaining;

        if (bccomp($requested, '0', 3) < 0) {
            WarehouseException::invalidStatus("Sá»‘ lÆ°á»£ng post khÃ´ng Ä‘Æ°á»£c Ã¢m (dÃ²ng {$line->line_no}).");
        }

        if (bccomp($requested, $remaining, 3) > 0) {
            WarehouseException::invalidStatus("Sá»‘ lÆ°á»£ng post khÃ´ng Ä‘Æ°á»£c vÆ°á»£t quÃ¡ sá»‘ cÃ²n láº¡i (dÃ²ng {$line->line_no}).");
        }

        return $requested;
    }

    private function allLinesFullyPosted(StockDocument $doc): bool
    {
        foreach ($doc->lines()->get() as $line) {
            if (bccomp((string) $line->qty_posted, (string) $line->qty_planned, 3) < 0) {
                return false;
            }
        }

        return true;
    }

    /** Điều chuyển — Xuất tại kho nguồn + tạo dòng InTransitLedger. */
    public function dispatch(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertTransfer($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED], 'xuất điều chuyển');
            $this->assertWarehouseActive($doc->src_warehouse_id);

            foreach ($doc->lines as $line) {
                $this->ledger->transferOut($doc, $line, $userId);

                InTransitLedger::create([
                    'stock_document_id' => $doc->id,
                    'stock_document_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'src_warehouse_id' => $doc->src_warehouse_id,
                    'dst_warehouse_id' => $doc->dst_warehouse_id,
                    'qty_dispatched' => $line->qty_planned,
                    'status' => InTransitLedger::STATUS_IN_TRANSIT,
                    'dispatched_at' => now(),
                ]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_IN_TRANSIT, 'dispatched_by' => $userId, 'dispatched_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_IN_TRANSIT, 'dispatch', $userId);

            return $doc->fresh('lines');
        });
    }

    /**
     * Điều chuyển — Nhận hàng tại kho đích. Mỗi dòng nhận đúng 1 lần (không hỗ trợ nhận nhiều
     * đợt như bản ERP gốc — đơn giản hoá cho base). `linesInput`: [line_id => qty_actual].
     */
    public function receive(StockDocument $doc, array $linesInput, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $linesInput, $userId) {
            $this->assertTransfer($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT], 'nhận hàng điều chuyển');
            $this->assertWarehouseActive($doc->dst_warehouse_id);

            $allCompleted = true;
            foreach ($doc->lines as $line) {
                $itl = InTransitLedger::where('stock_document_line_id', $line->id)->lockForUpdate()->firstOrFail();
                $qtyReceived = (string) ($linesInput[$line->id] ?? $itl->qty_dispatched);
                $remaining = bcsub((string) $itl->qty_dispatched, (string) $itl->qty_received, 3);

                if (bccomp($qtyReceived, $remaining, 3) > 0) {
                    WarehouseException::invalidStatus("Số thực nhận không được vượt quá số lượng còn lại (dòng {$line->line_no}).");
                }

                $newReceived = bcadd((string) $itl->qty_received, $qtyReceived, 3);
                if (bccomp($qtyReceived, '0', 3) > 0) {
                    $this->ledger->transferIn($doc, $line, $qtyReceived, $userId, $newReceived);
                }

                $stillRemaining = bcsub((string) $itl->qty_dispatched, $newReceived, 3);
                $itlStatus = bccomp($stillRemaining, '0', 3) <= 0 ? InTransitLedger::STATUS_COMPLETED : InTransitLedger::STATUS_PARTIALLY_RECEIVED;

                $itl->update(['qty_received' => $newReceived, 'status' => $itlStatus, 'received_at' => now()]);

                if ($itlStatus !== InTransitLedger::STATUS_COMPLETED) {
                    $allCompleted = false;
                }
            }

            $from = $doc->status;
            $newStatus = $allCompleted ? StockDocument::STATUS_COMPLETED : StockDocument::STATUS_IN_TRANSIT;
            $doc->update(['status' => $newStatus, 'received_by' => $userId, 'received_at' => now()]);
            $this->logStatusHistory($doc, $from, $newStatus, 'receive', $userId);

            return $doc->fresh('lines');
        });
    }

    /**
     * Điều chuyển — Ghi nhận mất mát trong quá trình vận chuyển. Không sinh stock_movement
     * (không có kho nào bị trừ thật — hàng đã xuất khỏi kho nguồn từ bước dispatch), chỉ cập
     * nhật InTransitLedger.qty_lost. `linesInput`: [line_id => qty_lost].
     */
    public function recordTransferLoss(StockDocument $doc, array $linesInput, string $userId, ?string $reason): StockDocument
    {
        return DB::transaction(function () use ($doc, $linesInput, $userId, $reason) {
            $this->assertTransfer($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT], 'ghi nhận mất mát điều chuyển');

            $allCompleted = true;
            foreach ($doc->lines as $line) {
                $itl = InTransitLedger::where('stock_document_line_id', $line->id)->lockForUpdate()->firstOrFail();
                $qtyLost = (string) ($linesInput[$line->id] ?? 0);
                $remaining = bcsub(bcsub((string) $itl->qty_dispatched, (string) $itl->qty_received, 3), (string) $itl->qty_lost, 3);

                if (bccomp($qtyLost, $remaining, 3) > 0) {
                    WarehouseException::invalidStatus("Số lượng mất mát không được vượt quá số lượng còn lại (dòng {$line->line_no}).");
                }

                $newLost = bcadd((string) $itl->qty_lost, $qtyLost, 3);
                $stillRemaining = bcsub($remaining, $qtyLost, 3);
                $itlStatus = bccomp($stillRemaining, '0', 3) <= 0 ? InTransitLedger::STATUS_COMPLETED_WITH_LOSS : InTransitLedger::STATUS_PARTIALLY_RECEIVED;

                $itl->update(['qty_lost' => $newLost, 'status' => $itlStatus]);
                if (bccomp($qtyLost, '0', 3) > 0) {
                    $line->update(['shortage_reason' => $reason]);
                }

                if ($itlStatus !== InTransitLedger::STATUS_COMPLETED_WITH_LOSS) {
                    $allCompleted = false;
                }
            }

            if ($allCompleted) {
                $from = $doc->status;
                $doc->update(['status' => StockDocument::STATUS_COMPLETED_WITH_LOSS]);
                $this->logStatusHistory($doc, $from, StockDocument::STATUS_COMPLETED_WITH_LOSS, 'record_loss', $userId, $reason);
            }

            return $doc->fresh('lines');
        });
    }

    /** Điều chuyển — Bước 1: yêu cầu trả lại hàng (chưa đổi tồn thật, chỉ đánh dấu). */
    public function returnTransfer(StockDocument $doc, ?string $reason, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $reason, $userId) {
            $this->assertTransfer($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT], 'trả lại hàng điều chuyển');

            foreach ($doc->lines as $line) {
                $itl = InTransitLedger::where('stock_document_line_id', $line->id)->lockForUpdate()->firstOrFail();
                $remaining = bcsub(bcsub((string) $itl->qty_dispatched, (string) $itl->qty_received, 3), (string) $itl->qty_lost, 3);
                if (bccomp($remaining, '0', 3) <= 0) {
                    continue;
                }

                $itl->update([
                    'qty_return_in_transit' => bcadd((string) $itl->qty_return_in_transit, $remaining, 3),
                    'status' => InTransitLedger::STATUS_RETURN_IN_TRANSIT,
                ]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_RETURN_IN_TRANSIT, 'return_reason' => $reason]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_RETURN_IN_TRANSIT, 'return', $userId, $reason);

            return $doc->fresh('lines');
        });
    }

    /** Điều chuyển — Bước 2: xác nhận đã nhận lại hàng trả về tại kho nguồn (đổi tồn thật). */
    public function confirmReturn(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertTransfer($doc);
            $this->assertStatus($doc, [StockDocument::STATUS_RETURN_IN_TRANSIT], 'xác nhận nhận lại hàng trả về');
            $this->assertWarehouseActive($doc->src_warehouse_id);

            foreach ($doc->lines as $line) {
                $itl = InTransitLedger::where('stock_document_line_id', $line->id)->lockForUpdate()->firstOrFail();
                $pending = bcsub((string) $itl->qty_return_in_transit, (string) $itl->qty_returned, 3);
                if (bccomp($pending, '0', 3) <= 0) {
                    continue;
                }

                $this->ledger->transferReturn($doc, $line, $pending, $userId);
                $itl->update(['qty_returned' => bcadd((string) $itl->qty_returned, $pending, 3), 'status' => InTransitLedger::STATUS_RETURNED, 'returned_at' => now()]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_RETURNED, 'returned_by' => $userId, 'returned_at' => now()]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_RETURNED, 'confirm_return', $userId);

            return $doc->fresh('lines');
        });
    }

    private function assertStatus(StockDocument $doc, array $allowed, string $action): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            WarehouseException::invalidStatus("Không thể {$action} khi phiếu đang ở trạng thái \"{$doc->status}\".");
        }
    }

    private function assertTransfer(StockDocument $doc): void
    {
        if ($doc->doc_type !== StockDocument::TYPE_TRANSFER) {
            WarehouseException::invalidStatus('Thao tác chỉ áp dụng cho phiếu điều chuyển.');
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
}
