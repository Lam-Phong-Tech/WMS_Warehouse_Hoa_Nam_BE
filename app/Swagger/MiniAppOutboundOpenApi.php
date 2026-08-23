<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Documentation-only contract for the Warehouse Mini App outbound adapter. */
final class MiniAppOutboundOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Get(
        path: '/api/v1/mini-app/outbound-documents',
        operationId: 'mini_app_outbound_documents_list',
        x: ['permission' => 'outbound.view'],
        summary: 'Mini App - danh sách phiếu xuất từ WMS',
        description: 'Không tạo phiếu xuất tại Mini App. Phiếu DRAFT được sinh từ Confirm toa/yêu cầu xuất canonical. Mỗi item có progress, Packing Label, ready_for_issue và mini_app_status.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])),
            new OA\Parameter(name: 'ready_for_issue', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Tìm doc_no/người nhận/địa chỉ/note.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách phân trang; mini_app_status=PICKING/LABELING/WAITING_LABEL_APPLY/READY_TO_ISSUE/COMPLETED/CANCELLED.')]
    )]
    public function listDocuments(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/outbound-documents/{id}',
        operationId: 'mini_app_outbound_documents_detail',
        x: ['permission' => 'outbound.view'],
        summary: 'Mini App - chi tiết phiếu xuất',
        description: 'Trả lines, item_matches, Packing Label, progress và trạng thái semantic. Backend WMS là source of truth.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Chi tiết phiếu xuất Mini App.')]
    )]
    public function showDocument(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/cancel',
        operationId: 'mini_app_outbound_documents_cancel',
        x: ['permission' => 'outbound.cancel'],
        summary: 'Mini App - hủy phiếu xuất trước Post',
        description: 'Chỉ DRAFT/SCANNING; stock-neutral. Sau POSTED phải dùng reversal canonical.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)])),
        responses: [new OA\Response(response: 200, description: 'CANCELLED; chưa đổi tồn.')]
    )]
    public function cancelDocument(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/scan',
        operationId: 'mini_app_outbound_scan',
        x: ['permission' => 'outbound.scan'],
        summary: 'Mini App - quét QR/barcode và match PRODUCT vào phiếu xuất',
        description: 'Gửi raw code; backend parse SKU/item authoritative và tự match line khi QR composite. Scan stock-neutral. Full match tự tạo/đảm bảo đúng 01 Packing Label DRAFT cho cả phiếu.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true, description: 'Có thể bỏ khi QR composite chứa SKU.'),
                new OA\Property(property: 'quantity', type: 'integer', minimum: 1, default: 1),
                new OA\Property(property: 'code_value', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Match thành công; stock_effect=NONE; full match có label_snapshot_id/label_status.'),
            new OA\Response(response: 409, description: 'Duplicate hoặc item đang bị phiếu khác giữ; lỗi có conflicting_document_* khi xác định được.'),
            new OA\Response(response: 422, description: 'Sai SKU/mã/item/quantity hoặc thiếu tồn tại thời điểm scan.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match.'),
        ]
    )]
    public function scan(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/unmatch',
        operationId: 'mini_app_outbound_unmatch',
        x: ['permission' => 'outbound.scan'],
        summary: 'Mini App - gỡ item quét nhầm trước Post',
        description: 'Match ACTIVE -> REMOVED, scanned_qty giảm; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['outbound_item_match_id'], properties: [
            new OA\Property(property: 'outbound_item_match_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'Đã gỡ match; stock_effect=NONE.')]
    )]
    public function unmatch(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/outbound-documents/{id}/scan-entries',
        operationId: 'mini_app_outbound_scan_entries',
        x: ['permission' => 'outbound.view'],
        summary: 'Mini App - danh sách item/mã đã quét của phiếu xuất',
        description: 'Dùng cho màn Kiểm tra; trả SKU/item/serial/raw_code/scan_source nếu evidence có.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'REMOVED', 'ALL'], default: 'ACTIVE')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách scan/match evidence.')]
    )]
    public function scanEntries(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-label',
        operationId: 'mini_app_outbound_packing_label',
        x: ['permission' => 'outbound.view'],
        summary: 'Mini App - lấy Packing Label hiện tại của phiếu xuất',
        description: 'Packing Label chỉ xuất hiện sau full match; một snapshot cho cả phiếu/lô.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Packing Label hiện tại.'), new OA\Response(response: 404, description: 'Chưa full match/chưa có label.')]
    )]
    public function packingLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/preview',
        operationId: 'mini_app_outbound_packing_label_preview',
        x: ['permission' => 'packing_label.preview|label.print'],
        summary: 'Mini App - preview Packing Label',
        description: 'DRAFT/PENDING_PRINT -> PREVIEWED; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: self::labelVersionParameters,
        responses: [new OA\Response(response: 200, description: 'PREVIEWED; stock_effect=NONE.')]
    )]
    public function previewLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/ready',
        operationId: 'mini_app_outbound_packing_label_ready',
        x: ['permission' => 'packing_label.ready|label.print'],
        summary: 'Mini App - đóng băng Packing Label sẵn sàng in',
        description: 'PREVIEWED -> READY_TO_PRINT; bắt buộc If-Match + Idempotency-Key; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: self::labelMutationParameters,
        responses: [new OA\Response(response: 200, description: 'READY_TO_PRINT; stock_effect=NONE.')]
    )]
    public function readyLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/print',
        operationId: 'mini_app_outbound_packing_label_print',
        x: ['permission' => 'packing_label.print|label.print'],
        summary: 'Mini App - in Packing Label',
        description: 'Chỉ in snapshot READY_TO_PRINT/PRINT_FAILED theo canonical print service; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: self::labelMutationParameters,
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['printer_config_id'], properties: [new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid')])) ,
        responses: [new OA\Response(response: 200, description: 'PRINTED hoặc trạng thái lỗi in theo canonical contract.')]
    )]
    public function printLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/reprint',
        operationId: 'mini_app_outbound_packing_label_reprint',
        x: ['permission' => 'packing_label.reprint|label.reprint'],
        summary: 'Mini App - in lại Packing Label',
        description: 'Dùng lại đúng immutable snapshot đã PRINTED; stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: self::labelMutationParameters,
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['printer_config_id'], properties: [new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid')])),
        responses: [new OA\Response(response: 200, description: 'In lại thành công; stock_effect=NONE.')]
    )]
    public function reprintLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/apply',
        operationId: 'mini_app_outbound_packing_label_apply',
        x: ['permission' => 'label.print'],
        summary: 'Mini App - xác nhận đã dán Packing Label lên lô hàng',
        description: 'Chỉ sau PRINTED. Đây là gate bắt buộc trước Post Issue nhưng vẫn stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: self::labelVersionParameters,
        responses: [new OA\Response(response: 200, description: 'applied=true; stock_effect=NONE.')]
    )]
    public function applyLabel(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents/{id}/post-issue',
        operationId: 'mini_app_outbound_post_issue',
        x: ['permission' => 'outbound.post'],
        summary: 'Mini App - hoàn tất xuất kho',
        description: 'Chỉ khi full scan + Packing Label PRINTED + đã xác nhận dán. Đây là action DUY NHẤT trong Mini App outbound làm giảm tồn; atomic, full-post, idempotent.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'POSTED; tồn giảm đúng một lần; item -> ISSUED; replay trả Idempotent-Replay=true.'),
            new OA\Response(response: 409, description: 'State/idempotency/label snapshot conflict.'),
            new OA\Response(response: 412, description: 'STALE_VERSION.'),
            new OA\Response(response: 422, description: 'FULL_POST_REQUIRED/LABEL_NOT_PRINTED/PACKING_LABEL_NOT_APPLIED/INSUFFICIENT_STOCK.'),
            new OA\Response(response: 428, description: 'Thiếu If-Match hoặc Idempotency-Key.'),
        ]
    )]
    public function postIssue(): void {}

    private const labelVersionParameters = [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'labelId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ];

    private const labelMutationParameters = [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'labelId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 100)),
    ];
}
