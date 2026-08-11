<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->applyKeyword($query, $request, ['doc_no', 'note']);

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    public function show(string $id)
    {
        $doc = StockDocument::with(['lines', 'movements', 'statusHistory'])->where('doc_type', $this->docType())->find($id);

        return $doc ? $this->respondSuccess($doc) : $this->respondNotFound('Không tìm thấy chứng từ.');
    }

    public function submit(string $id)
    {
        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->submit($doc, $this->userId()),
            'Gửi duyệt thành công'
        ));
    }

    public function approve(string $id)
    {
        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->approve($doc, $this->userId()),
            'Duyệt chứng từ thành công'
        ));
    }

    public function reject(string $id, Request $request)
    {
        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->reject($doc, $request->input('reason'), $this->userId()),
            'Từ chối duyệt thành công'
        ));
    }

    public function cancel(string $id, Request $request)
    {
        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->cancel($doc, $this->userId(), $request->input('reason')),
            'Huỷ chứng từ thành công'
        ));
    }

    /** Scan từng dòng chỉ validate/classify, không ghi movement và không đổi tồn. */
    public function scan(string $id, Request $request)
    {
        $validated = $request->validate([
            'line_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->validateScanLine($doc, $validated['line_id'], (string) $validated['qty']),
            'Scan validated'
        ));
    }

    /** Đóng phần còn thiếu (shortage) — không đổi tồn nên không bắt buộc Idempotency-Key. */
    public function closeRemainder(string $id, Request $request)
    {
        return $this->withLockedDocument($id, fn (StockDocument $doc) => $this->respondSuccess(
            $this->service()->closeRemainder($doc, $this->userId(), $request->input('reason')),
            'Đóng phần còn thiếu thành công'
        ));
    }

    /** Đảo ngược chứng từ đã hạch toán — bắt buộc Idempotency-Key vì đổi tồn thật. */
    public function reverse(string $id, Request $request)
    {
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);
        $operation = "reverse_stock_document_{$id}";

        [$code, $body] = IdempotencyStore::handle($idemKey, $operation, $this->userId(), $hash, function () use ($id, $request, $idemKey, $hash, $operation) {
            return $this->withLockedDocument($id, fn (StockDocument $doc) => [
                200,
                $this->envelope(
                    'Đảo ngược chứng từ thành công',
                    $this->service()->reverseDocument($doc, $this->userId(), $request->input('reason'), $idemKey, $hash, $operation)
                ),
            ]);
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

    /**
     * Khoá row chứng từ trước mọi state transition/scan để request cạnh tranh không dùng
     * model status cũ. Transaction lồng trong service dùng cùng connection và cùng row lock.
     */
    protected function withLockedDocument(string $id, callable $callback): mixed
    {
        return DB::transaction(function () use ($id, $callback) {
            $doc = StockDocument::query()
                ->with('lines')
                ->where('doc_type', $this->docType())
                ->lockForUpdate()
                ->find($id);

            if (! $doc) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
            }

            return $callback($doc);
        }, 3);
    }

    protected function requireIdempotencyKey(Request $request): string
    {
        return IdempotencyKeyGuard::require($request);
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
