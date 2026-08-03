<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Product;

/**
 * Transaction boundary for the SKU aggregate.
 *
 * Aggregate members:
 * - products: SKU root and optimistic-lock version;
 * - sku_component: bill-of-material/component edges;
 * - physical_code: physical identifiers assigned to the SKU.
 *
 * All aggregate writes, audit records and idempotency snapshots (outer layer) commit or
 * roll back together. No controller writes these tables directly.
 */
class SkuAggregateService
{
    /** @var array<int, string> */
    private const SKU_FIELDS = [
        'code', 'sku_code', 'name', 'unit', 'sku_type', 'brand_code',
        'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'published_to_app', 'is_serial_tracked', 'status',
    ];

    /** @var array<int, string> */
    private const PHYSICAL_CODE_TYPES = ['BARCODE', 'QR', 'RFID', 'SERIAL'];

    /** @var array<int, string> */
    private const CARRIER_TYPES = ['UNIT', 'PACKAGE', 'LABEL', 'TAG'];

    public function create(array $payload, ?string $actorId, ?string $requestId): array
    {
        return DB::transaction(function () use ($payload, $actorId, $requestId): array {
            $attributes = $this->normalizeSkuAttributes(Arr::only($payload, self::SKU_FIELDS));
            $attributes['status'] = $attributes['status'] ?? 'ACTIVE';
            $attributes['version'] = 1;

            $this->assertSkuCodesAvailable($attributes);

            /** @var Product $product */
            $product = Product::query()->create($attributes);

            $this->replaceComponents($product, $payload['components'] ?? []);
            $this->replacePhysicalCodes($product, $payload['physical_codes'] ?? []);

            $after = $this->snapshot($product->id, true);
            AuditLogger::record(
                'SKU_CREATE',
                'sku',
                (string) $product->id,
                $actorId,
                $requestId,
                afterData: $after,
                reason: 'WWHN-172 aggregate create'
            );

            return $after;
        }, 3);
    }

    public function update(
        string $productId,
        array $payload,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($productId, $payload, $expectedVersion, $actorId, $requestId): array {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($productId);
            if ($product === null) {
                ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
            }

            $this->assertExpectedVersion((int) $product->version, $expectedVersion, 'SKU_VERSION_CONFLICT');
            $before = $this->snapshot((string) $product->id, true);

            $attributes = $this->normalizeSkuAttributes(Arr::only($payload, self::SKU_FIELDS));
            $this->assertSkuCodesAvailable($attributes, (string) $product->id);

            if ($attributes !== []) {
                $product->fill($attributes);
            }

            if (array_key_exists('components', $payload)) {
                $this->replaceComponents($product, (array) $payload['components']);
            }

            if (array_key_exists('physical_codes', $payload)) {
                $this->replacePhysicalCodes($product, (array) $payload['physical_codes']);
            }

            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot((string) $product->id, true);
            AuditLogger::record(
                'SKU_UPDATE',
                'sku',
                (string) $product->id,
                $actorId,
                $requestId,
                beforeData: $before,
                afterData: $after,
                reason: 'WWHN-172 optimistic aggregate update'
            );

            return $after;
        }, 3);
    }

    public function addPhysicalCode(
        string $productId,
        array $physicalCode,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($productId, $physicalCode, $expectedVersion, $actorId, $requestId): array {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($productId);
            if ($product === null) {
                ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
            }

            $this->assertExpectedVersion((int) $product->version, $expectedVersion, 'SKU_VERSION_CONFLICT');
            $before = $this->snapshot((string) $product->id, true);
            $this->upsertPhysicalCode($product, $physicalCode);
            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot((string) $product->id, true);
            AuditLogger::record(
                'SKU_PHYSICAL_CODE_ASSIGN',
                'sku',
                (string) $product->id,
                $actorId,
                $requestId,
                beforeData: $before,
                afterData: $after,
                reason: 'WWHN-172 physical-code assignment'
            );

            return $after;
        }, 3);
    }

    public function revokePhysicalCode(
        string $productId,
        string $physicalCodeId,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($productId, $physicalCodeId, $expectedVersion, $actorId, $requestId): array {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($productId);
            if ($product === null) {
                ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
            }

            $this->assertExpectedVersion((int) $product->version, $expectedVersion, 'SKU_VERSION_CONFLICT');
            $before = $this->snapshot((string) $product->id, true);

            $code = DB::table('physical_code')
                ->where('physical_code_id', $physicalCodeId)
                ->lockForUpdate()
                ->first();

            if ($code === null || (string) $code->product_id !== (string) $product->id) {
                ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Physical code không thuộc SKU này.');
            }

            DB::table('physical_code')
                ->where('physical_code_id', $physicalCodeId)
                ->update([
                    'status' => 'REVOKED',
                    'product_id' => null,
                    'assigned_object_type' => null,
                    'assigned_object_id' => null,
                    'version' => ((int) $code->version) + 1,
                    'updated_at' => now(),
                ]);

            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot((string) $product->id, true);
            AuditLogger::record(
                'SKU_PHYSICAL_CODE_REVOKE',
                'sku',
                (string) $product->id,
                $actorId,
                $requestId,
                beforeData: $before,
                afterData: $after,
                reason: 'WWHN-172 physical-code revoke'
            );

            return $after;
        }, 3);
    }

    public function snapshot(string $productId, bool $failIfMissing = false): array
    {
        $product = Product::query()->find($productId);
        if ($product === null) {
            if ($failIfMissing) {
                ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
            }

            return [];
        }

        $components = DB::table('sku_component as sc')
            ->join('products as component', 'component.id', '=', 'sc.component_product_id')
            ->where('sc.parent_product_id', $productId)
            ->where('sc.status', 'ACTIVE')
            ->orderBy('component.code')
            ->get([
                'sc.sku_component_id',
                'sc.component_product_id',
                'component.code as component_code',
                'component.sku_code as component_sku_code',
                'component.name as component_name',
                'sc.quantity',
                'sc.version',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $physicalCodes = DB::table('physical_code')
            ->where('product_id', $productId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('code_value')
            ->get([
                'physical_code_id', 'code_value', 'code_type', 'carrier_type',
                'status', 'version', 'created_at', 'updated_at',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return array_merge($product->toArray(), [
            'components' => $components,
            'physical_codes' => $physicalCodes,
        ]);
    }

    private function replaceComponents(Product $product, array $components): void
    {
        if (strtoupper((string) $product->sku_type) === 'COMPONENT' && $components !== []) {
            ApiError::abort(
                422,
                'COMPONENT_CANNOT_HAVE_CHILDREN',
                'SKU loại COMPONENT không được khai báo component con.'
            );
        }

        DB::table('sku_component')
            ->where('parent_product_id', $product->id)
            ->lockForUpdate()
            ->get();

        DB::table('sku_component')->where('parent_product_id', $product->id)->delete();

        $seen = [];
        foreach ($components as $index => $component) {
            $componentId = strtolower(trim((string) ($component['component_id'] ?? '')));
            $quantity = (string) ($component['quantity'] ?? '');

            if (! Str::isUuid($componentId)) {
                ApiError::abort(422, 'INVALID_COMPONENT', "components.{$index}.component_id không hợp lệ.");
            }
            if ($componentId === strtolower((string) $product->id)) {
                ApiError::abort(422, 'SKU_COMPONENT_SELF_REFERENCE', 'SKU không thể là component của chính nó.');
            }
            if (isset($seen[$componentId])) {
                ApiError::abort(422, 'DUPLICATE_COMPONENT', 'Danh sách component có phần tử trùng.', [
                    'component_id' => $componentId,
                ]);
            }
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                ApiError::abort(422, 'INVALID_COMPONENT_QUANTITY', 'Số lượng component phải lớn hơn 0.', [
                    'component_id' => $componentId,
                ]);
            }

            $componentProduct = Product::query()->lockForUpdate()->find($componentId);
            if ($componentProduct === null || strtoupper((string) $componentProduct->status) !== 'ACTIVE') {
                ApiError::abort(422, 'COMPONENT_NOT_ACTIVE', 'Component không tồn tại hoặc không ACTIVE.', [
                    'component_id' => $componentId,
                ]);
            }

            $this->assertNoComponentCycle((string) $product->id, $componentId);

            DB::table('sku_component')->insert([
                'sku_component_id' => (string) Str::uuid(),
                'parent_product_id' => (string) $product->id,
                'component_product_id' => $componentId,
                'quantity' => $quantity,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $seen[$componentId] = true;
        }
    }

    private function assertNoComponentCycle(string $parentId, string $candidateComponentId): void
    {
        $frontier = [$candidateComponentId];
        $visited = [];

        while ($frontier !== []) {
            $normalized = array_values(array_unique(array_map('strtolower', $frontier)));
            if (in_array(strtolower($parentId), $normalized, true)) {
                ApiError::abort(422, 'SKU_COMPONENT_CYCLE', 'Quan hệ component tạo chu trình.', [
                    'parent_product_id' => $parentId,
                    'component_product_id' => $candidateComponentId,
                ]);
            }

            $next = DB::table('sku_component')
                ->whereIn('parent_product_id', $normalized)
                ->where('status', 'ACTIVE')
                ->pluck('component_product_id')
                ->map(fn (mixed $id): string => strtolower((string) $id))
                ->reject(fn (string $id): bool => isset($visited[$id]))
                ->values()
                ->all();

            foreach ($normalized as $id) {
                $visited[$id] = true;
            }

            $frontier = $next;
        }
    }

    private function replacePhysicalCodes(Product $product, array $physicalCodes): void
    {
        $normalizedCodes = [];
        foreach ($physicalCodes as $index => $physicalCode) {
            $normalized = $this->normalizePhysicalCode($physicalCode, $index);
            if (isset($normalizedCodes[$normalized['code_value']])) {
                ApiError::abort(422, 'DUPLICATE_PHYSICAL_CODE', 'Physical code bị trùng trong payload.', [
                    'code_value' => $normalized['code_value'],
                ]);
            }
            $normalizedCodes[$normalized['code_value']] = $normalized;
        }

        $currentRows = DB::table('physical_code')
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->get();

        foreach ($currentRows as $row) {
            if (! isset($normalizedCodes[strtoupper((string) $row->code_value)])) {
                DB::table('physical_code')
                    ->where('physical_code_id', $row->physical_code_id)
                    ->update([
                        'status' => 'REVOKED',
                        'product_id' => null,
                        'assigned_object_type' => null,
                        'assigned_object_id' => null,
                        'version' => ((int) $row->version) + 1,
                        'updated_at' => now(),
                    ]);
            }
        }

        foreach ($normalizedCodes as $physicalCode) {
            $this->upsertPhysicalCode($product, $physicalCode);
        }
    }

    private function upsertPhysicalCode(Product $product, array $physicalCode): void
    {
        $normalized = $this->normalizePhysicalCode($physicalCode);
        $existing = DB::table('physical_code')
            ->where('code_value', $normalized['code_value'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $assignedId = $existing->product_id ?: $existing->assigned_object_id;
            $assignedType = strtoupper((string) $existing->assigned_object_type);
            if ($assignedId !== null
                && (string) $assignedId !== (string) $product->id) {
                ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã được gán cho object khác.', [
                    'code_value' => $normalized['code_value'],
                    'assigned_object_type' => $existing->assigned_object_type,
                    'assigned_object_id' => $existing->assigned_object_id,
                ]);
            }

            DB::table('physical_code')
                ->where('physical_code_id', $existing->physical_code_id)
                ->update([
                    'product_id' => (string) $product->id,
                    'code_type' => $normalized['code_type'],
                    'carrier_type' => $normalized['carrier_type'],
                    'assigned_object_type' => 'SKU',
                    'assigned_object_id' => (string) $product->id,
                    'status' => $normalized['status'],
                    'version' => ((int) $existing->version) + 1,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('physical_code')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'product_id' => (string) $product->id,
            'code_value' => $normalized['code_value'],
            'code_type' => $normalized['code_type'],
            'carrier_type' => $normalized['carrier_type'],
            'assigned_object_type' => 'SKU',
            'assigned_object_id' => (string) $product->id,
            'status' => $normalized['status'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizePhysicalCode(array $physicalCode, ?int $index = null): array
    {
        $prefix = $index === null ? 'physical_code' : "physical_codes.{$index}";
        $codeValue = strtoupper(trim((string) ($physicalCode['code_value'] ?? '')));
        $codeType = strtoupper(trim((string) ($physicalCode['code_type'] ?? 'BARCODE')));
        $carrierType = strtoupper(trim((string) ($physicalCode['carrier_type'] ?? 'UNIT')));
        $status = strtoupper(trim((string) ($physicalCode['status'] ?? 'ACTIVE')));

        if ($codeValue === '' || mb_strlen($codeValue) > 150) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE', "{$prefix}.code_value không hợp lệ.");
        }
        if (! in_array($codeType, self::PHYSICAL_CODE_TYPES, true)) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE_TYPE', "{$prefix}.code_type không hợp lệ.");
        }
        if (! in_array($carrierType, self::CARRIER_TYPES, true)) {
            ApiError::abort(422, 'INVALID_CARRIER_TYPE', "{$prefix}.carrier_type không hợp lệ.");
        }
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE_STATUS', "{$prefix}.status không hợp lệ.");
        }

        return [
            'code_value' => $codeValue,
            'code_type' => $codeType,
            'carrier_type' => $carrierType,
            'status' => $status,
        ];
    }

    private function normalizeSkuAttributes(array $attributes): array
    {
        foreach (['code', 'sku_code', 'brand_code', 'category_code', 'model_code'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = strtoupper(trim((string) $attributes[$field]));
            }
        }

        foreach (['name', 'unit', 'power_or_voltage', 'package_variant', 'sku_type', 'status'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = trim((string) $attributes[$field]);
            }
        }

        if (isset($attributes['sku_type'])) {
            $attributes['sku_type'] = strtoupper($attributes['sku_type']);
        }
        if (isset($attributes['package_variant'])) {
            $attributes['package_variant'] = strtoupper($attributes['package_variant']);
        }
        if (isset($attributes['status'])) {
            $attributes['status'] = strtoupper($attributes['status']);
        }

        if (! isset($attributes['code']) && isset($attributes['sku_code'])) {
            $attributes['code'] = $attributes['sku_code'];
        }
        if (! isset($attributes['sku_code']) && isset($attributes['code'])) {
            $attributes['sku_code'] = $attributes['code'];
        }

        return $attributes;
    }

    private function assertSkuCodesAvailable(array $attributes, ?string $ignoreId = null): void
    {
        foreach (['code', 'sku_code'] as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                continue;
            }

            $query = Product::query()->where($field, $attributes[$field])->lockForUpdate();
            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if ($query->exists()) {
                ApiError::abort(409, 'SKU_CODE_DUPLICATE', "{$field} đã tồn tại.", [
                    $field => $attributes[$field],
                ]);
            }
        }
    }

    private function assertExpectedVersion(int $actual, int $expected, string $errorCode): void
    {
        if ($actual !== $expected) {
            ApiError::abort(409, $errorCode, 'Dữ liệu đã thay đổi bởi request khác.', [
                'expected_version' => $expected,
                'actual_version' => $actual,
            ]);
        }
    }
}
