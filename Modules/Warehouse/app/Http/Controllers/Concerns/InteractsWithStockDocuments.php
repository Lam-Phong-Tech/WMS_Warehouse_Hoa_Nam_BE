<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;

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
        $doc = StockDocument::with(['lines', 'movements', 'statusHistory'])
            ->where('doc_type', $this->docType())
            ->find($id);

        return $doc
            ? $this->respondSuccess($doc)
            : $this->respondNotFound('Không tìm thấy chứng từ.');
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
