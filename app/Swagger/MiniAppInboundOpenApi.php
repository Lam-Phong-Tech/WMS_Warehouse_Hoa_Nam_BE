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
    private const TAG = '22. Mini App';

    #[OA\Post(
        path: '/api/v1/mini-app/inbound/resolve-code',
        operationId: 'mini_app_inbound_resolve_code',
        x: ['permission' => 'inbound.scan'],
        summary: 'Mini App - resolve QR/barcode cho local staging',
        description: 'Dùng khi camera đang điền các dòng local trước khi tạo phiếu WMS. Endpoint chỉ resolve SKU/item identity, không tạo document, không tạo scan evidence, không tạo movement và không đổi tồn. Composite QR HN1|SKU=<sku_code>|ITEM=<item_unique> hỗ trợ NEW_ITEM_CANDIDATE. Mã HỘP linh kiện BOX-<sku_code>-<số hộp> được nhận diện riêng: trả item_type=BOX, requires_quantity=true, box_code/box_number và SKU COMPONENT đọc thẳng từ mã (chưa có thì resolution_status=NEW_SKU_CANDIDATE) để FE hiện ô nhập số lượng đếm tay. Mã còn lại trả item_type=ITEM, requires_quantity=false (mỗi mã là một hiện vật, +1).',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'raw_code'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Kho nhận hàng; phải thuộc warehouse scope của user.'),
                    new OA\Property(property: 'raw_code', type: 'string', minLength: 1, maxLength: 512, description: 'QR item composite, barcode legacy, hoặc mã hộp linh kiện BOX-<sku_code>-<số hộp>.', example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resolve thành công; stock_effect=NONE, scan_evidence_created=false, wms_document_created=false. Kèm item_type (ITEM|BOX), requires_quantity, box_code, box_number, is_serial_tracked và requires_new_sku_type — requires_new_sku_type=true nghĩa là sku_code chưa từng đăng ký: Mini App phải hỏi "Sản phẩm hay Linh kiện?" rồi gửi kèm new_sku_type ở bước record.'),
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
        description: 'Dùng khi bấm XÁC NHẬN GHI NHẬN. Server validate toàn batch, chống duplicate, group theo SKU, tạo receipt + lines + toàn bộ scan evidence trong một DB transaction. Lỗi bất kỳ item nào sẽ rollback toàn bộ. Kết quả canonical status=SCANNING, mini_app_status=WAITING_APPROVAL, ready_for_post=true; chưa Post Receipt và chưa tăng tồn. Body chấp nhận `warehouse_id` như alias của `dst_warehouse_id` (cùng tên field với bước resolve-code); gửi cả hai mà khác nhau -> 422 WAREHOUSE_ID_CONFLICT. Mỗi phần tử items[] có thể gửi nguyên row local từ resolve-code: raw_code là nguồn sự thật, các field echo (sku_id, sku_code, item_id, item_unique, resolution_status, ...) được nhận nhưng không dùng để quyết định. Mã hộp BOX-<sku_code>-<số hộp> ghi nhận theo `quantity` đếm tay (bắt buộc, 422 BOX_QUANTITY_REQUIRED nếu thiếu) vào dòng SKU COMPONENT + container BOX; mã item chỉ cho quantity=1.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Giữ nguyên key khi retry cùng một lần XÁC NHẬN GHI NHẬN.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'items'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Nhập hàng sáng 23/08'),
                    new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid', description: 'Kho nhận. Bắt buộc trừ khi gửi alias warehouse_id.'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true, description: 'Alias của dst_warehouse_id (đúng tên field của bước resolve-code). Gửi cả hai mà khác nhau -> 422 WAREHOUSE_ID_CONFLICT.'),
                    new OA\Property(property: 'expected_total_qty', type: 'integer', minimum: 1, maximum: 1000, nullable: true, description: 'Chỉ để tương thích client cũ; backend nhận đúng số đã quét thực tế.', example: 2),
                    new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'items', type: 'array', minItems: 1, maxItems: 1000, items: new OA\Items(
                        required: ['raw_code'],
                        description: 'Row local. Ngoài các field dưới đây còn nhận nguyên các field echo của resolve-code (sku_id, sku_code, sku_name, sku_type, item_id, item_unique, item_status, serial_number, payload_format, payload_version, normalized_code_value, resolution_status, box_code, requires_quantity) — nhận để FE gửi thẳng row, không dùng để quyết định. Field ngoài whitelist -> 422 VALIDATION_ERROR kèm errors.unexpected_fields.',
                        properties: [
                            new OA\Property(property: 'raw_code', type: 'string', minLength: 1, maxLength: 512, description: 'QR item composite, barcode legacy, hoặc mã hộp BOX-<sku_code>-<số hộp>. Đây là nguồn sự thật của dòng.', example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                            new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
                            new OA\Property(property: 'item_type', type: 'string', enum: ['ITEM', 'BOX'], nullable: true, description: 'Chỉ để đối chiếu; backend tự nhận diện từ raw_code. Lệch -> 422 ITEM_TYPE_MISMATCH.'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 100000, nullable: true, description: 'Số lượng đếm tay trong hộp — bắt buộc với item_type=BOX. Với mã item chỉ được là 1 (hoặc bỏ trống).', example: 50),
                            new OA\Property(property: 'box_number', type: 'string', nullable: true, description: 'Số hộp; đối chiếu theo giá trị số với đuôi của mã hộp. Lệch -> 422 BOX_NUMBER_MISMATCH.', example: '0001'),
                            new OA\Property(property: 'new_sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true, description: 'Trả lời của người quét khi resolve-code báo requires_new_sku_type=true. COMPONENT chọn từ MÃ ITEM = linh kiện dán mã từng cái -> SKU mới có is_serial_tracked=true (quét +1, không cần hộp). Bỏ trống = tạo PRODUCT như trước. Không ảnh hưởng SKU đã tồn tại.'),
                        ],
                        type: 'object'
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo phiếu + lines + scan evidence thành công; status=SCANNING, mini_app_status=WAITING_APPROVAL, ready_for_post=true, stock_effect=NONE. items[] trả kèm item_type, quantity, box_code, box_number. Nếu batch có SKU vừa được sinh ra khi quét, phiếu VẪN được ghi nhận nhưng response trả product_info_complete=false, skus_pending_info[] (sku_code + missing_fields), can_post=false, post_blocked_reason=INBOUND_SKU_INFO_INCOMPLETE: người ở web phải bổ sung tên + đơn vị tính rồi mới Ghi nhận nhập kho được.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu inbound.create/inbound.scan hoặc kho ngoài data scope.'),
            new OA\Response(response: 409, description: 'Duplicate item/hộp trong batch, idempotency/concurrency, item đang receiving, hộp đang thuộc phiếu nhập khác; toàn batch rollback.'),
            new OA\Response(response: 422, description: 'VALIDATION_ERROR (field ngoài whitelist), WAREHOUSE_ID_CONFLICT, ITEM_TYPE_MISMATCH, SKU_CODE_MISMATCH, BOX_NUMBER_MISMATCH, BOX_QUANTITY_REQUIRED, ITEM_QUANTITY_MUST_BE_ONE, BOX_SKU_NOT_COMPONENT, QR/SKU/item không hợp lệ; toàn batch rollback.'),
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
            new OA\Response(response: 200, description: 'Danh sách phân trang. Mỗi document có expected_total_qty, scanned_total_qty, remaining_qty, progress_percent, ready_for_post, mini_app_status, product_info_complete, pending_product_info_count, skus_pending_info[], can_post, post_blocked_reason. lines[] kèm product_code/product_name/product_type/product_unit + product_info_complete/product_missing_info.'),
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
        description: 'Trả document + lines + status history và các field progress dùng cho review/approval: expected_total_qty, scanned_total_qty, remaining_qty, progress_percent, ready_for_post, mini_app_status. Kèm can_post/post_blocked_reason/product_info_complete/skus_pending_info[] để biết phiếu có Post được chưa, và mỗi line có product_code/product_name/product_type/product_unit + product_info_complete/product_missing_info.',
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
            new OA\Response(response: 422, description: 'Business rule không hợp lệ. Hay gặp nhất: INBOUND_SKU_INFO_INCOMPLETE — phiếu còn SKU sinh ra lúc quét chưa có tên thật/đơn vị tính (errors.skus[] liệt kê sku_code + missing_fields). Phiếu vẫn ở SCANNING; người ở web bổ sung thông tin rồi Ghi nhận nhập kho. Đọc can_post/post_blocked_reason ở danh sách/chi tiết để khoá nút duyệt trước, đừng để người quét bấm rồi mới báo lỗi.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match hoặc Idempotency-Key.'),
        ]
    )]
    public function postReceipt(): void {}
}
