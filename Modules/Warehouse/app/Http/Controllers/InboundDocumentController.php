<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Warehouse\Http\Controllers\Concerns\InteractsWithStockDocuments;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;
use OpenApi\Attributes as OA;

/**
 * Phiếu nhập kho (doc_type=receipt).
 *
 * Baseline hiện hành chỉ có state machine DRAFT -> SCANNING -> POSTED/CANCELLED.
 * Không tồn tại approval workflow hoặc vòng đời nhận hàng một phần; chỉ dùng Full Post.
 * Runtime write actions được route tới CurrentBaselineDocumentController /
 * CurrentBaselineStockFlowController; các annotation dưới đây chỉ mô tả contract thật
 * để RouteSyncProcessor giữ schema chi tiết khi đồng bộ OpenAPI theo Laravel route list.
 */
#[OA\Get(
    path: '/api/v1/inbound-documents',
    operationId: 'stock_in_list',
    summary: 'Danh sách phiếu nhập kho (phân trang, tìm kiếm, lọc trạng thái)',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách phiếu nhập kho'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.view'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents',
    operationId: 'stock_in_create',
    summary: 'Tạo phiếu nhập kho DRAFT',
    description: 'Tạo chứng từ DRAFT và các dòng Expected. DRAFT chưa làm thay đổi tồn kho. Idempotency-Key là tuỳ chọn khi tạo.',
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
                new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true, example: 'HN-IN-000123'),
                new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true, example: '2026-08-21'),
                new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'source_name', type: 'string', maxLength: 255, nullable: true, example: 'Nhà cung cấp Hòa Nam'),
                new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Nhập hàng đợt 1'),
                new OA\Property(property: 'lines', type: 'array', minItems: 1, items: new OA\Items(
                    required: ['product_id', 'qty_planned'],
                    properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '4b6f1c2a-8e3d-4f5a-9b2c-1d3e4f5a6b7c'),
                        new OA\Property(property: 'import_batch_id', type: 'string', format: 'uuid', nullable: true, example: '5c7d2e3b-9f4e-4a6b-8c3d-2e4f5a6b7c8d'),
                        new OA\Property(property: 'qty_planned', type: 'integer', minimum: 1, example: 10),
                        new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Ghi chú dòng nhập'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Tạo phiếu nhập DRAFT thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.create'),
        new OA\Response(response: 409, description: 'Xung đột Idempotency-Key'),
        new OA\Response(response: 422, description: 'Kho/SKU/dòng dữ liệu không hợp lệ'),
    ]
)]
#[OA\Get(
    path: '/api/v1/inbound-documents/{id}',
    operationId: 'stock_in_detail',
    summary: 'Chi tiết phiếu nhập kho và các dòng Expected/Scanned',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết phiếu nhập kho'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.view'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/inbound-documents/{id}',
    operationId: 'stock_in_update',
    summary: 'Sửa phiếu DRAFT/SCANNING và hiệu chỉnh Expected an toàn',
    description: 'Bắt buộc If-Match. Khi SCANNING đã có scan evidence, line ID/SKU/import batch phải giữ nguyên; chỉ được hiệu chỉnh Expected/ghi chú và Expected không được nhỏ hơn scanned_qty. Không xoá/recreate scan evidence.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true, example: 'HN-IN-000123'),
            new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true, example: '2026-08-21'),
            new OA\Property(property: 'source_name', type: 'string', maxLength: 255, nullable: true, example: 'Nhà cung cấp Hòa Nam'),
            new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Điều chỉnh số lượng dự kiến'),
            new OA\Property(property: 'lines', type: 'array', minItems: 1, nullable: true, items: new OA\Items(
                required: ['product_id', 'qty_planned'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true, example: '6d8e3f4c-1a5b-4c7d-9e2f-3a4b5c6d7e8f'),
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '4b6f1c2a-8e3d-4f5a-9b2c-1d3e4f5a6b7c'),
                    new OA\Property(property: 'import_batch_id', type: 'string', format: 'uuid', nullable: true, example: '5c7d2e3b-9f4e-4a6b-8c3d-2e4f5a6b7c8d'),
                    new OA\Property(property: 'qty_planned', type: 'integer', minimum: 1, example: 15),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Ghi chú dòng nhập'),
                ]
            )),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Cập nhật phiếu nhập kho thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.create'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ/dòng'),
        new OA\Response(response: 409, description: 'State conflict hoặc cố thay đổi cấu trúc dòng đã có scan evidence'),
        new OA\Response(response: 412, description: 'If-Match không khớp version hiện tại'),
        new OA\Response(response: 422, description: 'Expected nhỏ hơn scanned_qty hoặc dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/cancel',
    operationId: 'stock_in_cancel',
    summary: 'Cancel phiếu DRAFT/SCANNING',
    description: 'Cancel là trạng thái kết thúc và stock-neutral. Bắt buộc If-Match và lý do.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000, example: 'Nhập sai đơn hàng')]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Cancel phiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.cancel hoặc inbound.create (route nhận cả hai — Annex C khai inbound.create, baseline seed cấp inbound.cancel riêng cho WAREHOUSE_APPROVER/KEEPER; xem docs/DR_HN_20260817_FLOW02_OPEN_DECISIONS.md)'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Chứng từ không còn DRAFT/SCANNING'),
        new OA\Response(response: 412, description: 'If-Match không khớp'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/scan',
    operationId: 'stock_in_scan',
    summary: 'Quét hàng cho phiếu DRAFT/SCANNING (stock-neutral)',
    description: 'Bắt buộc If-Match. Scan hợp lệ chuyển/giữ phiếu ở SCANNING, tăng scanned_qty nhưng không tạo stock movement và không đổi inventory balance. Scan vượt Expected bị từ chối. '
        .'Idempotency-Key là TÙY CHỌN (scan stock-neutral, Annex C không khai bắt buộc nên thiếu header KHÔNG trả 428) — xem DEC-SCAN-IDEMP-001. '
        .'Khi client gửi Idempotency-Key: mỗi hành động quét sinh một key và mọi lần gửi lại của đúng hành động đó dùng lại đúng key. '
        .'Cùng key + cùng nội dung scan (line_id, qty, code_value, item_id, container_id) trả lại đúng response cũ kèm data.replayed=true và header Idempotent-Replay: true, scanned_qty KHÔNG tăng lần hai kể cả khi If-Match là version mới sau khi client re-fetch. '
        .'Cùng key + khác nội dung scan trả 409 IDEMPOTENCY_CONFLICT. Khác key + cùng nội dung là lần quét thật thứ hai và vẫn cộng dồn bình thường. '
        .'Fingerprint dedupe là tất định trên nội dung scan, không chứa version và không dùng cửa sổ thời gian; qty và quantity là alias của cùng một trường nên không tạo xung đột payload.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
        new OA\Parameter(
            name: 'Idempotency-Key',
            in: 'header',
            required: false,
            description: 'Tùy chọn (DEC-SCAN-IDEMP-001). Độ dài 8..100 ký tự sau khi trim; sai độ dài trả 422 VALIDATION_ERROR. Thiếu header thì giữ nguyên hành vi cũ, không trả 428.',
            schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)
        ),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['line_id', 'qty'],
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid', example: '6d8e3f4c-1a5b-4c7d-9e2f-3a4b5c6d7e8f'),
                new OA\Property(property: 'qty', type: 'integer', minimum: 1, example: 1),
                new OA\Property(property: 'code_value', type: 'string', nullable: true, example: 'HN1|SKU001|ITEM00001'),
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, example: '7e9f4a5d-2b6c-4d8e-9f3a-4b5c6d7e8f9a'),
                new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, example: '8f1a5b6e-3c7d-4e9f-8a4b-5c6d7e8f9a1b'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Scan hợp lệ; stock_effect=NONE. data.replayed=false là lần áp dụng thật, true là replay của một request đã chạy trước đó với cùng Idempotency-Key (header Idempotent-Replay phản chiếu cùng giá trị).',
            headers: [
                new OA\Header(header: 'ETag', description: 'Version hiện hành của phiếu sau khi scan.', schema: new OA\Schema(type: 'string')),
                new OA\Header(header: 'Idempotent-Replay', description: 'true khi response là replay theo Idempotency-Key.', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
            ]
        ),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.scan'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ/dòng'),
        new OA\Response(response: 409, description: 'STATE_CONFLICT (phiếu không còn DRAFT/SCANNING) | DUPLICATE_ITEM_IN_DOCUMENT | ITEM_ALREADY_RECEIVING | IDEMPOTENCY_CONFLICT (cùng Idempotency-Key nhưng khác nội dung scan) | CONCURRENCY_CONFLICT (cùng key đang được xử lý)'),
        new OA\Response(response: 412, description: 'If-Match không khớp'),
        new OA\Response(response: 422, description: 'Scan sai SKU/mã/BOX, vượt Expected, hoặc Idempotency-Key sai độ dài 8..100'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/unscan',
    operationId: 'stock_in_unscan',
    summary: 'Gỡ một dòng đã quét khỏi phiếu DRAFT/SCANNING (stock-neutral)',
    description: 'ERD bảng 28: inbound_scan_entries.status ACTIVE -> REMOVED để sửa mã quét sai trước Post; scanned_qty của line giảm tương ứng. Bắt buộc If-Match. Không tạo/không đảo stock movement. Sau Post phải dùng Reversal thay vì gỡ.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['inbound_scan_entry_id'],
            properties: [
                new OA\Property(property: 'inbound_scan_entry_id', type: 'string', format: 'uuid', example: '9a2b6c7f-4d8e-4f1a-9b5c-6d7e8f9a1b2c'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true, example: 'Quét nhầm mã, gỡ để quét lại'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Đã gỡ dòng quét; stock_effect=NONE'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.scan'),
        new OA\Response(response: 404, description: 'Không tìm thấy phiếu/dòng quét'),
        new OA\Response(response: 409, description: 'Phiếu không còn DRAFT/SCANNING hoặc dòng quét không còn ACTIVE'),
        new OA\Response(response: 412, description: 'If-Match không khớp version hiện tại'),
        new OA\Response(response: 422, description: 'Payload không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/post-receipt',
    operationId: 'stock_in_post_receipt',
    summary: 'Full Post Receipt SCANNING -> POSTED',
    description: 'Chỉ Post khi mọi dòng scanned_qty = qty_planned. Bắt buộc If-Match + Idempotency-Key. Đây là bước duy nhất của inbound làm tăng tồn; retry cùng key không được tăng tồn lần hai.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Full Post Receipt thành công, phiếu POSTED'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inbound.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'State/idempotency conflict'),
        new OA\Response(response: 412, description: 'If-Match không khớp'),
        new OA\Response(response: 422, description: 'Chưa scan đủ chính xác toàn bộ dòng'),
        new OA\Response(response: 428, description: 'Thiếu If-Match/điều kiện bắt buộc'),
    ]
)]
#[OA\Post(
    path: '/api/v1/inbound-documents/{id}/reverse',
    operationId: 'stock_in_reverse',
    summary: 'Reversal/Correction cho phiếu nhập POSTED',
    description: 'Không sửa/xóa chứng từ POSTED. Sai giao dịch được xử lý bằng reversal aggregate liên kết movement gốc. Bắt buộc If-Match + Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Reversal command thành công; chứng từ gốc vẫn POSTED'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có permission inventory.reversal.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ/movement'),
        new OA\Response(response: 409, description: 'State/idempotency/dependency conflict'),
        new OA\Response(response: 412, description: 'If-Match không khớp'),
        new OA\Response(response: 422, description: 'Reversal payload hoặc quantity không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu If-Match/điều kiện bắt buộc'),
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
}
