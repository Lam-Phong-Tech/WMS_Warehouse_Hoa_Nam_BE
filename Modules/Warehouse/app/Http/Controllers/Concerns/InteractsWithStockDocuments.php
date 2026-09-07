<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;
use Modules\Warehouse\Support\SkuInboundInfoRule;

/**
 * Read-side logic shared by InboundDocumentController/OutboundDocumentController.
 *
 * Canonical runtime writes are intentionally NOT implemented in this concern.
 * Inbound/outbound mutations are routed exclusively through the current-baseline
 * controllers so superseded submit/approve/reject/partial/remainder transitions
 * cannot be reintroduced accidentally through inherited controller methods.
 */
trait InteractsWithStockDocuments
{
    abstract protected function docType(): string;

    abstract protected function service(): StockDocumentService;

    public function index(Request $request)
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', $this->docType());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $keywordColumns = ['doc_no', 'note'];
        if ($this->docType() === StockDocument::TYPE_RECEIPT) {
            $keywordColumns[] = 'source_name';
        }

        $this->applyKeyword($query, $request, $keywordColumns);

        return $this->respondPaginated(
            $query->orderByDesc('created_at')->paginate($this->perPage($request))
        );
    }

    public function show(string $id)
    {
        // `lines.product_id` chỉ là UUID thô — FE (bảng "Hàng thực nhận/xuất theo
        // SKU") cần mã/tên SKU đọc được ngay trên từng dòng, không được bắt phải tự
        // tra qua danh sách SKU tải sẵn ở màn khác (danh sách đó có thể phân trang và
        // KHÔNG chứa hết mọi SKU trong hệ thống — sai với SKU nằm ngoài trang đầu).
        // Eager-load quan hệ product (Sku qua product_id -> sku_id) rồi gán thẳng
        // product_code/product_name/product_type vào từng line, cùng quy ước field
        // đã dùng ở OperationalReportController::documentHistoryLines(). product_type
        // (PRODUCT/COMPONENT) cho phép FE hiện badge "Sản phẩm/Linh kiện" ngay trên
        // dòng, không phụ thuộc danh sách SKU tải sẵn (bị giới hạn per_page) như
        // product_code/product_name ở trên.
        $doc = StockDocument::with(['lines.product:sku_id,sku_code,sku_name,sku_type,unit', 'movements', 'statusHistory'])
            ->where('doc_type', $this->docType())
            ->find($id);

        if ($doc === null) {
            return $this->respondNotFound('Không tìm thấy chứng từ.');
        }

        $doc->lines->each(function ($line): void {
            $line->setAttribute('product_code', $line->product?->sku_code);
            $line->setAttribute('product_name', $line->product?->sku_name);
            $line->setAttribute('product_type', $line->product?->sku_type);
            // ĐVT đi kèm để form "Bổ sung thông tin" prefill đúng giá trị đang có,
            // không bắt người dùng gõ lại (gõ lại = có ngày gõ thiếu, ghi đè mất).
            $line->setAttribute('product_unit', $line->product?->unit);
            // SKU sinh tự động lúc quét (materializeSkuIfMissing) mới chỉ có mã:
            // tên tạm bằng chính mã, ĐVT rỗng. FE cần biết NGAY trên từng dòng để
            // hiện nút bổ sung thông tin và khoá nút Ghi nhận — không suy ra được từ
            // danh sách SKU tải sẵn ở màn khác (danh sách đó bị giới hạn per_page
            // nên thiếu đúng những SKU vừa sinh ra). Cùng MỘT luật với guard chặn
            // Post Receipt ở CurrentBaselineStockFlowController::postDocument().
            $missingInfo = SkuInboundInfoRule::missingFields(
                $line->product?->sku_code,
                $line->product?->sku_name,
                $line->product?->unit,
            );
            $line->setAttribute('product_missing_info', $missingInfo);
            $line->setAttribute('product_info_complete', $missingInfo === []);
            $line->unsetRelation('product');
        });

        return $this->respondSuccess($doc);
    }

    /**
     * TransferController::update/dispatch/receive/recordLoss/returnTransfer/
     * confirmReturn gọi $this->find($id) và dùng thẳng kết quả (vd $doc->id)
     * mà không null-check — method này trước đó không tồn tại ở bất kỳ đâu
     * trong chain kế thừa (Controller/ApiResponse/trait này), nên mọi lời gọi
     * sẽ ném "Call to undefined method" (500) ngay khi route transfer-orders
     * được wire. Định nghĩa ở đây để cùng chỗ với show() (cùng nguồn sự thật
     * về cách lọc theo doc_type) và fail rõ ràng bằng 404 thay vì 500 khi
     * không tìm thấy.
     */
    protected function find(string $id): StockDocument
    {
        $doc = StockDocument::where('doc_type', $this->docType())->find($id);

        if ($doc === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $doc;
    }

    protected function requireIdempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Idempotency-Key là bắt buộc.');
        }
        if (strlen($key) < 8 || strlen($key) > 100) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Idempotency-Key phải từ 8 đến 100 ký tự.');
        }

        return $key;
    }
}
