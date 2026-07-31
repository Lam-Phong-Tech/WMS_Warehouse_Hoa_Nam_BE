<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockRequest;
use Modules\Warehouse\Models\Warehouse;

/**
 * Bước tiền duyệt (draft → pending → approved) trước khi chuyển đổi thành stock_documents thật.
 * Cho phép request_type=transfer (src ≠ dst) nhưng base hiện chỉ có 1 kho tổng nên nhánh này
 * chưa dùng được thực tế — xem ghi chú trong migration + BRD_ERD_Upgrade_Guide.md.
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

            $this->assertWarehousePair($type, $src, $dst);

            $request = StockRequest::create([
                'request_no' => $data['request_no'] ?? $this->genCode($type),
                'request_type' => $type,
                'request_date' => $data['request_date'] ?? now()->format('Y-m-d'),
                'status' => StockRequest::STATUS_DRAFT,
                'src_warehouse_id' => $src,
                'dst_warehouse_id' => $dst,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->syncLines($request, $data['lines']);

            AuditLogger::record('CREATE', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'), afterData: ['request_no' => $request->request_no]);

            return $request->load(['lines.product', 'srcWarehouse', 'dstWarehouse']);
        });
    }

    public function update(string $id, array $data, string $userId): StockRequest
    {
        return DB::transaction(function () use ($id, $data, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_DRAFT], 'cập nhật');

            $src = $data['src_warehouse_id'] ?? null;
            $dst = $data['dst_warehouse_id'] ?? null;
            $this->assertWarehousePair($request->request_type, $src, $dst);

            $request->update([
                'request_date' => $data['request_date'] ?? $request->request_date->format('Y-m-d'),
                'src_warehouse_id' => $src,
                'dst_warehouse_id' => $dst,
                'note' => $data['note'] ?? null,
                'updated_by' => $userId,
            ]);

            $request->lines()->delete();
            $this->syncLines($request, $data['lines']);

            AuditLogger::record('UPDATE', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'), afterData: ['request_no' => $request->request_no]);

            return $request->load(['lines.product', 'srcWarehouse', 'dstWarehouse']);
        });
    }

    public function submit(string $id, string $userId): StockRequest
    {
        return DB::transaction(function () use ($id, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_DRAFT], 'gửi duyệt');

            if ($request->lines()->count() < 1) {
                ApiError::abort(422, 'VALIDATION_FAILED', 'Yêu cầu phải có ít nhất 1 dòng sản phẩm.');
            }

            $request->update([
                'status' => StockRequest::STATUS_PENDING,
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);

            return $request->fresh('lines');
        });
    }

    public function approve(string $id, string $userId): StockRequest
    {
        return DB::transaction(function () use ($id, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_PENDING], 'duyệt');

            $request->update([
                'status' => StockRequest::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            AuditLogger::record('APPROVE', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'));

            return $request->fresh('lines');
        });
    }

    public function reject(string $id, string $reason, string $userId): StockRequest
    {
        return DB::transaction(function () use ($id, $reason, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_PENDING], 'từ chối duyệt');

            $request->update([
                'status' => StockRequest::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'reject_reason' => $reason,
            ]);

            AuditLogger::record('REJECT', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $request->fresh('lines');
        });
    }

    public function cancel(string $id, ?string $reason, string $userId): StockRequest
    {
        return DB::transaction(function () use ($id, $reason, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_DRAFT, StockRequest::STATUS_PENDING, StockRequest::STATUS_APPROVED], 'hủy');

            $request->update([
                'status' => StockRequest::STATUS_CANCELLED,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            AuditLogger::record('CANCEL', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'), reason: $reason);

            return $request->fresh('lines');
        });
    }

    /** Chuyển đổi yêu cầu đã approved thành stock_document thật (draft), khởi động vòng đời nghiệp vụ. */
    public function convert(string $id, string $userId): StockDocument
    {
        return DB::transaction(function () use ($id, $userId) {
            $request = $this->lock($id);
            $this->assertStatus($request, [StockRequest::STATUS_APPROVED], 'chuyển đổi thành phiếu');

            if ($request->request_type === StockRequest::TYPE_TRANSFER) {
                ApiError::abort(422, 'VALIDATION_FAILED', 'Yêu cầu điều chuyển kho cần dùng transfer-orders để chuyển đổi (chưa hỗ trợ tự động convert sang stock_documents).');
            }

            if ($request->converted_doc_id !== null) {
                WarehouseException::invalidStatus('Yêu cầu này đã được chuyển thành phiếu trước đó.');
            }

            $docData = [
                'src_warehouse_id' => $request->src_warehouse_id,
                'dst_warehouse_id' => $request->dst_warehouse_id,
                'note' => $request->note ? ('[Tạo từ Yêu cầu '.$request->request_no.'] '.$request->note) : ('[Tạo từ Yêu cầu '.$request->request_no.']'),
                'doc_date' => now()->format('Y-m-d'),
                'lines' => $request->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'qty_planned' => (string) $line->qty_planned,
                ])->toArray(),
            ];

            $doc = $this->documentService->create($request->request_type, $docData, $userId);

            $request->update([
                'status' => StockRequest::STATUS_CONVERTED,
                'converted_doc_id' => $doc->id,
            ]);

            AuditLogger::record('CONVERT', 'stock_request', $request->id, $userId, request()?->attributes->get('request_id'), afterData: ['doc_id' => $doc->id, 'doc_no' => $doc->doc_no]);

            return $doc->load(['lines.product', 'srcWarehouse', 'dstWarehouse']);
        });
    }

    private function syncLines(StockRequest $request, array $lines): void
    {
        foreach (array_values($lines) as $index => $l) {
            $request->lines()->create([
                'line_no' => $index + 1,
                'product_id' => $l['product_id'],
                'qty_planned' => $l['qty_planned'],
                'unit_cost' => $l['unit_cost'] ?? null,
                'note' => $l['note'] ?? null,
            ]);
        }
    }

    private function lock(string $id): StockRequest
    {
        $request = StockRequest::where('id', $id)->lockForUpdate()->first();
        if (! $request) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy yêu cầu.');
        }

        return $request;
    }

    private function assertStatus(StockRequest $request, array $allowed, string $action): void
    {
        if (! in_array($request->status, $allowed, true)) {
            WarehouseException::invalidStatus("Không thể {$action} khi yêu cầu đang ở trạng thái \"{$request->status}\".");
        }
    }

    private function assertWarehousePair(string $type, ?string $src, ?string $dst): void
    {
        if ($type === StockRequest::TYPE_TRANSFER && $src !== null && $dst !== null && $src === $dst) {
            ApiError::abort(422, 'VALIDATION_FAILED', 'Kho nguồn và kho đích không được trùng nhau.');
        }

        $this->assertWarehouseActive($src);
        $this->assertWarehouseActive($dst);
    }

    private function assertWarehouseActive(?string $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            ApiError::abort(422, 'VALIDATION_FAILED', 'Kho không tồn tại.');
        }
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(422, 'VALIDATION_FAILED', "Kho \"{$warehouse->name}\" đang ngừng hoạt động, không thể thực hiện nghiệp vụ.");
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
