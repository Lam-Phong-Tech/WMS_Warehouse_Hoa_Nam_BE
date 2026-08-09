<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Services\SkuAggregateService;

/**
 * WWHN-172 SKU aggregate endpoints.
 * Idempotency-Key uses the project-wide 8..100 contract. Mutations on an
 * existing SKU also require If-Match or body version for optimistic locking.
 */
class SkuGovernanceController extends Controller
{
    public function __construct(private readonly SkuAggregateService $service) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->skuRules(create: true));

        return $this->idempotent($request, 'sku_create', fn (): array => [
            201,
            $this->envelope('Tạo SKU thành công.', $this->service->create(
                $validated, $this->actorId(), $this->requestId($request)
            )),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->skuRules(create: false));
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent($request, "sku_update_{$id}", fn (): array => [
            200,
            $this->envelope('Cập nhật SKU thành công.', $this->service->update(
                $id, $validated, $expectedVersion, $this->actorId(), $this->requestId($request)
            )),
        ]);
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

        return $this->idempotent($request, "sku_physical_code_assign_{$id}", fn (): array => [
            200,
            $this->envelope('Gán physical code thành công.', $this->service->addPhysicalCode(
                $id, $validated, $expectedVersion, $this->actorId(), $this->requestId($request)
            )),
        ]);
    }

    public function revokePhysicalCode(Request $request, string $id, string $physicalCodeId): JsonResponse
    {
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent($request, "sku_physical_code_revoke_{$id}_{$physicalCodeId}", fn (): array => [
            200,
            $this->envelope('Thu hồi physical code thành công.', $this->service->revokePhysicalCode(
                $id, $physicalCodeId, $expectedVersion, $this->actorId(), $this->requestId($request)
            )),
        ]);
    }

    private function skuRules(bool $create): array
    {
        return [
            'code' => [$create ? 'required_without:sku_code' : 'sometimes', 'string', 'max:100'],
            'sku_code' => [$create ? 'required_without:code' : 'sometimes', 'string', 'max:100'],
            'name' => [$create ? 'required_without:sku_name' : 'sometimes', 'string', 'max:255'],
            'sku_name' => [$create ? 'required_without:name' : 'sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sku_type' => ['sometimes', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'power_or_voltage' => ['sometimes', 'nullable', 'string', 'max:100'],
            'package_variant' => ['sometimes', 'nullable', 'string', 'max:50'],
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
        ];
    }

    private function idempotent(Request $request, string $operation, callable $callback): JsonResponse
    {
        $key = IdempotencyKeyGuard::require($request);

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
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
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
