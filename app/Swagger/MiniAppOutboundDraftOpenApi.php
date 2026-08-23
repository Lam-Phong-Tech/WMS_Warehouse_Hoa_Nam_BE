<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Documentation-only contract for direct Mini App outbound DRAFT creation. */
final class MiniAppOutboundDraftOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Post(
        path: '/api/v1/mini-app/outbound-documents',
        operationId: 'mini_app_outbound_documents_create',
        x: ['permission' => 'outbound.request.manual_create|outbound.import'],
        summary: 'Mini App - tạo phiếu xuất DRAFT trực tiếp',
        description: 'Mini App không cần quay lại Web WMS để tạo phiếu nháp. Server atomically tạo source import nội bộ + outbound request CONSUMED + request lines + stock_document issue DRAFT + document lines. Duplicate SKU lines được group. Bước này stock-neutral; chỉ Post Issue mới giảm tồn.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                description: 'Giữ nguyên key khi retry cùng một lần tạo phiếu.',
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'recipient_name', 'expected_total_qty', 'lines'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Kho nguồn ACTIVE thuộc data scope của user.'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, nullable: true, example: 'Xuất hàng chiều 23/08'),
                    new OA\Property(property: 'request_no', type: 'string', maxLength: 80, nullable: true, description: 'Bỏ trống để server tự sinh.', example: 'MA-OUT-20260823-001'),
                    new OA\Property(property: 'request_date', type: 'string', format: 'date', nullable: true, example: '2026-08-23'),
                    new OA\Property(property: 'recipient_name', type: 'string', maxLength: 200, example: 'Đại lý Hòa Nam Đà Nẵng'),
                    new OA\Property(property: 'recipient_address', type: 'string', maxLength: 1000, nullable: true, example: 'Hải Châu, Đà Nẵng'),
                    new OA\Property(property: 'recipient_contact_phone', type: 'string', maxLength: 50, nullable: true, example: '0912345678'),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'expected_total_qty', type: 'integer', minimum: 1, maximum: 1000, example: 2),
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        minItems: 1,
                        maxItems: 1000,
                        items: new OA\Items(
                            required: ['required_qty'],
                            properties: [
                                new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true, description: 'Gửi sku_id hoặc sku_code.'),
                                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, nullable: true, example: 'HN-PRD-SEED-001'),
                                new OA\Property(property: 'required_qty', type: 'integer', minimum: 1, maximum: 1000, example: 2),
                                new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo canonical issue DRAFT thành công. Trả document_id/doc_no/outbound_request_id/version/progress/lines; stock_effect=NONE.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu quyền tạo thủ công hoặc warehouse nằm ngoài data scope.'),
            new OA\Response(response: 409, description: 'Idempotency conflict hoặc request_no đã tồn tại.'),
            new OA\Response(response: 422, description: 'Kho/SKU/PRODUCT/quantity/recipient không hợp lệ hoặc tổng line lệch expected_total_qty.'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key.'),
        ]
    )]
    public function createDocument(): void {}
}
