<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\DocumentStatusHistory;
use Modules\Warehouse\Models\InTransitLedger;
use Modules\Warehouse\Models\StockDocument;

/**
 * Điều phối vòng đời riêng của phiếu điều chuyển kho, tiếp nối sau approve() của
 * StockDocumentService: approved → dispatch (in_transit) → receive (completed|completed_with_loss)
 *                                                         → return (return_in_transit) → confirm-return (returned)
 * Base này không có bucket tồn "in_transit" trên inventory_balances — qty trừ khỏi kho nguồn
 * ngay lúc dispatch, chỉ cộng vào kho đích khi receive thật sự xảy ra; record-loss/return chỉ
 * ghi nhận trạng thái/số lượng trên in_transit_ledger, không tạo thêm biến động tồn cho tới khi
 * confirm-return (lúc đó mới thật sự cộng lại tồn kho nguồn).
 */
class TransferService
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function dispatch(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_APPROVED], 'xuất điều chuyển');

            foreach ($doc->lines as $line) {
                $this->ledger->transferOut($doc, $line, $userId);

                InTransitLedger::create([
                    'document_id' => $doc->id,
                    'document_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'src_warehouse_id' => $doc->src_warehouse_id,
                    'dst_warehouse_id' => $doc->dst_warehouse_id,
                    'qty_dispatched' => $line->qty_planned,
                    'status' => InTransitLedger::STATUS_IN_TRANSIT,
                    'dispatched_at' => now(),
                ]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_IN_TRANSIT]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_IN_TRANSIT, 'dispatch', $userId);
            AuditLogger::record('DISPATCH', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'));

            return $doc->fresh(['lines', 'movements', 'inTransitLedgers']);
        });
    }

    /** @param array<int, array{line_id: string, qty_actual: string|float}> $lines */
    public function receive(StockDocument $doc, array $lines, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $lines, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT], 'nhận hàng điều chuyển');

            $hasShortage = false;
            foreach ($lines as $l) {
                $itl = InTransitLedger::where('document_line_id', $l['line_id'])->where('document_id', $doc->id)->lockForUpdate()->first();
                if (! $itl) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy dòng điều chuyển.');
                }

                $qtyActual = (string) $l['qty_actual'];
                if (bccomp($qtyActual, (string) $itl->qty_dispatched, 3) > 0) {
                    ApiError::abort(422, 'VALIDATION_FAILED', 'Số lượng nhận không được vượt quá số lượng đã xuất.');
                }
                if (bccomp($qtyActual, (string) $itl->qty_dispatched, 3) < 0) {
                    $hasShortage = true;
                }

                $line = $doc->lines()->where('id', $l['line_id'])->firstOrFail();
                if (bccomp($qtyActual, '0', 3) > 0) {
                    $this->ledger->transferIn($doc, $line, $qtyActual, $userId);
                }
                $line->update(['qty_actual' => $qtyActual]);

                $itl->update([
                    'qty_received' => $qtyActual,
                    'status' => bccomp($qtyActual, (string) $itl->qty_dispatched, 3) >= 0 ? InTransitLedger::STATUS_COMPLETED : InTransitLedger::STATUS_COMPLETED_WITH_LOSS,
                    'received_at' => now(),
                ]);
            }

            $from = $doc->status;
            $finalStatus = $hasShortage ? StockDocument::STATUS_COMPLETED_WITH_LOSS : StockDocument::STATUS_COMPLETED;
            $doc->update(['status' => $finalStatus]);
            $this->logStatusHistory($doc, $from, $finalStatus, 'receive', $userId);
            AuditLogger::record('RECEIVE', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'));

            return $doc->fresh(['lines', 'movements', 'inTransitLedgers']);
        });
    }

    /** @param array<int, array{line_id: string, qty_lost: string|float, reason: string}> $lines */
    public function recordLoss(StockDocument $doc, array $lines, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $lines, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT, StockDocument::STATUS_COMPLETED_WITH_LOSS], 'ghi nhận mất mát điều chuyển');

            foreach ($lines as $l) {
                $itl = InTransitLedger::where('document_line_id', $l['line_id'])->where('document_id', $doc->id)->lockForUpdate()->first();
                if (! $itl) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy dòng điều chuyển.');
                }

                $qtyLost = (string) $l['qty_lost'];
                $accountedFor = bcadd((string) $itl->qty_received, bcadd((string) $itl->qty_returned, (string) $itl->qty_lost, 3), 3);
                $remaining = bcsub((string) $itl->qty_dispatched, $accountedFor, 3);
                if (bccomp($qtyLost, $remaining, 3) > 0) {
                    ApiError::abort(422, 'VALIDATION_FAILED', 'Số lượng mất mát vượt quá số lượng còn lại chưa xử lý.');
                }

                $itl->update([
                    'qty_lost' => bcadd((string) $itl->qty_lost, $qtyLost, 3),
                    'status' => InTransitLedger::STATUS_COMPLETED_WITH_LOSS,
                ]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_COMPLETED_WITH_LOSS]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_COMPLETED_WITH_LOSS, 'record_loss', $userId, $lines[0]['reason'] ?? null);
            AuditLogger::record('RECORD_LOSS', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'));

            return $doc->fresh(['lines', 'movements', 'inTransitLedgers']);
        });
    }

    /** Bước 1: đánh dấu đang trả lại — KHÔNG đổi tồn kho thật (chỉ khi confirm-return mới đổi). */
    public function returnTransfer(StockDocument $doc, ?string $reason, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $reason, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_IN_TRANSIT], 'trả lại hàng điều chuyển');

            foreach ($doc->inTransitLedgers as $itl) {
                $accountedFor = bcadd((string) $itl->qty_received, (string) $itl->qty_lost, 3);
                $remaining = bcsub((string) $itl->qty_dispatched, $accountedFor, 3);
                if (bccomp($remaining, '0', 3) > 0) {
                    $itl->update(['qty_return_in_transit' => $remaining, 'status' => InTransitLedger::STATUS_RETURN_IN_TRANSIT]);
                }
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_RETURN_IN_TRANSIT]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_RETURN_IN_TRANSIT, 'return', $userId, $reason);
            AuditLogger::record('RETURN', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $doc->fresh(['lines', 'movements', 'inTransitLedgers']);
        });
    }

    /** Bước 2: kho nguồn xác nhận đã nhận lại hàng trả về — cộng lại tồn khả dụng kho nguồn thật sự ở đây. */
    public function confirmReturn(StockDocument $doc, string $userId): StockDocument
    {
        return DB::transaction(function () use ($doc, $userId) {
            $this->assertStatus($doc, [StockDocument::STATUS_RETURN_IN_TRANSIT], 'xác nhận nhận lại hàng trả về');

            foreach ($doc->inTransitLedgers as $itl) {
                $qtyReturn = (string) $itl->qty_return_in_transit;
                if (bccomp($qtyReturn, '0', 3) <= 0) {
                    continue;
                }
                $line = $doc->lines()->where('id', $itl->document_line_id)->firstOrFail();
                $this->ledger->transferReturn($doc, $line, $qtyReturn, $userId);

                $itl->update([
                    'qty_returned' => bcadd((string) $itl->qty_returned, $qtyReturn, 3),
                    'qty_return_in_transit' => '0',
                    'status' => InTransitLedger::STATUS_RETURNED,
                    'returned_at' => now(),
                ]);
            }

            $from = $doc->status;
            $doc->update(['status' => StockDocument::STATUS_RETURNED]);
            $this->logStatusHistory($doc, $from, StockDocument::STATUS_RETURNED, 'confirm_return', $userId);
            AuditLogger::record('CONFIRM_RETURN', 'stock_document', $doc->id, $userId, request()?->attributes->get('request_id'));

            return $doc->fresh(['lines', 'movements', 'inTransitLedgers']);
        });
    }

    private function assertStatus(StockDocument $doc, array $allowed, string $action): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            ApiError::abort(409, 'STATE_CONFLICT', "Không thể {$action} khi phiếu đang ở trạng thái \"{$doc->status}\".");
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
}
