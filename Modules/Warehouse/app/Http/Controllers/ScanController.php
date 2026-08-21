<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Services\ScanClassificationService;
use Modules\Warehouse\Support\ProductQrPayload;
use OpenApi\Attributes as OA;

/**
 * Scan classify is a read-only boundary. It resolves and validates a code only;
 * inventory posting is owned by the dedicated inbound/outbound actions.
 */
#[OA\Schema(
    schema: 'Wwhn150ApiError',
    required: ['success', 'message', 'error_code', 'errors', 'error', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', enum: [false]),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'error_code', type: 'string', enum: [
            'UNAUTHENTICATED', 'ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED', 'WAREHOUSE_NOT_FOUND',
            'SCAN_CODE_NOT_FOUND', 'WAREHOUSE_INACTIVE', 'SCAN_CODE_INACTIVE',
            'SCAN_CONTAINER_INACTIVE', 'SCAN_SKU_INACTIVE', 'SCAN_LOCATION_UNKNOWN',
            'SCAN_WAREHOUSE_MISMATCH', 'SCAN_CONTEXT_CONFLICT', 'BUSINESS_RULE_ERROR', 'VALIDATION_ERROR',
            // Change Pack QR/SKU 20/08/2026 (DEC-QR-001) — composite PRODUCT QR:
            'INVALID_PAYLOAD', 'UNSUPPORTED_PAYLOAD_VERSION', 'SKU_NOT_FOUND', 'SKU_INACTIVE',
            'WRONG_SKU', 'UNKNOWN_CODE',
        ]),
        new OA\Property(property: 'errors', oneOf: [
            new OA\Schema(type: 'object'),
            new OA\Schema(type: 'array', items: new OA\Items),
        ]),
        new OA\Property(property: 'error', type: 'object', required: ['code', 'details'], properties: [
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'details', oneOf: [
                new OA\Schema(type: 'object'),
                new OA\Schema(type: 'array', items: new OA\Items),
            ]),
        ]),
        new OA\Property(property: 'meta', type: 'object', required: ['request_id', 'timestamp'], properties: [
            new OA\Property(property: 'request_id', type: 'string'),
            new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
        ]),
    ]
)]
#[OA\Post(
    path: '/api/v1/scan/classify',
    operationId: 'scan_classify',
    x: ['permission' => 'scan.classify'],
    summary: 'Phân loại và validate mã scan, không thay đổi tồn kho',
    description: 'Contract WWHN-150: endpoint chỉ đọc dữ liệu để resolve ITEM/CONTAINER/SKU theo warehouse data scope. stock_effect luôn bằng NONE; không tạo inventory balance, stock movement, posting_event, scan_event hoặc idempotency cache. Header Idempotency-Key nếu client gửi lên được bỏ qua vì đây không phải posting operation.',
    security: [['bearerAuth' => []]],
    tags: ['11. Scan'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['raw_code', 'context', 'warehouse_id'],
            properties: [
                new OA\Property(property: 'raw_code', type: 'string', maxLength: 512, description: 'RAW scan payload: composite PRODUCT QR (HN1|SKU=<sku_code>|ITEM=<item_unique>) hoặc plain QR/barcode/serial/container/SKU code. Server parse authoritative — client không tự tách SKU/item.'),
                new OA\Property(property: 'context', type: 'string', enum: ['INBOUND', 'OUTBOUND', 'INVENTORY', 'TRACE', 'WARRANTY_RECEIVE', 'WARRANTY_TEMP_ONLY']),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Kho nằm trong data scope của người dùng'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Resolve thành công; stock_effect=NONE và toàn bộ write flags=false',
            content: new OA\JsonContent(
                required: ['success', 'message', 'data', 'meta'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', enum: [true]),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'object', required: [
                        'raw_code', 'context', 'classification', 'stock_status', 'resolved_source',
                        'contract_version', 'stock_effect', 'movement_created',
                        'posting_event_created', 'idempotency_cache_written', 'current_location',
                    ], properties: [
                        new OA\Property(property: 'raw_code', type: 'string'),
                        new OA\Property(property: 'context', type: 'string', enum: ['INBOUND', 'OUTBOUND', 'INVENTORY', 'TRACE', 'WARRANTY_RECEIVE', 'WARRANTY_TEMP_ONLY']),
                        new OA\Property(property: 'classification', type: 'string', enum: ['ITEM', 'CONTAINER', 'SKU']),
                        new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
                        // Inbound Carton Scan v1.0 §3.1: classification=CONTAINER phải trả
                        // container_id non-null để FE đặt carton làm "Thùng đang quét".
                        new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, description: 'Identity của container khi classification=CONTAINER (non-null trong trường hợp đó); null cho ITEM/SKU.'),
                        new OA\Property(property: 'container_code', type: 'string', nullable: true, description: 'Mã container hiển thị (ví dụ CTN-001) khi classification=CONTAINER.'),
                        new OA\Property(property: 'container_type', type: 'string', enum: ['CARTON', 'BOX', 'TRAY'], nullable: true, description: 'Loại container theo ERD bảng 22; FE chỉ đặt active carton cho CARTON.'),
                        new OA\Property(property: 'stock_status', type: 'string'),
                        new OA\Property(property: 'object_status', type: 'string'),
                        new OA\Property(property: 'resolved_source', type: 'string', enum: [
                            'physical_code', 'inventory_serials.serial_no', 'container.container_code', 'products.code',
                        ]),
                        new OA\Property(property: 'contract_version', type: 'string', enum: ['WWHN-150.1.1.0']),
                        new OA\Property(property: 'stock_effect', type: 'string', enum: ['NONE']),
                        new OA\Property(property: 'movement_created', type: 'boolean', enum: [false]),
                        new OA\Property(property: 'posting_event_created', type: 'boolean', enum: [false]),
                        new OA\Property(property: 'idempotency_cache_written', type: 'boolean', enum: [false]),
                        new OA\Property(property: 'payload_format', type: 'string', enum: ['COMPOSITE_V1'], nullable: true, description: 'Chỉ có khi raw_code là composite PRODUCT QR (Change Pack QR/SKU).'),
                        new OA\Property(property: 'payload_version', type: 'integer', nullable: true),
                        new OA\Property(property: 'sku_code', type: 'string', nullable: true, description: 'sku_code embedded đã parse từ composite QR.'),
                        new OA\Property(property: 'item_unique', type: 'string', nullable: true),
                        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
                        new OA\Property(property: 'normalized_code_value', type: 'string', nullable: true, description: 'Identity chuẩn hoá để gọi tiếp trace/warranty; không dùng raw payload cho path/URL.'),
                        new OA\Property(property: 'resolution_status', type: 'string', enum: ['EXISTING_ITEM'], nullable: true),
                        new OA\Property(property: 'current_location', type: 'object', required: ['warehouse_id', 'warehouse_code', 'warehouse_name'], properties: [
                            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'warehouse_code', type: 'string'),
                            new OA\Property(property: 'warehouse_name', type: 'string'),
                        ]),
                    ]),
                    new OA\Property(property: 'meta', type: 'object', required: ['request_id', 'timestamp'], properties: [
                        new OA\Property(property: 'request_id', type: 'string'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                    ]),
                ]
            )
        ),
        new OA\Response(response: 401, description: 'Chưa xác thực', content: new OA\JsonContent(ref: '#/components/schemas/Wwhn150ApiError')),
        new OA\Response(response: 403, description: 'Thiếu scan.classify hoặc kho ngoài data scope', content: new OA\JsonContent(ref: '#/components/schemas/Wwhn150ApiError')),
        new OA\Response(response: 404, description: 'Không tìm thấy mã scan hoặc kho', content: new OA\JsonContent(ref: '#/components/schemas/Wwhn150ApiError')),
        new OA\Response(response: 409, description: 'Kho/mã/object/context không hợp lệ', content: new OA\JsonContent(ref: '#/components/schemas/Wwhn150ApiError')),
        new OA\Response(response: 422, description: 'Payload validation thất bại', content: new OA\JsonContent(ref: '#/components/schemas/Wwhn150ApiError')),
    ]
)]
class ScanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ScanClassificationService $classifier) {}

    public function classify(Request $request)
    {
        $validated = $request->validate([
            // Change Pack QR/SKU 20/08/2026: raw_code là RAW scan payload; trần
            // nâng 150 -> 512 cho composite QR (nguồn số: config product_qr).
            'raw_code' => ['required', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            // SRS §5.3: camera context gồm inbound, outbound, inventory trace/search
            // và warranty receive/search. INVENTORY/TRACE/WARRANTY_RECEIVE là
            // read-only nên không có ràng buộc trạng thái như OUTBOUND.
            'context' => ['required', 'string', 'in:INBOUND,OUTBOUND,INVENTORY,TRACE,WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY'],
            'warehouse_id' => ['required', 'uuid'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Người dùng chưa được xác thực.');
        }
        if (! $user->canAccessWarehouse((string) $validated['warehouse_id'])) {
            ApiError::abort(403, 'WAREHOUSE_SCOPE_DENIED', 'Kho yêu cầu nằm ngoài data scope.', [
                'warehouse_id' => (string) $validated['warehouse_id'],
            ]);
        }

        $warehouse = Warehouse::query()->find($validated['warehouse_id']);
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho được yêu cầu.');
        }
        if (strtoupper((string) $warehouse->status) !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', "Kho \"{$warehouse->name}\" đang ngừng hoạt động.");
        }

        // DEC-QR-001: composite PRODUCT QR được parse authoritative tại đây rồi
        // resolve theo normalized item_unique — classify là resolver dùng chung
        // cho WARRANTY/TRACE (Change Pack §6.3/§6.4, AC-QR-06/07).
        $rawCode = (string) $validated['raw_code'];
        $parser = app(ProductQrPayloadParser::class);
        $parsed = null;
        $embeddedSku = null;
        $classifyCode = $rawCode;
        if ($parser->looksComposite($rawCode)) {
            $parsed = $parser->parse($rawCode);
            if ($parsed->parseStatus === ProductQrPayload::STATUS_UNSUPPORTED_VERSION) {
                ApiError::abort(422, 'UNSUPPORTED_PAYLOAD_VERSION', 'Version cấu trúc QR không được hỗ trợ.', [
                    'payload_version' => $parsed->payloadVersion,
                ]);
            }
            if (! $parsed->isValid()) {
                ApiError::abort(422, 'INVALID_PAYLOAD', 'QR/Barcode composite sai cấu trúc; không thể parse SKU + item unique.');
            }
            $embeddedSku = app(ProductIdentityResolver::class)->requireActiveSkuByCode((string) $parsed->skuCode);
            $classifyCode = (string) $parsed->normalizedCodeValue;
        }

        try {
            $classification = $this->classifier->classify(
                $classifyCode,
                (string) $validated['context'],
                $warehouse,
                $user->warehouseScopeIds() !== null
            );
        } catch (HttpResponseException $exception) {
            if ($parsed !== null && $this->isScanCodeNotFound($exception)) {
                // AC-QR-06: QR parse được nhưng item chưa đăng ký — không suy đoán
                // link; FE cho phép chuyển TEMP_ONLY (warranty) / báo lỗi (trace).
                ApiError::abort(404, 'UNKNOWN_CODE', 'QR hợp lệ nhưng item chưa được đăng ký trong hệ thống.', [
                    'sku_code' => $parsed->skuCode,
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            throw $exception;
        }

        if ($parsed !== null) {
            if (($classification['classification'] ?? null) !== 'ITEM') {
                ApiError::abort(404, 'UNKNOWN_CODE', 'item_unique trong QR không trỏ tới một ITEM đã đăng ký.', [
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            if ((string) ($classification['sku_id'] ?? '') !== (string) $embeddedSku->sku_id) {
                ApiError::abort(422, 'WRONG_SKU', 'SKU embedded trong QR không khớp SKU của item đã đăng ký.', [
                    'sku_code' => $parsed->skuCode,
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            $classification += [
                'payload_format' => $parsed->format,
                'payload_version' => $parsed->payloadVersion,
                'sku_code' => $parsed->skuCode,
                'item_unique' => $parsed->itemUnique,
                'serial_number' => $parsed->serialNumber,
                'normalized_code_value' => $parsed->normalizedCodeValue,
                'resolution_status' => 'EXISTING_ITEM',
            ];
        }

        return $this->respondSuccess([
            'raw_code' => trim((string) $validated['raw_code']),
            'context' => (string) $validated['context'],
            ...$classification,
            'current_location' => [
                'warehouse_id' => (string) $warehouse->id,
                'warehouse_code' => (string) $warehouse->code,
                'warehouse_name' => (string) $warehouse->name,
            ],
        ], 'Scan classified without stock effect');
    }

    private function isScanCodeNotFound(HttpResponseException $exception): bool
    {
        $response = $exception->getResponse();
        if ($response->getStatusCode() !== 404) {
            return false;
        }

        $payload = json_decode((string) $response->getContent(), true);

        return is_array($payload)
            && ($payload['error_code'] ?? $payload['error']['code'] ?? null) === 'SCAN_CODE_NOT_FOUND';
    }
}
