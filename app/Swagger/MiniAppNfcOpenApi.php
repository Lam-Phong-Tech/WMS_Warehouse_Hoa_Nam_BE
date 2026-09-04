<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * Documentation-only contract cho nhóm NFC của Mini App.
 *
 * Luồng: quét QR (resolve-code) -> prepare giữ chỗ + nhận `nfc_payload` -> app ghi
 * chuỗi đó vào chip và dán lên sản phẩm -> PATCH .../nfc xác nhận sau khi đọc lại
 * thành công. Từ đó quét chip trả về đúng chuỗi QR nên mọi endpoint scan sẵn có
 * dùng lại được nguyên vẹn.
 */
final class MiniAppNfcOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Get(
        path: '/api/v1/mini-app/items',
        operationId: 'mini_app_item_list',
        x: ['permission' => 'physical_code.assign_rfid|item_instance.view|inventory.view'],
        summary: 'Mini App - danh sách hiện vật kèm trạng thái chip NFC',
        description: 'Worklist của màn gán NFC: mỗi dòng là MỘT hiện vật (item_instance) kèm `nfc_status` (UNASSIGNED|ASSIGNED), khối `nfc` và `nfc_payload`. Mặc định chỉ trả item_status=IN_STOCK; gửi item_status=ALL để bỏ lọc. Read-only.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Khớp serial_number, sku_code hoặc sku_name.', schema: new OA\Schema(type: 'string', maxLength: 200)),
            new OA\Parameter(name: 'sku_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item_status', in: 'query', description: 'Mặc định IN_STOCK; ALL để bỏ lọc.', schema: new OA\Schema(type: 'string', default: 'IN_STOCK')),
            new OA\Parameter(name: 'container_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'nfc_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['UNASSIGNED', 'ASSIGNED', 'ALL'], default: 'ALL')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang hiện vật kèm nfc_status/nfc/nfc_payload.'),
            new OA\Response(response: 403, description: 'Thiếu cả ba quyền được chấp nhận.'),
        ]
    )]
    public function listItems(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/nfc/resolve-code',
        operationId: 'mini_app_nfc_resolve_code',
        x: ['permission' => 'physical_code.assign_rfid'],
        summary: 'Mini App - quét QR sản phẩm để lấy item_id trước khi gán chip',
        description: 'Cầu nối giữa "cầm máy trên tay" và item_id mà prepare cần. Read-only: resolve raw QR thành item + SKU, trả trạng thái chip hiện có và `nfc_payload` (đúng payload QR `HN1|SKU=<sku_code>|ITEM=<item_unique>`, DEC-QR-001). Không tạo bản ghi nào, không giữ chỗ, stock_effect=NONE.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['raw_code'],
                properties: [
                    new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Trả item/sku/nfc_status/nfc_payload + assignable, assign_block_code (ASSIGNABLE | ITEM_NOT_FOUND | ITEM_ALREADY_HAS_NFC | ITEM_IDENTITY_NOT_RESOLVABLE | ITEM_STATUS_NOT_TAGGABLE).'),
            new OA\Response(response: 403, description: 'Thiếu physical_code.assign_rfid.'),
            new OA\Response(response: 422, description: 'SKU trong QR không tồn tại/không ACTIVE hoặc QR trỏ sai SKU của item (WRONG_SKU).'),
        ]
    )]
    public function resolveCode(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/items/{itemId}/nfc/prepare',
        operationId: 'mini_app_nfc_prepare',
        x: ['permission' => 'physical_code.assign_rfid'],
        summary: 'Mini App - giữ chỗ ghi chip NFC cho một hiện vật',
        description: 'Kiểm tra quyền, trạng thái hiện vật, SKU và chip chưa được gán, rồi GIỮ CHỖ có thời hạn (mặc định 300s, đọc từ config warehouse.nfc) trước khi app ghi NDEF. Giữ chỗ tồn tại vì ghi chip là thao tác vật lý không hoàn tác: thiếu nó, hai máy cùng chuẩn bị một hiện vật sẽ có một bên dán chip xong mới biết mình thua. Gọi lại bởi CÙNG người dùng thì gia hạn chỗ đang giữ. Stock-neutral, chưa ghi physical_code nào.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trả nfc_payload + reservation_token + expires_at + ttl_seconds.'),
            new OA\Response(response: 403, description: 'Thiếu physical_code.assign_rfid.'),
            new OA\Response(response: 404, description: 'Không tìm thấy hiện vật.'),
            new OA\Response(response: 409, description: 'ITEM_ALREADY_HAS_NFC hoặc NFC_WRITE_RESERVED_BY_OTHER (máy khác đang giữ chỗ).'),
            new OA\Response(response: 422, description: 'ITEM_IDENTITY_NOT_RESOLVABLE hoặc ITEM_STATUS_NOT_TAGGABLE.'),
        ]
    )]
    public function prepare(): void {}

    #[OA\Patch(
        path: '/api/v1/mini-app/items/{itemId}/nfc',
        operationId: 'mini_app_nfc_confirm',
        x: ['permission' => 'physical_code.assign_rfid'],
        summary: 'Mini App - xác nhận chip NFC đã ghi và đọc lại thành công',
        description: 'Chốt mapping item ↔ hardware_uid sau khi app đã ghi NDEF, dán chip và đọc lại. Ghi một dòng physical_codes carrier_type=NFC (code_value mặc định `NFC-<hardware_uid>`). Hai lớp idempotency: header Idempotency-Key chặn double-submit, khoá tự nhiên hardware_uid chặn cả khi client sinh key mới rồi chạm lại đúng chip cũ. Thao tác STOCK-NEUTRAL, không phải điều kiện của Post Receipt/Post Issue.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                description: 'Giữ nguyên key khi retry cùng một lần xác nhận.',
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['hardware_uid'],
                properties: [
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 150, description: 'UID phần cứng đọc từ chip; duy nhất toàn hệ thống.', example: '04A1B2C3D4E5F6'),
                    new OA\Property(property: 'written_payload', type: 'string', maxLength: 512, nullable: true, description: 'Nội dung ĐỌC LẠI từ chip; BE đối chiếu phải trỏ đúng item, lệch trả NFC_PAYLOAD_ITEM_MISMATCH.'),
                    new OA\Property(property: 'reservation_token', type: 'string', maxLength: 100, nullable: true, description: 'Token nhận từ prepare; giúp xác nhận đúng phiên khi đổi thiết bị.'),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 100, nullable: true, description: 'Bỏ trống thì BE dùng `NFC-<hardware_uid>`.'),
                    new OA\Property(property: 'replace_existing', type: 'boolean', nullable: true, default: false, description: 'true để thay chip cũ (chip cũ chuyển INACTIVE + replaced_by_code_id).'),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true, description: 'Bắt buộc khi replace_existing=true.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Đã gán chip mới; stock_effect=NONE, movement_created=false.'),
            new OA\Response(response: 200, description: 'Chip đã gán trước đó cho đúng item này (idempotent replay).'),
            new OA\Response(response: 403, description: 'Thiếu physical_code.assign_rfid.'),
            new OA\Response(response: 404, description: 'Không tìm thấy hiện vật.'),
            new OA\Response(response: 409, description: 'NFC_TAG_ALREADY_ASSIGNED, NFC_TAG_INACTIVE, ITEM_ALREADY_HAS_NFC, DUPLICATE_CODE_VALUE hoặc NFC_WRITE_RESERVED_BY_OTHER.'),
            new OA\Response(response: 422, description: 'ITEM_IDENTITY_NOT_RESOLVABLE, NFC_PAYLOAD_ITEM_MISMATCH hoặc REPLACE_REASON_REQUIRED.'),
            new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED — thiếu header Idempotency-Key.'),
        ]
    )]
    public function confirm(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/nfc/resolve-tag',
        operationId: 'mini_app_nfc_resolve_tag',
        x: ['permission' => 'physical_code.assign_rfid|inventory.trace|warranty.view'],
        summary: 'Mini App - quét chip NFC để tra cứu sản phẩm đã xuất kho',
        description: 'Dùng cho màn quét NFC thay QR sau khi hàng rời kho. Nhận hardware_uid (UID chip) hoặc raw_code (nội dung đọc từ chip). Trả item + SKU + chip + phiếu xuất đã Post gần nhất (bên nhận) + hồ sơ bảo hành đang mở. PII bên nhận chỉ trả thô cho actor có outbound.view, ngược lại mask kèm cờ pii_masked=true (§13.8).',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 150, nullable: true, example: '04A1B2C3D4E5F6'),
                    new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, nullable: true, example: 'HN1|SKU=DCPL02-8|ITEM=DCPL02-8-000001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'resolve_code = RESOLVED | NFC_TAG_NOT_REGISTERED | NFC_TAG_NOT_ASSIGNED; stock_effect=NONE.'),
            new OA\Response(response: 403, description: 'Thiếu cả ba quyền được chấp nhận.'),
            new OA\Response(response: 422, description: 'Không gửi hardware_uid lẫn raw_code.'),
        ]
    )]
    public function resolveTag(): void {}

    #[OA\Get(
        path: '/api/v1/mini-app/nfc-tags',
        operationId: 'mini_app_nfc_tag_list',
        x: ['permission' => 'physical_code.assign_rfid|inventory.trace'],
        summary: 'Mini App - danh sách chip NFC đã gán',
        description: 'Read-only, xoay quanh CHIP (khác /items xoay quanh hiện vật): lọc theo item_id, hardware_uid hoặc status, gồm cả chip đã thu hồi, trả kèm serial/SKU của hiện vật đang đeo chip.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'hardware_uid', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách chip NFC.'),
            new OA\Response(response: 403, description: 'Thiếu quyền đọc.'),
        ]
    )]
    public function listTags(): void {}

    #[OA\Post(
        path: '/api/v1/mini-app/nfc-tags/{physicalCodeId}/deactivate',
        operationId: 'mini_app_nfc_tag_deactivate',
        x: ['permission' => 'physical_code.assign_rfid'],
        summary: 'Mini App - thu hồi chip NFC (rơi, hỏng, gỡ khỏi sản phẩm)',
        description: 'Chuyển chip sang INACTIVE/LOST/DAMAGED kèm lý do; bản ghi không bị xoá để giữ lịch sử. Sau thao tác này hiện vật quay lại nfc_status=UNASSIGNED và gán được chip mới. Stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'physicalCodeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['INACTIVE', 'LOST', 'DAMAGED']),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, example: 'Chip bong khỏi vỏ máy khi vận chuyển'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã thu hồi chip; stock_effect=NONE.'),
            new OA\Response(response: 403, description: 'Thiếu physical_code.assign_rfid.'),
            new OA\Response(response: 404, description: 'Không tìm thấy chip NFC.'),
            new OA\Response(response: 409, description: 'NFC_TAG_INACTIVE — chip đã thu hồi từ trước.'),
        ]
    )]
    public function deactivate(): void {}
}
