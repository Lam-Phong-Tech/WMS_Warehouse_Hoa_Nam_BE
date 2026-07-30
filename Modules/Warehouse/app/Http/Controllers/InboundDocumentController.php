<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Controllers\Concerns\InteractsWithStockDocuments;
use Modules\Warehouse\Http\Requests\StoreInboundDocumentRequest;
use Modules\Warehouse\Http\Requests\UpdateInboundDocumentRequest;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;
use OpenApi\Attributes as OA;

/**
 * Phiếu nhập kho (doc_type=receipt).
 *
 * Logic của index/show/submit/approve/reject/cancel/scan/close-remainder/reverse nằm ở
 * Concerns\InteractsWithStockDocuments (dùng chung với OutboundDocumentController) — annotation
 * Swagger cho các action đó được khai báo ở đây (class-level) vì path/tag riêng cho từng loại
 * chứng từ, trong khi PHP method vật lý nằm ở trait dùng chung.
 */
#[OA\Get(
    path: '/api/v1/inbound-documents',
    operationId: 'stock_in_list',
    summary: 'Danh sách phiếu nhập kho (phân trang, filter theo status)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'pending', 'approved', 'partially_posted', 'received', 'closed', 'reversed', 'cancelled', 'rejected'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách phiếu nhập kho'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.view'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents',
    operationId: 'stock_in_create',
    summary: 'Tạo phiếu nhập kho (trạng thái draft)',
    description: 'Phiếu ở trạng thái draft chưa ảnh hưởng tồn kho. Header Idempotency-Key là tuỳ chọn ở action này (chỉ bắt buộc ở post-receipt/scan/reverse vì các action đó đổi tồn thật) — nếu có gửi, gọi lại đúng key sẽ trả về đúng chứng từ đã tạo thay vì tạo bản ghi mới.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['dst_warehouse_id', 'lines'],
            properties: [
                new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qty_planned', type: 'number', format: 'float', example: 10),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Tạo phiếu nhập kho thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.create'),
        new OA\Response(response: 409, description: 'Idempotency-Key đã dùng với payload khác'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: kho không active, thiếu lines)'),
    ]
)]
#[OA\Get(
    path: '/api/v1/inbound-documents/{id}',
    operationId: 'stock_in_detail',
    summary: 'Chi tiết phiếu nhập kho (kèm lines, movements, status history)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết phiếu nhập kho'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/inbound-documents/{id}',
    operationId: 'stock_in_update',
    summary: 'Sửa phiếu nhập kho (chỉ khi còn draft)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true),
            new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
            new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items(
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'qty_planned', type: 'number', format: 'float'),
                ]
            )),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Cập nhật phiếu nhập kho thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.create'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc phiếu không còn ở trạng thái draft'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/submit',
    operationId: 'stock_in_submit',
    summary: 'Gửi duyệt phiếu nhập kho (draft → pending)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Gửi duyệt thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.submit'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/approve',
    operationId: 'stock_in_approve',
    summary: 'Duyệt phiếu nhập kho (pending → approved)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Duyệt phiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.approve'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/reject',
    operationId: 'stock_in_reject',
    summary: 'Từ chối duyệt phiếu nhập kho (pending → rejected)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Từ chối phiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.approve'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/cancel',
    operationId: 'stock_in_cancel',
    summary: 'Huỷ phiếu nhập kho (chỉ khi chưa hạch toán xong)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Huỷ phiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.cancel'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/post-receipt',
    operationId: 'stock_in_post_receipt',
    summary: 'Hạch toán nhập kho toàn bộ trong 1 lần (approved → received, tăng tồn thật)',
    description: 'Đổi tồn kho thật qua StockLedger. Bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Hạch toán nhập kho thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Idempotency-Key đã dùng với payload khác hoặc xung đột optimistic lock'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/scan',
    operationId: 'stock_in_scan',
    summary: 'Ghi nhận nhập kho từng phần theo dòng (scan từng line qty)',
    description: 'Cho phép hạch toán từng phần (partially_posted) trước khi đủ toàn bộ số lượng kế hoạch. Đổi tồn thật, bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['line_id', 'qty'],
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'qty', type: 'number', format: 'float', example: 5),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Ghi nhận scan thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ hoặc dòng'),
        new OA\Response(response: 409, description: 'Idempotency-Key đã dùng với payload khác hoặc xung đột optimistic lock'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại hoặc qty vượt số lượng kế hoạch còn lại'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/close-remainder',
    operationId: 'stock_in_close_remainder',
    summary: 'Đóng phần còn thiếu (shortage) của phiếu đang partially_posted',
    description: 'Không đổi tồn kho nên không bắt buộc Idempotency-Key. Đóng phiếu ở trạng thái received/closed với phần chưa nhập đủ được ghi nhận là thiếu.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Đóng phần còn thiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 422, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/reverse',
    operationId: 'stock_in_reverse',
    summary: 'Đảo ngược (reverse/correction) phiếu nhập đã hạch toán',
    description: 'Sinh movement đảo ngược liên kết ngược tới movement gốc qua original_movement_id. Đổi tồn thật, bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Đảo ngược chứng từ thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.reverse'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Idempotency-Key đã dùng với payload khác hoặc xung đột optimistic lock'),
        new OA\Response(response: 422, description: 'Chứng từ chưa hạch toán hoặc đã bị đảo ngược trước đó'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class InboundDocumentController extends Controller
{
    use ApiResponse;
    use InteractsWithStockDocuments;

    public function __construct(private readonly StockDocumentService $documentService) {}

    protected function docType(): string
    {
        return StockDocument::TYPE_RECEIPT;
    }

    protected function service(): StockDocumentService
    {
        return $this->documentService;
    }

    public function store(StoreInboundDocumentRequest $request)
    {
        $userId = $this->userId();
        $idemKey = $request->header('Idempotency-Key');
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle($idemKey, 'create_inbound_document', $userId, $hash, function () use ($request, $userId) {
            $doc = $this->documentService->create(StockDocument::TYPE_RECEIPT, $request->validated(), $userId);

            return [201, $this->envelope('Tạo phiếu nhập kho thành công', $doc)];
        });

        return response()->json($body, $code);
    }

    public function update(UpdateInboundDocumentRequest $request, string $id)
    {
        $doc = $this->documentService->update($this->find($id), $request->validated(), $this->userId());

        return $this->respondSuccess($doc, 'Cập nhật phiếu nhập kho thành công');
    }

    /** Hạch toán toàn bộ trong 1 lần (approved → received, đổi tồn thật). */
    public function postReceipt(Request $request, string $id)
    {
        $doc = $this->find($id);
        $idemKey = $this->requireIdempotencyKey($request);
        $hash = IdempotencyStore::requestHash($request);

        $operation = "post_receipt_{$doc->id}";
        [$code, $body] = IdempotencyStore::handle($idemKey, $operation, $this->userId(), $hash, function () use ($doc, $idemKey, $hash, $operation) {
            return [200, $this->envelope('Hạch toán nhập kho thành công', $this->documentService->postReceipt($doc, $this->userId(), $idemKey, $hash, $operation))];
        });

        return response()->json($body, $code);
    }
}
