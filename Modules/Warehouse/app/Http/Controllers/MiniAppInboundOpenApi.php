<?php

namespace Modules\Warehouse\Http\Controllers;

use OpenApi\Attributes as OA;

/**
 * OpenAPI-only contract for the Mini App local-staging endpoints.
 * Runtime implementation lives in MiniAppInboundStagingController.
 */
final class MiniAppInboundOpenApi
{
    #[OA\Post(
        path: '/api/v1/mini-app/inbound/resolve-code',
        operationId: 'mini_app_inbound_resolve_code',
        summary: 'Resolve QR/barcode cho local staging, không tạo phiếu/evidence/tồn',
        description: 'Dùng khi camera đang điền các dòng local. Hỗ trợ composite QR có SKU + item unique kể cả NEW_ITEM_CANDIDATE. Endpoint read-only, stock_effect=NONE.',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock In (Inbound)'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['warehouse_id', 'raw_code'],
            properties: [
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Resolve thành công; không tạo WMS document/scan evidence'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Thiếu inbound.scan hoặc kho ngoài data scope'),
            new OA\Response(response: 409, description: 'Identity đã bị chiếm hoặc đang thuộc inbound khác'),
            new OA\Response(response: 422, description: 'QR/SKU/item không hợp lệ hoặc item không receivable'),
        ]
    )]
    public function resolveCode(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/inbound/record',
        operationId: 'mini_app_inbound_record',
        summary: 'Atomic ghi nhận toàn bộ local scans thành phiếu WMS chờ duyệt',
        description: 'Một request group theo SKU, tạo receipt + lines + toàn bộ scan evidence trong cùng transaction. Bất kỳ mã nào lỗi sẽ rollback toàn bộ. Không Post Receipt, không tăng tồn.',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
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
                        new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                        new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true, example: 'CAMERA'),
                    ],
                    type: 'object'
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Phiếu SCANNING full-scan; mini_app_status=WAITING_APPROVAL; stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Thiếu inbound.create/inbound.scan hoặc kho ngoài data scope'),
            new OA\Response(response: 409, description: 'Duplicate/concurrency/item đang receiving; batch rollback'),
            new OA\Response(response: 422, description: 'Sai count/QR/SKU/item/business rule; batch rollback'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key'),
        ]
    )]
    public function record(): void {}
}
