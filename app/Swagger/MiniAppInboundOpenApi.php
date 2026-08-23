<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * OpenAPI contract for the Warehouse Mini App inbound flow.
 *
 * Runtime routes live under Modules/Warehouse/routes/mini_app.php. This class is
 * documentation-only and is intentionally placed under app/Swagger because that
 * directory is already scanned by L5 Swagger.
 */
final class MiniAppInboundOpenApi
{
    private const TAG = '09. Stock In (Inbound)';

    #[OA\Post(
        path: '/api/v1/mini-app/inbound/resolve-code',
        operationId: 'mini_app_inbound_resolve_code',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - resolve QR/barcode cho local staging',
        description: 'Dùng khi camera đang điền các dòng local trước khi tạo phiếu WMS. Endpoint chỉ resolve SKU/item identity, không tạo document, không tạo scan evidence, không tạo movement và không đổi tồn. Composite QR HN1|SKU=<sku_code>|ITEM=<item_unique> hỗ trợ NEW_ITEM_CANDIDATE.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'raw_code'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Kho nhận hàng; phải thuộc warehouse scope của user.'),
                    new OA\Property(property: 'raw_code', type: 'string', minLength: 1, maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resolve thành công; stock_effect=NONE, scan_evidence_created=false, wms_document_created=false.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc kho ngoài data scope.'),
            new OA\Response(response: 409, description: 'Identity bị chiếm hoặc item đang thuộc inbound khác.'),
            new OA\Response(response: 422, description: 'QR/SKU/item không hợp lệ hoặc item không receivable.'),
        ]
    )]
    public function resolveCode(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound/record',
        operationId: 'mini_app_inbound_record',
        x: ['permission' => 'inbound.create+inbound.scan'],
        summary: 'Mini App - atomic ghi nhận batch local thành phiếu WMS chờ duyệt',
        description: 'Dùng khi bấm XÁC NHẬN GHI NHẬN. Server validate toàn batch, chống duplicate, group theo SKU, tạo receipt + lines + toàn bộ scan evidence trong một DB transaction. Lỗi bất kỳ item nào sẽ rollback toàn bộ. Kết quả canonical status=SCANNING, mini_app_status=WAITING_APPROVAL, ready_for_post=true; chưa Post Receipt và chưa tăng tồn.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Giữ nguyên key khi retry cùng một lần XÁC NHẬN GHI NHẬN.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'dst_warehouse_id', 'expected_total_qty', 'items'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Nhập hàng sáng 23/08'),
                    new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'expected_total_qty', type: 'integer', minimum: 1, maximum: 1000, example: 2),
                    new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'items', type: 'array', minItems: 1, maxItems: 1000, items: new OA\Items(
                        required: ['raw_code'],
                        properties: [
                            new OA\Property(property: 'raw_code', type: 'string', minLength: 1, maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                            new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
                        ],
                        type: 'object'
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo phiếu + lines + scan evidence thành công; status=SCANNING, mini_app_status=WAITING_APPROVAL, ready_for_post=true, stock_effect=NONE.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.create/inbound.scan hoặc kho ngoài data scope.'),
            new OA\Response(response: 409, description: 'Duplicate/idempotency/concurrency/item đang receiving; toàn batch rollback.'),
            new OA\Response(response: 422, description: 'Sai expected count/QR/SKU/item/business rule; toàn batch rollback.'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key.'),
        ]
    )]
    public function record(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/inbound-documents',
        operationId: 'mini_app_inbound_documents_list',
        x: ['permission' => 'inbound.view'],
        summary: 'Mini App - danh sách phiếu nhập',
        description: 'Server-side source of truth cho tab Duyệt phiếu. status=WAITING_APPROVAL được backend translate thành canonical SCANNING + mọi line scanned_qty=qty_planned. Có thể dùng ready_for_post=true tương đương.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'WAITING_APPROVAL là semantic status của Mini App, không phải DB stock_document status.', schema: new OA\Schema(type: 'string', enum: ['WAITING_APPROVAL', 'DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])),
            new OA\Parameter(name: 'ready_for_post', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'keyword', in: 'query', required: false, description: 'Tìm theo doc_no, source_name, note.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách phân trang. Mỗi document có expected_total_qty, scanned_total_qty, remaining_qty, progress_percent, ready_for_post, mini_app_status.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.view hoặc ngoài warehouse scope.'),
            new OA\Response(response: 422, description: 'status/ready_for_post không hợp lệ.'),
        ]
    )]
    public function listDocuments(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents',
        operationId: 'mini_app_inbound_documents_create',
        x: ['permission' => 'inbound.create'],
        summary: 'Mini App - tạo phiếu nhập WMS có planned SKU lines',
        description: 'Adapter create canonical receipt. Khuyến nghị luồng local batch dùng /mini-app/inbound/record. Endpoint này vẫn hỗ trợ full lines[] hoặc shortcut một SKU bằng expected_qty + product_id/sku_id/sku_code.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Tùy chọn cho create; cùng key + cùng payload replay cùng kết quả.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['dst_warehouse_id'],
                properties: [
                    new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'source_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'source_reference', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'expected_qty', type: 'integer', minimum: 1, nullable: true, description: 'Shortcut một SKU; dùng khi không gửi lines[].'),
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true, description: 'Shortcut alias của SKU id.'),
                    new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'lines', type: 'array', minItems: 1, nullable: true, items: new OA\Items(
                        required: ['product_id', 'qty_planned'],
                        properties: [
                            new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'import_batch_id', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'qty_planned', type: 'integer', minimum: 1),
                            new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
                        ],
                        type: 'object'
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo receipt DRAFT thành công; ETag chứa version.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.create hoặc ngoài warehouse scope.'),
            new OA\Response(response: 409, description: 'Idempotency/business conflict.'),
            new OA\Response(response: 422, description: 'SKU/warehouse/payload không hợp lệ.'),
        ]
    )]
    public function createDocument(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/inbound-documents/{id}',
        operationId: 'mini_app_inbound_documents_detail',
        x: ['permission' => 'inbound.view'],
        summary: 'Mini App - chi tiết phiếu nhập',
        description: 'Trả document + lines + status history và các field progress dùng cho review/approval: expected_total_qty, scanned_total_qty, remaining_qty, progress_percent, ready_for_post, mini_app_status.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết phiếu nhập Mini App.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.view hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu nhập.'),
        ]
    )]
    public function showDocument(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/cancel',
        operationId: 'mini_app_inbound_documents_cancel',
        x: ['permission' => 'inbound.cancel|inbound.create'],
        summary: 'Mini App - hủy phiếu nhập DRAFT/SCANNING',
        description: 'Hủy stock-neutral trước Post Receipt. Sau POSTED phải dùng reversal, không cancel.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, description: 'Document version hiện tại.', schema: new OA\Schema(type: 'integer', minimum: 0)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'status=CANCELLED; ETag/version tăng 1.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu permission hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu.'),
            new OA\Response(response: 409, description: 'Phiếu không còn DRAFT/SCANNING.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 422, description: 'Payload không hợp lệ.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match.'),
        ]
    )]
    public function cancelDocument(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/boxes',
        operationId: 'mini_app_inbound_documents_register_box',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - đăng ký BOX cho dòng COMPONENT',
        description: 'Chỉ áp dụng SKU COMPONENT; stock-neutral. PRODUCT local-staging không cần BOX để record.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['line_id', 'box_code'],
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'box_code', type: 'string', maxLength: 100, example: 'BOX-000123'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'BOX đã tồn tại và hợp lệ.'),
            new OA\Response(response: 201, description: 'Tạo BOX mới thành công.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy document/line.'),
            new OA\Response(response: 409, description: 'BOX không receivable/đang dùng bởi phiếu khác.'),
            new OA\Response(response: 422, description: 'Không phải COMPONENT hoặc box_code conflict.'),
        ]
    )]
    public function registerBox(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/scan',
        operationId: 'mini_app_inbound_documents_scan',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - ghi một scan evidence vào phiếu nhập',
        description: 'Stock-neutral. Hỗ trợ code_value raw QR, tự match line theo SKU embedded khi không truyền line_id. scan_source được lưu vào inbound evidence. Idempotency-Key là optional cho scan.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, description: 'Document version hiện tại; mỗi scan thành công tăng version 1.', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true, description: 'Bắt buộc khi không có code_value; nếu có QR composite server có thể tự match line.'),
                new OA\Property(property: 'quantity', type: 'integer', minimum: 1, nullable: true, description: 'Alias với qty; phải có một trong hai.'),
                new OA\Property(property: 'qty', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'code_value', type: 'string', minLength: 1, maxLength: 512, nullable: true),
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Scan thành công; stock_effect=NONE, movement_created=false, ETag/version mới, inbound_scan_entry_id và progress.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy document/line.'),
            new OA\Response(response: 409, description: 'Duplicate/state/idempotency conflict.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 422, description: 'QR/SKU/item/quantity/BOX rule không hợp lệ.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match.'),
        ]
    )]
    public function scan(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/unscan',
        operationId: 'mini_app_inbound_documents_unscan',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - gỡ scan evidence trước Post Receipt',
        description: 'Chuyển inbound_scan_entry ACTIVE -> REMOVED và giảm scanned_qty tương ứng; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['inbound_scan_entry_id'],
            properties: [
                new OA\Property(property: 'inbound_scan_entry_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Entry REMOVED, scanned_qty giảm, stock_effect=NONE, ETag/version mới.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy document/entry.'),
            new OA\Response(response: 409, description: 'Entry không ACTIVE hoặc document không mutable.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 422, description: 'Payload không hợp lệ.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match.'),
        ]
    )]
    public function unscan(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/inbound-documents/{id}/scan-entries',
        operationId: 'mini_app_inbound_documents_scan_entries',
        x: ['permission' => 'inbound.view'],
        summary: 'Mini App - danh sách mã đã quét của phiếu nhập',
        description: 'Dùng cho màn Kiểm tra trước duyệt. Trả raw_code, SKU, tên SKU, item/serial hoặc candidate_item_unique, quantity, scan_source, status, created_at.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'REMOVED', 'ALL'], default: 'ACTIVE')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách scan evidence phân trang.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.view hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu nhập.'),
            new OA\Response(response: 422, description: 'status không hợp lệ.'),
        ]
    )]
    public function scanEntries(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/cartons/{containerId}/complete-scan',
        operationId: 'mini_app_inbound_carton_complete_scan',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - ghi nhận đã quét xong carton',
        description: 'Audit-only milestone cho carton. Không đổi container state, không bump document version, stock_effect=NONE, movement_created=false.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'containerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit milestone đã ghi; stock_effect=NONE.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy document/carton.'),
            new OA\Response(response: 409, description: 'Carton/document state conflict.'),
            new OA\Response(response: 422, description: 'Carton không hợp lệ cho phiếu.'),
        ]
    )]
    public function completeCarton(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound-documents/{id}/post-receipt',
        operationId: 'mini_app_inbound_documents_post_receipt',
        x: ['permission' => 'inbound.post'],
        summary: 'Mini App - phê duyệt và Post Receipt',
        description: 'Đây là stock mutation cuối của luồng Mini App. Chỉ SCANNING full-scan mới được Post. Sau thành công document=POSTED và tồn kho mới tăng. UI có thể hiển thị Đã duyệt.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, description: 'Version mới nhất của document.', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Bắt buộc cho Post Receipt; retry cùng key replay kết quả cũ.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Post Receipt thành công; status=POSTED, inventory tăng, data.replayed cho retry idempotent, ETag/version mới.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.post hoặc ngoài warehouse scope.'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu.'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT/phiếu chưa full-scan/idempotency conflict.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 422, description: 'Business rule không hợp lệ.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match hoặc Idempotency-Key.'),
        ]
    )]
    public function postReceipt(): void {}
}
