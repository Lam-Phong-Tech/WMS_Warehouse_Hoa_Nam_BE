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

    // Các stub private bên dưới KHÔNG được gọi ở đâu — mỗi hàm chỉ neo đúng MỘT
    // annotation #[OA\...] để mô tả contract thật (dead code ở controller này,
    // route thật đã chuyển sang CurrentBaselineDocumentController/
    // CurrentBaselineStockFlowController/BaM1InboundDocumentController). Trước đây
    // cả 8 annotation này xếp chồng ở class-level; swagger-php không merge được
    // nhiều attribute CÙNG LOẠI (vd 5 lần #[OA\Post]) tại cùng một context, khiến
    // l5-swagger:generate crash — và vì .env có L5_SWAGGER_GENERATE_ALWAYS=true nên
    // Swagger UI ('/api/documentation') nhận 404 "Failed to load API definition"
    // trên MỌI request. Tách mỗi annotation ra một method riêng để mỗi attribute
    // có context riêng, không còn xung đột merge, mà vẫn giữ nguyên toàn bộ nội
    // dung/operationId (SwaggerRouteSyncTest pin operationId 'stock_in_cancel').

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
            new OA\Parameter(name: 'pending_product_info', in: 'query', required: false, description: 'true = chỉ phiếu còn mở (DRAFT/SCANNING) đang có SKU sinh ra lúc quét chưa đủ thông tin (thiếu tên thật hoặc đơn vị tính); false = phần còn lại. Không gửi = không lọc.', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort_order', in: 'query', required: false, description: 'Sắp xếp theo thời điểm tạo/sửa gần nhất (COALESCE(updated_at, created_at)): desc = Mới nhất trước, asc = Cũ nhất trước. Không gửi = giữ thứ tự mặc định cũ (created_at desc). Giá trị khác trả 422.', schema: new OA\Schema(type: 'string', enum: ['desc', 'asc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách phiếu nhập kho. Mỗi phiếu kèm pending_product_info (bool) + pending_product_info_count để hiện dấu "Chờ bổ sung thông tin" ngay trên danh sách.'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có permission inbound.view'),
        ]
    )]
    private function docContractList(): void {}

    // docContractCreate()/docContractUpdate() đã bị GỠ (không phải comment ra —
    // xóa hẳn): POST/PATCH /api/v1/inbound-documents{,/{id}} đã có annotation
    // chính xác hơn ngay trên handler THẬT đang chạy — BaM1InboundDocumentController
    // ::store()/::update() (Flow06AlignmentServiceProvider đăng ký route này SAU,
    // ghi đè route cùng path+method). Giữ annotation ở cả hai nơi cho cùng
    // path+method khiến swagger-php ném "Unable to merge @OA\Post()" y hệt lỗi đã
    // sửa ở trên — đây chính là nguyên nhân THẬT SỰ (không phải multi-Post trùng
    // loại ở class-level như đoán ban đầu).

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
    private function docContractDetail(): void {}

    // docContractCancel() đã bị GỠ: '/api/v1/inbound-documents/{id}/cancel' giờ có
    // annotation chính xác hơn ngay trên handler thật đang chạy —
    // CurrentBaselineDocumentController::cancelInbound() (route KHÔNG bị
    // Flow06AlignmentServiceProvider ghi đè). Giữ cả hai gây trùng path+method.

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
    private function docContractScan(): void {}

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
    private function docContractUnscan(): void {}

    // docContractPostReceipt() đã bị GỠ: '/api/v1/inbound-documents/{id}/post-receipt'
    // giờ có annotation trên chính handler thật —
    // CurrentBaselineStockFlowController::postReceipt() (không bị override).

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
    private function docContractReverse(): void {}
}
