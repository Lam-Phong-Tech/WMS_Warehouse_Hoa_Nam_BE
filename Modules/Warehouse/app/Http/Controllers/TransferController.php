<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Warehouse\Http\Controllers\Concerns\InteractsWithStockDocuments;
use Modules\Warehouse\Http\Requests\StoreTransferRequest;
use Modules\Warehouse\Http\Requests\UpdateTransferRequest;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;
use Modules\Warehouse\Services\TransferService;
use OpenApi\Attributes as OA;

/**
 * Phiếu điều chuyển kho (doc_type=transfer). Cần ≥ 2 kho khác nhau (src ≠ dst) để có ý nghĩa
 * thực tế — base hiện chỉ có 1 kho tổng nên nhánh nghiệp vụ này port sẵn code, chưa dùng được
 * cho tới khi Hoà Nam thêm kho thứ 2 (xem docs/BRD_ERD_Upgrade_Guide.md).
 *
 * index/show/submit/approve/reject/cancel dùng chung Concerns\InteractsWithStockDocuments với
 * Inbound/Outbound; dispatch/receive/record-loss/return/confirm-return là action riêng của
 * transfer, xử lý qua TransferService.
 */
#[OA\Get(
    path: '/api/v1/transfer-orders',
    operationId: 'transfer_list',
    summary: 'Danh sách phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'pending', 'approved', 'in_transit', 'return_in_transit', 'completed', 'completed_with_loss', 'returned', 'cancelled', 'rejected'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách phiếu điều chuyển'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.view'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders',
    operationId: 'transfer_create',
    summary: 'Tạo phiếu điều chuyển kho (nháp)',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['src_warehouse_id', 'dst_warehouse_id', 'lines'],
            properties: [
                new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'src_warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qty_planned', type: 'number', format: 'float'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Tạo phiếu điều chuyển thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.create'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: src = dst, kho không active)'),
    ]
)]
#[OA\Get(
    path: '/api/v1/transfer-orders/{id}',
    operationId: 'transfer_show',
    summary: 'Chi tiết phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết phiếu điều chuyển'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/transfer-orders/{id}',
    operationId: 'transfer_update',
    summary: 'Sửa phiếu điều chuyển kho (chỉ khi còn draft)',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Cập nhật thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc phiếu không còn ở trạng thái draft'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/submit',
    operationId: 'transfer_submit',
    summary: 'Gửi duyệt phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Gửi duyệt thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/approve',
    operationId: 'transfer_approve',
    summary: 'Duyệt phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Duyệt thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 422, description: 'Kho nguồn không đủ tồn hoặc kho không active'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/reject',
    operationId: 'transfer_reject',
    summary: 'Từ chối duyệt phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Từ chối thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/cancel',
    operationId: 'transfer_cancel',
    summary: 'Hủy phiếu điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Hủy thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/dispatch',
    operationId: 'transfer_dispatch',
    summary: 'Xuất điều chuyển (approved → in_transit, trừ tồn kho nguồn)',
    description: 'Đổi tồn kho thật. Bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Đã xuất điều chuyển'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/receive',
    operationId: 'transfer_receive',
    summary: 'Nhận hàng điều chuyển tại kho đích (in_transit → completed|completed_with_loss)',
    description: 'Đổi tồn kho thật (cộng vào kho đích theo qty_actual). Bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['lines'],
            properties: [
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qty_actual', type: 'number', format: 'float'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Đã nhận hàng điều chuyển'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ hoặc dòng'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (qty_actual vượt quá qty_dispatched)'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/record-loss',
    operationId: 'transfer_record_loss',
    summary: 'Ghi nhận hàng mất mát trong quá trình vận chuyển',
    description: 'Không tạo biến động tồn (hàng chưa từng cộng vào kho đích) — chỉ ghi nhận trên in_transit_ledger. Bắt buộc header Idempotency-Key để chống ghi trùng.',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['lines'],
            properties: [
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qty_lost', type: 'number', format: 'float'),
                        new OA\Property(property: 'reason', type: 'string'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Đã ghi nhận hàng mất mát'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ hoặc dòng'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 422, description: 'Số lượng mất mát vượt quá số lượng còn lại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/return',
    operationId: 'transfer_return',
    summary: 'Bước 1: yêu cầu trả lại hàng điều chuyển (in_transit → return_in_transit)',
    description: 'KHÔNG đổi tồn kho thật — chỉ đánh dấu ý định trả hàng. Tồn kho nguồn chỉ thật sự được cộng lại ở bước confirm-return.',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Đã đánh dấu trả lại hàng điều chuyển'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/transfer-orders/{id}/confirm-return',
    operationId: 'transfer_confirm_return',
    summary: 'Bước 2: kho nguồn xác nhận đã nhận lại hàng trả về (return_in_transit → returned)',
    description: 'Đổi tồn kho thật — cộng lại tồn khả dụng kho nguồn. Bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['10. Transfer'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Đã xác nhận nhận lại hàng trả về'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền transfer.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class TransferController extends Controller
{
    use ApiResponse;
    use InteractsWithStockDocuments;

    public function __construct(
        private readonly StockDocumentService $documentService,
        private readonly TransferService $transferService,
    ) {}

    protected function docType(): string
    {
        return StockDocument::TYPE_TRANSFER;
    }

    protected function service(): StockDocumentService
    {
        return $this->documentService;
    }

    public function store(StoreTransferRequest $request)
    {
        $userId = $this->userId();
        $idemKey = $request->header('Idempotency-Key');
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, 'create_transfer_document', $userId, $hash, function () use ($request, $userId) {
            $doc = $this->documentService->create(StockDocument::TYPE_TRANSFER, $request->validated(), $userId);

            return [201, $this->envelope('Tạo phiếu điều chuyển thành công', $doc)];
        });

        return response()->json($body, $code);
    }

    public function update(UpdateTransferRequest $request, string $id)
    {
        $doc = $this->documentService->update($this->find($id), $request->validated(), $this->userId());

        return $this->respondSuccess($doc, 'Cập nhật phiếu điều chuyển thành công');
    }

    public function dispatch(Request $request, string $id)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, "transfer_dispatch_{$doc->id}", $this->userId(), $hash, function () use ($doc) {
            return [200, $this->envelope('Đã xuất điều chuyển', $this->transferService->dispatch($doc, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function receive(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.qty_actual' => ['required', 'numeric', 'gte:0'],
        ]);
        if ($validator->fails()) {
            return $this->respondError('Dữ liệu đầu vào không hợp lệ', $validator->errors()->toArray(), 422, 'VALIDATION_FAILED');
        }

        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);
        $lines = $validator->validated()['lines'];

        [$code, $body] = IdempotencyStore::handle($idemKey, "transfer_receive_{$doc->id}", $this->userId(), $hash, function () use ($doc, $lines) {
            return [200, $this->envelope('Đã nhận hàng điều chuyển', $this->transferService->receive($doc, $lines, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function recordLoss(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.qty_lost' => ['required', 'numeric', 'gt:0'],
            'lines.*.reason' => ['required', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            return $this->respondError('Dữ liệu đầu vào không hợp lệ', $validator->errors()->toArray(), 422, 'VALIDATION_FAILED');
        }

        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);
        $lines = $validator->validated()['lines'];

        [$code, $body] = IdempotencyStore::handle($idemKey, "transfer_record_loss_{$doc->id}", $this->userId(), $hash, function () use ($doc, $lines) {
            return [200, $this->envelope('Đã ghi nhận hàng mất mát', $this->transferService->recordLoss($doc, $lines, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function returnTransfer(Request $request, string $id)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);
        $reason = $request->input('reason');

        [$code, $body] = IdempotencyStore::handle($idemKey, "transfer_return_{$doc->id}", $this->userId(), $hash, function () use ($doc, $reason) {
            return [200, $this->envelope('Đã trả lại hàng điều chuyển', $this->transferService->returnTransfer($doc, $reason, $this->userId()))];
        });

        return response()->json($body, $code);
    }

    public function confirmReturn(Request $request, string $id)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, "transfer_confirm_return_{$doc->id}", $this->userId(), $hash, function () use ($doc) {
            return [200, $this->envelope('Đã xác nhận kho nguồn nhận lại hàng trả về', $this->transferService->confirmReturn($doc, $this->userId()))];
        });

        return response()->json($body, $code);
    }
}
