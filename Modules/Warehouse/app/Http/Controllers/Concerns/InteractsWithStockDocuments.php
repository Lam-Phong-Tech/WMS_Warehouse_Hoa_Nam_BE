<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

use App\Support\ApiError;
use App\Support\IdempotencyStore;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;

/**
 * Logic dùng chung cho InboundDocumentController/OutboundDocumentController — chỉ khác nhau
 * ở doc_type và tên hành động post (post-receipt/post-issue). Mọi thao tác đổi tồn thật
 * (post-*, reverse) đi qua App\Support\IdempotencyStore — cơ chế idempotency DUY NHẤT
 * của dự án. Scan chứng từ chỉ validate/classify dòng, stock_effect=NONE.
 */
trait InteractsWithStockDocuments
{
    abstract protected function docType(): string;

    abstract protected function service(): StockDocumentService;

    public function index(Request $request)
    {
        $query = StockDocument::query()->with('lines')->where('doc_type', $this->docType());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }

    public function show(string $id)
    {
        $doc = StockDocument::with(['lines', 'movements', 'statusHistory'])->where('doc_type', $this->docType())->find($id);

        return $doc ? $this->respondSuccess($doc) : $this->respondNotFound('Không tìm thấy chứng từ.');
    }

    public function submit(string $id)
    {
        return $this->respondSuccess($this->service()->submit($this->find($id), $this->userId()), 'Gửi duyệt thành công');
    }

    public function approve(string $id)
    {
        return $this->respondSuccess($this->service()->approve($this->find($id), $this->userId()), 'Duyệt chứng từ thành công');
    }

    public function reject(string $id, Request $request)
    {
        return $this->respondSuccess($this->service()->reject($this->find($id), $request->input('reason'), $this->userId()), 'Từ chối duyệt thành công');
    }

    public function cancel(string $id, Request $request)
    {
        return $this->respondSuccess($this->service()->cancel($this->find($id), $this->userId(), $request->input('reason')), 'Huỷ chứng từ thành công');
    }

    /** Scan từng dòng chỉ validate/classify, không ghi movement và không đổi tồn. */
    public function scan(string $id, Request $request)
    {
        $doc = $this->find($id);
        $validated = $request->validate([
            'line_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->respondSuccess(
            $this->service()->validateScanLine($doc, $validated['line_id'], (string) $validated['qty']),
            'Scan validated'
        );
    }

    /** Đóng phần còn thiếu (shortage) — không đổi tồn nên không bắt buộc Idempotency-Key. */
    public function closeRemainder(string $id, Request $request)
    {
        $doc = $this->find($id);

        return $this->respondSuccess(
            $this->service()->closeRemainder($doc, $this->userId(), $request->input('reason')),
            'Đóng phần còn thiếu thành công'
        );
    }

    /** Đảo ngược chứng từ đã hạch toán — bắt buộc Idempotency-Key vì đổi tồn thật. */
    public function reverse(string $id, Request $request)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        $operation = "reverse_stock_document_{$doc->id}";
        [$code, $body] = IdempotencyStore::handle($idemKey, $operation, $this->userId(), $hash, function () use ($doc, $request, $idemKey, $hash, $operation) {
            return [200, $this->envelope('Đảo ngược chứng từ thành công', $this->service()->reverseDocument($doc, $this->userId(), $request->input('reason'), $idemKey, $hash, $operation))];
        });

        return response()->json($body, $code);
    }

    protected function find(string $id): StockDocument
    {
        $doc = StockDocument::with('lines')->where('doc_type', $this->docType())->find($id);
        if (! $doc) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $doc;
    }

    protected function requireIdempotencyKey(Request $request): string
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc cho thao tác này.');
        }

        return $idemKey;
    }

    protected function envelope(string $message, $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => is_array($data) ? $data : $data->toArray(),
            'meta' => ['request_id' => $this->currentRequestId()],
        ];
    }

    protected function userId(): string
    {
        return (string) auth('api')->id();
    }
}
