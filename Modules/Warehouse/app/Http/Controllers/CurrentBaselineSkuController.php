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
use OpenApi\Attributes as OA;

class CurrentBaselineSkuController extends Controller
{
    public function __construct(private readonly SkuAggregateService $service) {}

    #[OA\Post(
        path: '/api/v1/skus',
        operationId: 'sku_create',
        summary: 'Tạo SKU mới (governance aggregate)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Bắt buộc một trong hai alias cho mã (code hoặc sku_code) và một trong hai alias cho tên (name hoặc sku_name).',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 100, description: 'Bắt buộc nếu thiếu sku_code'),
                    new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, description: 'Bắt buộc nếu thiếu code'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, description: 'Bắt buộc nếu thiếu sku_name'),
                    new OA\Property(property: 'sku_name', type: 'string', maxLength: 255, description: 'Bắt buộc nếu thiếu name'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true),
                    new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT']),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'brand_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'category_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'model_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'power_source_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'power_or_voltage', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'package_variant_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'package_variant_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'package_variant', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
                    new OA\Property(property: 'published_to_app', type: 'boolean'),
                    new OA\Property(property: 'is_serial_tracked', type: 'boolean'),
                    new OA\Property(property: 'warranty_applicable', type: 'boolean'),
                    new OA\Property(property: 'specification', type: 'object', nullable: true),
                    new OA\Property(property: 'components', type: 'array', maxItems: 100, items: new OA\Items(
                        required: ['component_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'component_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity', type: 'number', minimum: 0.001),
                        ]
                    )),
                    new OA\Property(property: 'physical_codes', type: 'array', maxItems: 100, items: new OA\Items(
                        required: ['code_value'],
                        properties: [
                            new OA\Property(property: 'code_value', type: 'string', maxLength: 150),
                            new OA\Property(property: 'code_type', type: 'string', nullable: true),
                            new OA\Property(property: 'carrier_type', type: 'string', nullable: true),
                            new OA\Property(property: 'status', type: 'string', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo SKU thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: master tham chiếu không ACTIVE)'),
        ]
    )]
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

    #[OA\Patch(
        path: '/api/v1/skus/{id}',
        operationId: 'sku_update',
        summary: 'Sửa SKU (optimistic locking bằng If-Match version)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 100),
                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100),
                new OA\Property(property: 'name', type: 'string', maxLength: 255),
                new OA\Property(property: 'sku_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true),
                new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT']),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'brand_code', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'category_code', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'model_code', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'power_source_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'power_or_voltage', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'package_variant_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'package_variant_code', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'package_variant', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
                new OA\Property(property: 'published_to_app', type: 'boolean'),
                new OA\Property(property: 'is_serial_tracked', type: 'boolean'),
                new OA\Property(property: 'warranty_applicable', type: 'boolean'),
                new OA\Property(property: 'specification', type: 'object', nullable: true),
                new OA\Property(property: 'components', type: 'array', maxItems: 100, items: new OA\Items(
                    required: ['component_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'component_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'number', minimum: 0.001),
                    ]
                )),
                new OA\Property(property: 'physical_codes', type: 'array', maxItems: 100, items: new OA\Items(
                    required: ['code_value'],
                    properties: [
                        new OA\Property(property: 'code_value', type: 'string', maxLength: 150),
                        new OA\Property(property: 'code_type', type: 'string', nullable: true),
                        new OA\Property(property: 'carrier_type', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', nullable: true),
                    ]
                )),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật SKU thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 409, description: 'sku_code/tracking mode immutable sau khi đã có stock/movement'),
            new OA\Response(response: 412, description: 'STALE_VERSION — version If-Match không khớp'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
            new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED — thiếu If-Match'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->rules(false));
        $expectedVersion = $this->expectedVersion($request);

        return $this->idempotent($request, "sku_update_{$id}", function () use ($id, $validated, $expectedVersion): array {
            $data = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
                $this->assertImmutableAfterStock($id, $validated);
                $validated = $this->assertPublishRequiresActive($id, $validated);
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
            'components.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'physical_codes' => ['sometimes', 'array', 'max:100'],
            'physical_codes.*.code_value' => ['required', 'string', 'max:150'],
            'physical_codes.*.code_type' => ['sometimes', 'nullable', 'string'],
            'physical_codes.*.carrier_type' => ['sometimes', 'nullable', 'string'],
            'physical_codes.*.status' => ['sometimes', 'nullable', 'string'],
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

    /**
     * REQ-SRS-020 / AC-SRS-020: chỉ SKU active mới publish; inactive không được publish.
     * Mirror của guard cùng tên trong ProductController (endpoint /products/{id}) để
     * endpoint governance /skus/{id} không phải là cửa sau bỏ qua rule active-only.
     */
    private function assertPublishRequiresActive(string $skuId, array $payload): array
    {
        $current = DB::table('sku')->where('sku_id', $skuId)->first();
        $resultingStatus = array_key_exists('status', $payload)
            ? strtoupper((string) $payload['status'])
            : strtoupper((string) ($current?->status ?? 'ACTIVE'));

        if ($resultingStatus === 'ACTIVE') {
            return $payload;
        }

        if (array_key_exists('published_to_app', $payload) && (bool) $payload['published_to_app']) {
            ApiError::abort(
                422,
                'SKU_PUBLISH_REQUIRES_ACTIVE',
                'Chỉ SKU đang ACTIVE mới được publish cho App PV.'
            );
        }

        $payload['published_to_app'] = false;

        return $payload;
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
