<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockRequest;
use Modules\Warehouse\Models\StockRequestLine;
use Modules\Warehouse\Models\Warehouse;

/**
 * Port từ StockRequestService của ERP-Backend M2 — luồng "xin duyệt trước" độc lập với
 * StockDocument, chuyển đổi (convert) thành StockDocument thật (ở trạng thái DRAFT) sau khi
 * được duyệt.
 */
class StockRequestService
{
    private const PREFIX = [
        StockRequest::TYPE_RECEIPT => 'YCNK',
        StockRequest::TYPE_ISSUE => 'YCXK',
        StockRequest::TYPE_TRANSFER => 'YCDC',
    ];

    public function __construct(private readonly StockDocumentService $documentService) {}

    public function create(string $type, array $data, string $userId): StockRequest
    {
        return DB::transaction(function () use ($type, $data, $userId) {
            $src = $data['src_warehouse_id'] ?? null;
            $dst = $data['dst_warehouse_id'] ?? null;

            if ($type === StockRequest::TYPE_TRANSFER && $src && $dst && $src === $dst) {
                WarehouseException::invalidStatus('Kho nguồn và kho đích không được trùng nhau.');
            }
            $this->assertWarehouseActive($src);
            $this->assertWarehouseActive($dst);

            $request = StockRequest::create([
                'request_type' => $type,
                'request_no' => $data['request_no'] ?? $this->genCode($type),
                'request_date' => $data['request_date'] ?? now()->format('Y-m-d'),
                'status' => StockRequest::STATUS_DRAFT,
                'src_warehouse_id' => $src,
                'dst_warehouse_id' => $dst,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
            ]);

            foreach (array_values($data['lines']) as $index => $l) {
                StockRequestLine::create([
                    'stock_request_id' => $request->id,
                    'line_no' => $index + 1,
                    'product_id' => $l['product_id'],
                    'qty_planned' => $l['qty_planned'],
                    'note' => $l['note'] ?? null,
                ]);
            }

            return $request->load('lines');
        });
    }

    public function submit(StockRequest $request, string $userId): StockRequest
    {
        return DB::transaction(function () use ($request, $userId) {
            $this->assertStatus($request, [StockRequest::STATUS_DRAFT], 'gửi duyệt');
            if ($request->lines()->count() < 1) {
                WarehouseException::invalidStatus('Yêu cầu phải có ít nhất 1 dòng sản phẩm.');
            }

            $request->update(['status' => StockRequest::STATUS_PENDING, 'submitted_by' => $userId, 'submitted_at' => now()]);

            return $request->fresh('lines');
        });
    }

    public function approve(StockRequest $request, string $userId): StockRequest
    {
        return DB::transaction(function () use ($request, $userId) {
            $this->assertStatus($request, [StockRequest::STATUS_PENDING], 'duyệt');

            $request->update(['status' => StockRequest::STATUS_APPROVED, 'approved_by' => $userId, 'approved_at' => now()]);

            return $request->fresh('lines');
        });
    }

    public function reject(StockRequest $request, ?string $reason, string $userId): StockRequest
    {
        return DB::transaction(function () use ($request, $reason, $userId) {
            $this->assertStatus($request, [StockRequest::STATUS_PENDING], 'từ chối duyệt');

            $request->update(['status' => StockRequest::STATUS_REJECTED, 'reject_reason' => $reason, 'rejected_by' => $userId, 'rejected_at' => now()]);

            return $request->fresh('lines');
        });
    }

    public function cancel(StockRequest $request, ?string $reason, string $userId): StockRequest
    {
        return DB::transaction(function () use ($request, $reason, $userId) {
            $this->assertStatus($request, [StockRequest::STATUS_DRAFT, StockRequest::STATUS_PENDING, StockRequest::STATUS_APPROVED], 'huỷ');

            $request->update(['status' => StockRequest::STATUS_CANCELLED, 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);

            return $request->fresh('lines');
        });
    }

    /** Chuyển đổi yêu cầu đã duyệt thành StockDocument thật (trạng thái DRAFT). */
    public function convert(StockRequest $request, string $userId): StockDocument
    {
        return DB::transaction(function () use ($request, $userId) {
            $this->assertStatus($request, [StockRequest::STATUS_APPROVED], 'chuyển đổi thành phiếu');

            if ($request->converted_doc_id !== null) {
                WarehouseException::invalidStatus('Yêu cầu này đã được chuyển thành phiếu trước đó.');
            }

            $docData = [
                'src_warehouse_id' => $request->src_warehouse_id,
                'dst_warehouse_id' => $request->dst_warehouse_id,
                'doc_date' => now()->format('Y-m-d'),
                'note' => $request->note ? "[Tạo từ yêu cầu {$request->request_no}] {$request->note}" : "[Tạo từ yêu cầu {$request->request_no}]",
                'lines' => $request->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'qty_planned' => (float) $line->qty_planned,
                ])->toArray(),
            ];

            $doc = $this->documentService->create($request->request_type, $docData, $userId);
            $doc->update(['ref_doc_type' => 'stock_request', 'ref_doc_id' => $request->id]);

            $request->update(['status' => StockRequest::STATUS_CONVERTED, 'converted_doc_id' => $doc->id]);

            return $doc->load(['lines', 'srcWarehouse', 'dstWarehouse']);
        });
    }

    private function assertStatus(StockRequest $request, array $allowed, string $action): void
    {
        if (! in_array($request->status, $allowed, true)) {
            WarehouseException::invalidStatus("Không thể {$action} khi yêu cầu đang ở trạng thái \"{$request->status}\".");
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

    private function genCode(string $type): string
    {
        $prefix = self::PREFIX[$type] ?? 'YC';
        $prefixWithDate = $prefix.'-'.now()->format('Ymd').'-';
        $count = StockRequest::where('request_no', 'like', $prefixWithDate.'%')->count();

        return $prefixWithDate.sprintf('%04d', $count + 1);
    }
}
