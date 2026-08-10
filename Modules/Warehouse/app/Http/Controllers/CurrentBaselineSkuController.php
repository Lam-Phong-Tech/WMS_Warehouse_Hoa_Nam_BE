<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Services\SkuAggregateService;

class CurrentBaselineSkuController extends Controller
{
    public function __construct(private readonly SkuAggregateService $service) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        return $this->idempotent($request, 'sku_create', function () use ($validated): array {
            $data = DB::transaction(function () use ($validated): array {
                $snapshot = $this->service->create($validated, $this->actorId(), $this->requestId());
                $skuId = (string) $snapshot['id'];
                $this->applyCanonicalMasters($skuId, $validated, 1);

                return $this->service->snapshot($skuId, true);
            }, 3);

            return [201, $this->envelope('Tạo SKU thành công.', $data)];
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->rules(false));
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent($request, "sku_update_{$id}", function () use ($id, $validated, $expectedVersion): array {
            $data = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
                $this->assertImmutableAfterStock($id, $validated);
                $this->service->update($id, $validated, $expectedVersion, $this->actorId(), $this->requestId());
                $this->applyCanonicalMasters($id, $validated, $expectedVersion + 1);

                return $this->service->snapshot($id, true);
            }, 3);

            return [200, $this->envelope('Cập nhật SKU thành công.', $data)];
        });
    }

    private function rules(bool $create): array
    {
        return [
            'code' => [$create ? 'required_without:sku_code' : 'sometimes', 'string', 'max:100'],
            'sku_code' => [$create ? 'required_without:code' : 'sometimes', 'string', 'max:100'],
            'name' => [$create ? 'required_without:sku_name' : 'sometimes', 'string', 'max:255'],
            'sku_name' => [$create ? 'required_without:name' : 'sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sku_type' => ['sometimes', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_id' => ['sometimes', 'nullable', 'uuid', 'exists:brands,id'],
            'brand_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_categories,id'],
            'category_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_models,id'],
            'model_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'power_source_id' => ['sometimes', 'nullable', 'uuid', 'exists:power_sources,id'],
            'power_source_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'power_or_voltage' => ['sometimes', 'nullable', 'string', 'max:100'],
            'package_variant_id' => ['sometimes', 'nullable', 'uuid', 'exists:package_variants,id'],
            'package_variant_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'package_variant' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'published_to_app' => ['sometimes', 'boolean'],
            'is_serial_tracked' => ['sometimes', 'boolean'],
            'warranty_applicable' => ['sometimes', 'boolean'],
            'specification' => ['sometimes', 'nullable', 'array'],
            'components' => ['sometimes', 'array', 'max:100'],
            'components.*.component_id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    private function applyCanonicalMasters(string $skuId, array $payload, int $version): void
    {
        $updates = ['version' => $version, 'updated_at' => now()];

        $this->resolveMaster($payload, 'brand_id', 'brand_code', 'brands', 'brand_code', $updates);
        $this->resolveMaster($payload, 'category_id', 'category_code', 'product_categories', 'category_code', $updates);
        $this->resolveMaster($payload, 'model_id', 'model_code', 'product_models', 'model_code', $updates);
        $this->resolveMaster($payload, 'power_source_id', 'power_source_code', 'power_sources', 'power_source_code', $updates);

        if (array_key_exists('power_or_voltage', $payload) && ! array_key_exists('power_source_id', $updates)) {
            $value = trim((string) $payload['power_or_voltage']);
            if ($value !== '') {
                $master = DB::table('power_sources')
                    ->where('power_source_code', $value)
                    ->orWhere('power_source_name', $value)
                    ->first();
                if ($master !== null) {
                    $updates['power_source_id'] = $master->id;
                    $updates['power_or_voltage'] = $master->power_source_code;
                }
            }
        }

        if (array_key_exists('package_variant_id', $payload) || array_key_exists('package_variant_code', $payload) || array_key_exists('package_variant', $payload)) {
            $packageId = $payload['package_variant_id'] ?? null;
            $code = trim((string) ($payload['package_variant_code'] ?? $payload['package_variant'] ?? ''));
            $master = $packageId
                ? DB::table('package_variants')->where('id', $packageId)->first()
                : DB::table('package_variants')->where('package_code', $code)->first();
            if ($master === null || strtoupper((string) $master->status) !== 'ACTIVE') {
                ApiError::abort(422, 'PACKAGE_VARIANT_NOT_ACTIVE', 'Package Variant không tồn tại hoặc không ACTIVE.');
            }
            $updates['package_variant_id'] = $master->id;
            $updates['package_variant'] = $master->package_code;
        }

        DB::table('sku')->where('sku_id', $skuId)->update($updates);
    }

    private function resolveMaster(
        array $payload,
        string $idField,
        string $codeField,
        string $table,
        string $codeColumn,
        array &$updates
    ): void {
        if (! array_key_exists($idField, $payload) && ! array_key_exists($codeField, $payload)) {
            return;
        }

        $id = $payload[$idField] ?? null;
        $code = trim((string) ($payload[$codeField] ?? ''));
        $master = $id
            ? DB::table($table)->where('id', $id)->first()
            : DB::table($table)->where($codeColumn, $code)->first();
        if ($master === null || strtoupper((string) $master->status) !== 'ACTIVE') {
            ApiError::abort(422, 'MASTER_NOT_ACTIVE', "{$idField}/{$codeField} không tồn tại hoặc không ACTIVE.");
        }
        $updates[$idField] = $master->id;
    }

    private function assertImmutableAfterStock(string $skuId, array $payload): void
    {
        if (! array_key_exists('sku_code', $payload)
            && ! array_key_exists('code', $payload)
            && ! array_key_exists('is_serial_tracked', $payload)) {
            return;
        }

        $hasStock = DB::table('inventory_balances')
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('quantity', '>', 0)
            ->exists();
        $hasMovement = DB::table('stock_movements')
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->exists();
        if ($hasStock || $hasMovement) {
            $current = DB::table('sku')->where('sku_id', $skuId)->first();
            $newCode = strtoupper(trim((string) ($payload['sku_code'] ?? $payload['code'] ?? $current?->sku_code)));
            $newTracking = array_key_exists('is_serial_tracked', $payload)
                ? (bool) $payload['is_serial_tracked']
                : (bool) $current?->is_serial_tracked;
            if ($current && ($newCode !== strtoupper((string) $current->sku_code) || $newTracking !== (bool) $current->is_serial_tracked)) {
                ApiError::abort(409, 'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK', 'Không được đổi sku_code/tracking mode sau khi SKU đã có stock hoặc movement.');
            }
        }
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho SKU update.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header) || (int) $header < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }
        return (int) $header;
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

    private function actorId(): ?string
    {
        $id = auth('api')->id();
        return $id === null ? null : (string) $id;
    }

    private function requestId(): ?string
    {
        $id = request()?->attributes->get('request_id');
        return is_string($id) ? $id : null;
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => $this->requestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
