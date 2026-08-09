<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Services\SkuAggregateService;
use OpenApi\Attributes as OA;

/**
 * Quản trị SKU (WWHN-172): cập nhật SKU aggregate, gán/thu hồi physical code.
 * Tất cả endpoint đều yêu cầu header Idempotency-Key và If-Match (hoặc field version)
 * để đảm bảo idempotency và optimistic locking.
 */
#[OA\Patch(
    path: '/api/v1/skus/{id}',
    operationId: 'sku_update',
    summary: 'Cập nhật SKU aggregate (code/name/unit/sku_type/components/physical_codes...)',
    description: 'Yêu cầu header Idempotency-Key và If-Match (hoặc field version) để chống ghi trùng và đảm bảo optimistic locking.',
    security: [['bearerAuth' => []]],
    tags: ['05. Product'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
        new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true),
                new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true),
                new OA\Property(property: 'brand_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'category_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'model_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'power_or_voltage', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'package_variant', type: 'string', enum: ['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT'], nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'DRAFT'], nullable: true),
                new OA\Property(property: 'published_to_app', type: 'boolean', nullable: true),
                new OA\Property(property: 'is_serial_tracked', type: 'boolean', nullable: true),
                new OA\Property(property: 'version', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'components', type: 'array', nullable: true, items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'component_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'number', format: 'float'),
                    ]
                )),
                new OA\Property(property: 'physical_codes', type: 'array', nullable: true, items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'code_value', type: 'string', maxLength: 150),
                        new OA\Property(property: 'code_type', type: 'string', enum: ['BARCODE', 'QR', 'RFID', 'SERIAL']),
                        new OA\Property(property: 'carrier_type', type: 'string', enum: ['UNIT', 'PACKAGE', 'LABEL', 'TAG']),
                        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Cập nhật SKU thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền sku.manage'),
        new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        new OA\Response(response: 409, description: 'Xung đột version (If-Match không khớp)'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key hoặc If-Match/version'),
    ]
)]
#[OA\Post(
    path: '/api/v1/skus/{id}/physical-codes',
    operationId: 'sku_add_physical_code',
    summary: 'Gán physical code (barcode/QR/RFID/serial) cho SKU',
    description: 'Yêu cầu header Idempotency-Key và If-Match (hoặc field version) để chống ghi trùng và đảm bảo optimistic locking.',
    security: [['bearerAuth' => []]],
    tags: ['05. Product'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
        new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code_value'],
            properties: [
                new OA\Property(property: 'code_value', type: 'string', maxLength: 150),
                new OA\Property(property: 'code_type', type: 'string', enum: ['BARCODE', 'QR', 'RFID', 'SERIAL']),
                new OA\Property(property: 'carrier_type', type: 'string', enum: ['UNIT', 'PACKAGE', 'LABEL', 'TAG']),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
                new OA\Property(property: 'version', type: 'integer', minimum: 1),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Gán physical code thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền sku.manage'),
        new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        new OA\Response(response: 409, description: 'Xung đột version hoặc physical code đã tồn tại'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key hoặc If-Match/version'),
    ]
)]
#[OA\Delete(
    path: '/api/v1/skus/{id}/physical-codes/{physicalCodeId}',
    operationId: 'sku_revoke_physical_code',
    summary: 'Thu hồi physical code khỏi SKU',
    description: 'Yêu cầu header Idempotency-Key và If-Match (hoặc field version) để chống ghi trùng và đảm bảo optimistic locking.',
    security: [['bearerAuth' => []]],
    tags: ['05. Product'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'physicalCodeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
        new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'version', type: 'integer', minimum: 1),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Thu hồi physical code thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền sku.manage'),
        new OA\Response(response: 404, description: 'Không tìm thấy SKU hoặc physical code'),
        new OA\Response(response: 409, description: 'Xung đột version'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key hoặc If-Match/version'),
    ]
)]
class SkuGovernanceController extends Controller
{
    public function __construct(private readonly SkuAggregateService $service) {}

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'sku_code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sku_type' => ['sometimes', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'power_or_voltage' => ['sometimes', 'nullable', 'string', 'max:100'],
            'package_variant' => ['sometimes', 'nullable', Rule::in(['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT'])],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE', 'DRAFT'])],
            'published_to_app' => ['sometimes', 'boolean'],
            'is_serial_tracked' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'components' => ['sometimes', 'array', 'max:100'],
            'components.*.component_id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'physical_codes' => ['sometimes', 'array', 'max:100'],
            'physical_codes.*.code_value' => ['required', 'string', 'max:150'],
            'physical_codes.*.code_type' => ['sometimes', Rule::in(['BARCODE', 'QR', 'RFID', 'SERIAL'])],
            'physical_codes.*.carrier_type' => ['sometimes', Rule::in(['UNIT', 'PACKAGE', 'LABEL', 'TAG'])],
            'physical_codes.*.status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent(
            $request,
            "sku_update_{$id}",
            fn (): array => [
                200,
                $this->envelope(
                    'Cập nhật SKU thành công.',
                    $this->service->update(
                        $id,
                        $validated,
                        $expectedVersion,
                        $this->actorId(),
                        $this->requestId($request)
                    )
                ),
            ]
        );
    }

    public function addPhysicalCode(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'code_value' => ['required', 'string', 'max:150'],
            'code_type' => ['sometimes', Rule::in(['BARCODE', 'QR', 'RFID', 'SERIAL'])],
            'carrier_type' => ['sometimes', Rule::in(['UNIT', 'PACKAGE', 'LABEL', 'TAG'])],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent(
            $request,
            "sku_physical_code_assign_{$id}",
            fn (): array => [
                200,
                $this->envelope(
                    'Gán physical code thành công.',
                    $this->service->addPhysicalCode(
                        $id,
                        $validated,
                        $expectedVersion,
                        $this->actorId(),
                        $this->requestId($request)
                    )
                ),
            ]
        );
    }

    public function revokePhysicalCode(Request $request, string $id, string $physicalCodeId): JsonResponse
    {
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent(
            $request,
            "sku_physical_code_revoke_{$id}_{$physicalCodeId}",
            fn (): array => [
                200,
                $this->envelope(
                    'Thu hồi physical code thành công.',
                    $this->service->revokePhysicalCode(
                        $id,
                        $physicalCodeId,
                        $expectedVersion,
                        $this->actorId(),
                        $this->requestId($request)
                    )
                ),
            ]
        );
    }

    private function idempotent(Request $request, string $operation, callable $callback): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }
        if (mb_strlen($key) > 128) {
            ApiError::abort(422, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key tối đa 128 ký tự.');
        }

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            $callback
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header !== '') {
            $header = preg_replace('/^W\//', '', $header) ?? $header;
            $header = trim($header, '" ');
            if (ctype_digit($header) && (int) $header > 0) {
                return (int) $header;
            }
            ApiError::abort(422, 'INVALID_IF_MATCH', 'If-Match phải chứa version số nguyên dương.');
        }

        $version = $request->input('version');
        if (is_numeric($version) && (int) $version > 0) {
            return (int) $version;
        }

        ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match hoặc field version là bắt buộc.');
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    private function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
