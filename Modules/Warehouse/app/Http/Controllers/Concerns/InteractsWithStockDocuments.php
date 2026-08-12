<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

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
}
