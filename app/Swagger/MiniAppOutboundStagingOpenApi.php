<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Documentation-only contract for scan-first Mini App outbound staging. */
final class MiniAppOutboundStagingOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Post(
        path: '/api/v1/mini-app/outbound/resolve-code',
        operationId: 'mini_app_outbound_resolve_code',
        x: ['permission' => 'outbound.scan'],
        summary: 'Mini App - resolve mã xuất trước khi tạo phiếu',
        description: 'Read-only preflight cho camera/local staging. Resolve QR/barcode thành SKU + tên SP + item, kiểm tra PRODUCT/IN_STOCK/reservation/available_qty. Không tạo outbound request, phiếu, match, scan evidence hoặc stock movement.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'raw_code'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Trả SKU/item + eligible_for_outbound/eligibility_code; stock_effect=NONE, wms_document_created=false.'),
            new OA\Response(response: 403, description: 'Thiếu quyền hoặc warehouse ngoài data scope.'),
            new OA\Response(response: 422, description: 'QR/SKU/item/kho không resolve được hoặc không đúng PRODUCT.'),
        ]
    )]
    public function resolveCode(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/outbound/record',
        operationId: 'mini_app_outbound_record',
        x: ['permission' => 'outbound.request.manual_create|outbound.import + outbound.scan'],
        summary: 'Mini App - xác nhận batch N/N và tạo phiếu xuất từ các mã đã quét',
        description: 'Sau review local, client gửi toàn bộ raw scan. Server re-resolve authoritative, chống duplicate/reservation, kiểm aggregate available_qty, tự group SKU, atomically tạo canonical source chain + issue document rồi replay mọi scan qua canonical outbound handler. Full scan tự tạo Packing Label DRAFT. Toàn bộ bước này stock-neutral; lỗi bất kỳ rollback toàn bộ.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                description: 'Giữ nguyên key khi retry cùng một lần Xác nhận ghi nhận.',
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'warehouse_id', 'recipient_name', 'expected_total_qty', 'items'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Xuất hàng chiều 23/08'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'request_no', type: 'string', maxLength: 80, nullable: true, description: 'Bỏ trống để BE tự sinh.'),
                    new OA\Property(property: 'request_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'recipient_name', type: 'string', maxLength: 200, example: 'Đại lý A'),
                    new OA\Property(property: 'recipient_address', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'recipient_contact_phone', type: 'string', maxLength: 50, nullable: true, example: '0912345678'),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'expected_total_qty', type: 'integer', minimum: 1, maximum: 1000, example: 3),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        minItems: 1,
                        maxItems: 1000,
                        items: new OA\Items(
                            required: ['raw_code'],
                            properties: [
                                new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                                new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo phiếu + match đủ N/N. status=SCANNING, mini_app_status=LABELING, full_scan=true, Packing Label DRAFT, stock_effect=NONE.'),
            new OA\Response(response: 409, description: 'Duplicate item, item bị phiếu khác giữ hoặc atomic handoff không hoàn tất.'),
            new OA\Response(response: 422, description: 'Count/SKU/item/tồn/kho/phone không hợp lệ.'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key.'),
        ]
    )]
    public function record(): void {}
}
