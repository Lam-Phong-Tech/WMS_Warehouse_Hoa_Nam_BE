<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Documentation-only contract for the Warehouse Mini App warranty adapter. */
final class MiniAppWarrantyOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Post(
        path: '/api/v1/mini-app/warranty/resolve-code',
        operationId: 'mini_app_warranty_resolve_code',
        x: ['permission' => 'warranty.view|warranty.manage'],
        summary: 'Mini App - resolve mã sản phẩm trước khi tiếp nhận bảo hành',
        description: 'Read-only preflight cho camera/manual input. Không tạo warranty case, không tạo scan evidence, không đổi tồn. Trả eligible_for_warranty và recommended_flow=IDENTIFIED hoặc TEMP_ONLY để FE quyết định màn tiếp nhận.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['raw_code'], properties: [
            new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'SER-000123'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Resolve result; stock_effect=NONE; wms_case_created=false.')]
    )]
    public function resolveCode(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/warranty-cases',
        operationId: 'mini_app_warranty_cases_list',
        x: ['permission' => 'warranty.view|warranty.manage'],
        summary: 'Mini App - danh sách hồ sơ bảo hành',
        description: 'Adapter đọc canonical warranty cases. Hỗ trợ các filter canonical: status, identification_status, defect_id, keyword, page, per_page. PII vẫn mask theo quyền warranty.pii.view.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'])),
            new OA\Parameter(name: 'identification_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['IDENTIFIED', 'TEMP_ONLY'])),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách phân trang warranty cases.')]
    )]
    public function listCases(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/warranty-cases',
        operationId: 'mini_app_warranty_cases_create',
        x: ['permission' => 'warranty.manage'],
        summary: 'Mini App - tiếp nhận hồ sơ bảo hành',
        description: 'Dùng cùng canonical createWarrantyCase. Có item_code/item_id thì nhánh IDENTIFIED; không resolve được item thì gửi missing_code_reason + manual_product_description để tạo TEMP_ONLY. Stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['customer_name', 'customer_phone', 'description'], properties: [
            new OA\Property(property: 'item_code', type: 'string', maxLength: 512, nullable: true),
            new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'customer_name', type: 'string', maxLength: 200),
            new OA\Property(property: 'customer_phone', type: 'string', maxLength: 30, example: '0901234567'),
            new OA\Property(property: 'customer_address', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'description', type: 'string', maxLength: 2000),
            new OA\Property(property: 'reported_defect', type: 'string', maxLength: 2000, nullable: true),
            new OA\Property(property: 'accessories_received', type: 'string', maxLength: 1000, nullable: true),
            new OA\Property(property: 'received_condition', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'missing_code_reason', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'manual_product_description', type: 'string', maxLength: 2000, nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: 'Warranty case RECEIVED; ETag=version; stock_effect=NONE.')]
    )]
    public function createCase(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/warranty-cases/{id}',
        operationId: 'mini_app_warranty_cases_detail',
        x: ['permission' => 'warranty.view|warranty.manage'],
        summary: 'Mini App - chi tiết hồ sơ bảo hành',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Warranty case detail; PII mask theo quyền.')]
    )]
    public function detailCase(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/warranty-cases/{id}/status',
        operationId: 'mini_app_warranty_cases_status',
        x: ['permission' => 'warranty.manage'],
        summary: 'Mini App - chuyển trạng thái hồ sơ bảo hành',
        description: 'Dùng canonical state matrix: RECEIVED→CHECKING→REPAIRING/COMPLETED→RETURNED hoặc CANCELLED. If-Match bắt buộc; Idempotency-Key tùy chọn. Stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED']),
            new OA\Property(property: 'confirmed_defect', type: 'string', maxLength: 2000, nullable: true),
            new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
            new OA\Property(property: 'cancel_reason', type: 'string', maxLength: 1000, nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'Case updated; ETag=version; stock_effect=NONE.')]
    )]
    public function statusCase(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/warranty-cases/{id}/events',
        operationId: 'mini_app_warranty_cases_events',
        x: ['permission' => 'warranty.view|warranty.manage'],
        summary: 'Mini App - timeline sự kiện bảo hành',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Danh sách warranty_event.')]
    )]
    public function events(): void {}
}
