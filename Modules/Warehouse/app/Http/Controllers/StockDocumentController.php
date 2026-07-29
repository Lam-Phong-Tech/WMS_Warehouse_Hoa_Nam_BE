<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\StoreStockDocumentRequest;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;

/**
 * Chứng từ nhập/xuất/điều chuyển kho — full state machine port từ M2 (ERP-Backend).
 * Mọi hành động thực sự đổi tồn (post/dispatch/receive/confirm-return) đi qua
 * App\Support\IdempotencyStore — cơ chế idempotency DUY NHẤT của dự án.
 */
class StockDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockDocumentService $service) {}

    public function index(Request $request)
    {
        $query = StockDocument::query()->with('lines');

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->input('doc_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }

    public function show(string $id)
    {
        $doc = StockDocument::with(['lines', 'movements', 'statusHistory', 'inTransitLedgers'])->find($id);

        return $doc ? $this->respondSuccess($doc) : $this->respondNotFound('Không tìm thấy chứng từ.');
    }

    public function store(StoreStockDocumentRequest $request)
    {
        $userId = $this->userId();
        $idemKey = $request->header('Idempotency-Key');
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, 'create_stock_document', $userId, $hash, function () use ($request, $userId) {
            $doc = $this->service->create($request->input('doc_type'), $request->validated(), $userId);

            return [201, $this->envelope('Tạo chứng từ thành công', $doc)];
        });

        return response()->json($body, $code);
    }

    public function submit(string $id)
    {
        return $this->respondSuccess($this->service->submit($this->find($id), $this->userId()), 'Gửi duyệt thành công');
    }

    public function approve(string $id)
    {
        return $this->respondSuccess($this->service->approve($this->find($id), $this->userId()), 'Duyệt chứng từ thành công');
    }

    public function reject(string $id, Request $request)
    {
        return $this->respondSuccess($this->service->reject($this->find($id), $request->input('reason'), $this->userId()), 'Từ chối duyệt thành công');
    }

    public function cancel(string $id, Request $request)
    {
        return $this->respondSuccess($this->service->cancel($this->find($id), $this->userId(), $request->input('reason')), 'Huỷ chứng từ thành công');
    }

    /** Thực hiện nhập/xuất kho thật (đổi tồn) — bắt buộc Idempotency-Key. */
    public function post(string $id, Request $request)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, "post_stock_document_{$doc->id}", $this->userId(), $hash, function () use ($doc) {
            $posted = $doc->doc_type === StockDocument::TYPE_RECEIPT
                ? $this->service->postReceipt($doc, $this->userId())
                : $this->service->postIssue($doc, $this->userId());

            return [200, $this->envelope('Post chứng từ thành công', $posted)];
        });

        return response()->json($body, $code);
    }

    public function dispatch(string $id, Request $request)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, "dispatch_stock_document_{$doc->id}", $this->userId(), $hash, function () use ($doc) {
            return [200, $this->envelope('Xuất điều chuyển thành công', $this->service->dispatch($doc, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function receive(string $id, Request $request)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);
        $linesInput = collect($request->input('lines', []))->keyBy('line_id')->map(fn ($l) => $l['qty_actual'])->all();

        [$code, $body] = IdempotencyStore::handle($idemKey, "receive_stock_document_{$doc->id}", $this->userId(), $hash, function () use ($doc, $linesInput) {
            return [200, $this->envelope('Nhận hàng điều chuyển thành công', $this->service->receive($doc, $linesInput, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function recordLoss(string $id, Request $request)
    {
        $doc = $this->find($id);
        $linesInput = collect($request->input('lines', []))->keyBy('line_id')->map(fn ($l) => $l['qty_lost'])->all();

        return $this->respondSuccess(
            $this->service->recordTransferLoss($doc, $linesInput, $this->userId(), $request->input('reason')),
            'Ghi nhận mất mát thành công'
        );
    }

    public function returnTransfer(string $id, Request $request)
    {
        return $this->respondSuccess($this->service->returnTransfer($this->find($id), $request->input('reason'), $this->userId()), 'Ghi nhận yêu cầu trả hàng thành công');
    }

    public function confirmReturn(string $id, Request $request)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, "confirm_return_stock_document_{$doc->id}", $this->userId(), $hash, function () use ($doc) {
            return [200, $this->envelope('Xác nhận nhận lại hàng trả về thành công', $this->service->confirmReturn($doc, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    private function find(string $id): StockDocument
    {
        $doc = StockDocument::with('lines')->find($id);
        if (! $doc) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $doc;
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc cho thao tác này.');
        }

        return $idemKey;
    }

    private function envelope(string $message, $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data->toArray(),
            'meta' => ['request_id' => $this->currentRequestId()],
        ];
    }

    private function userId(): string
    {
        return (string) auth('api')->id();
    }
}
