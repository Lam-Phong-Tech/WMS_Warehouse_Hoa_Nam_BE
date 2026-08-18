<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * Catalog schema chuẩn cho envelope API v1 (WWHN-309 - Backend handover).
 *
 * LÝ DO TỒN TẠI: trước WWHN-309, `storage/api-docs/api-docs.json` có đủ 100%
 * path nhưng `components.schemas` rỗng và không response nào có `content`.
 * FE/QA đọc spec không suy ra được hình dạng envelope success/error, phải đọc
 * ngược code `App\Traits\ApiResponse` / `App\Support\ApiError`. Lớp này khai
 * envelope một lần, và `App\Swagger\Processors\RouteSyncProcessor` gắn
 * `$ref` vào các baseline response nó tự sinh, nên contract không thể lệch
 * khỏi runtime mà không sửa cùng chỗ.
 *
 * Nguồn sự thật runtime:
 * - success/paginated: App\Traits\ApiResponse::respondSuccess/respondPaginated
 * - error: App\Traits\ApiResponse::respondError + App\Support\ApiError::abort
 * - error toàn cục (401/405/404/500...): bootstrap/app.php withExceptions
 */
#[OA\Schema(
    schema: 'ApiMeta',
    title: 'Meta envelope',
    description: 'Luôn có request_id và timestamp. Các khoá phân trang chỉ xuất hiện ở response danh sách có phân trang.',
    type: 'object',
    required: ['request_id', 'timestamp'],
    properties: [
        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', description: 'Định danh request để đối chiếu log/audit.'),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', description: 'Thời điểm server trả lời (ISO-8601, Asia/Ho_Chi_Minh).'),
        new OA\Property(property: 'current_page', type: 'integer', nullable: true),
        new OA\Property(property: 'per_page', type: 'integer', nullable: true),
        new OA\Property(property: 'last_page', type: 'integer', nullable: true),
        new OA\Property(property: 'total', type: 'integer', nullable: true),
        new OA\Property(property: 'from', type: 'integer', nullable: true),
        new OA\Property(property: 'to', type: 'integer', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ApiLinks',
    title: 'Links envelope',
    description: 'Chỉ có ở response danh sách phân trang (respondPaginated).',
    type: 'object',
    properties: [
        new OA\Property(property: 'first', type: 'string', nullable: true),
        new OA\Property(property: 'last', type: 'string', nullable: true),
        new OA\Property(property: 'prev', type: 'string', nullable: true),
        new OA\Property(property: 'next', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ApiSuccessEnvelope',
    title: 'Success envelope',
    description: 'Envelope thành công chuẩn. `data` phụ thuộc endpoint; `links` chỉ có khi phân trang.',
    type: 'object',
    required: ['success', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'data', description: 'Payload nghiệp vụ: object, array hoặc null.', nullable: true),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ApiMeta'),
        new OA\Property(property: 'links', ref: '#/components/schemas/ApiLinks', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ApiErrorDetail',
    title: 'Error detail',
    type: 'object',
    required: ['code'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: 'Mã lỗi nghiệp vụ, xem docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md', example: 'STATE_CONFLICT'),
        new OA\Property(property: 'details', type: 'object', additionalProperties: true, description: 'Chi tiết theo field hoặc theo dòng; rỗng khi lỗi không gắn field.'),
    ]
)]
#[OA\Schema(
    schema: 'ApiErrorEnvelope',
    title: 'Error envelope',
    description: 'Envelope lỗi chuẩn. `error_code` và `error.code` LUÔN bằng nhau: `error_code` là khoá FE hiện dùng, `error.code` là dạng lồng theo Annex C. Không client nào được coi một trong hai là optional.',
    type: 'object',
    required: ['success', 'message', 'error_code', 'error'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', description: 'Thông điệp tiếng Việt hiển thị được cho người dùng; không chứa stack trace hay secret.'),
        new OA\Property(property: 'error_code', type: 'string', example: 'BUSINESS_RULE_ERROR'),
        new OA\Property(property: 'errors', type: 'object', additionalProperties: true, description: 'Bản đồ lỗi theo field (tương thích Laravel validation).'),
        new OA\Property(property: 'error', ref: '#/components/schemas/ApiErrorDetail'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ApiMeta'),
    ]
)]
class ApiEnvelopeSchemas {}
