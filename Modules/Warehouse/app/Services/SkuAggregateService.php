<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Product;

/**
 * Single transaction boundary for products + sku_component + physical_code + audit.
 */
class SkuAggregateService
{
    private const SKU_FIELDS = [
        'code', 'sku_code', 'name', 'unit', 'sku_type', 'brand_code',
        'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'published_to_app', 'is_serial_tracked', 'status',
    ];

    private const CODE_TYPES = ['BARCODE', 'QR', 'RFID', 'SERIAL'];
    private const CARRIER_TYPES = ['UNIT', 'PACKAGE', 'LABEL', 'TAG'];

    public function create(array $payload, ?string $actorId, ?string $requestId): array
    {
        return DB::transaction(function () use ($payload, $actorId, $requestId): array {
            $attributes = $this->normalizeSku(Arr::only($payload, self::SKU_FIELDS));
            $attributes['status'] = $attributes['status'] ?? 'ACTIVE';
            $attributes['version'] = 1;
            $this->assertCodesAvailable($attributes);

            /** @var Product $product */
            $product = Product::query()->create($attributes);
            $this->replaceComponents($product, (array) ($payload['components'] ?? []));
            $this->replacePhysicalCodes($product, (array) ($payload['physical_codes'] ?? []));

            $after = $this->snapshot((string) $product->id, true);
            $this->audit('SKU_CREATE', $product, $actorId, $requestId, [], $after);

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
            $product = $this->lockProduct($productId);
            $this->assertVersion((int) $product->version, $expectedVersion);
            $before = $this->snapshot($productId, true);

            $attributes = $this->normalizeSku(Arr::only($payload, self::SKU_FIELDS));
            $this->assertCodesAvailable($attributes, $productId);
            $product->fill($attributes);

            if (array_key_exists('components', $payload)) {
                $this->replaceComponents($product, (array) $payload['components']);
            }
            if (array_key_exists('physical_codes', $payload)) {
                $this->replacePhysicalCodes($product, (array) $payload['physical_codes']);
            }

            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot($productId, true);
            $this->audit('SKU_UPDATE', $product, $actorId, $requestId, $before, $after);

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
            $product = $this->lockProduct($productId);
            $this->assertVersion((int) $product->version, $expectedVersion);
            $before = $this->snapshot($productId, true);

            $this->upsertPhysicalCode($product, $physicalCode);
            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot($productId, true);
            $this->audit('SKU_PHYSICAL_CODE_ASSIGN', $product, $actorId, $requestId, $before, $after);

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
            $product = $this->lockProduct($productId);
            $this->assertVersion((int) $product->version, $expectedVersion);
            $before = $this->snapshot($productId, true);

            $code = DB::table('physical_code')
                ->where('physical_code_id', $physicalCodeId)
                ->lockForUpdate()
                ->first();
            if ($code === null || (string) $code->product_id !== $productId) {
                ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Physical code không thuộc SKU này.');
            }

            DB::table('physical_code')->where('physical_code_id', $physicalCodeId)->update([
                'status' => 'REVOKED',
                'product_id' => null,
                'assigned_object_type' => null,
                'assigned_object_id' => null,
                'version' => ((int) $code->version) + 1,
                'updated_at' => now(),
            ]);

            $product->version = ((int) $product->version) + 1;
            $product->save();

            $after = $this->snapshot($productId, true);
            $this->audit('SKU_PHYSICAL_CODE_REVOKE', $product, $actorId, $requestId, $before, $after);

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
            ->join('products as p', 'p.id', '=', 'sc.component_product_id')
            ->where('sc.parent_product_id', $productId)
            ->where('sc.status', 'ACTIVE')
            ->orderBy('p.code')
            ->get([
                'sc.sku_component_id', 'sc.component_product_id',
                'p.code as component_code', 'p.sku_code as component_sku_code',
                'p.name as component_name', 'sc.quantity', 'sc.version',
            ])->map(fn (object $row): array => (array) $row)->all();

        $physicalCodes = DB::table('physical_code')
            ->where('product_id', $productId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('code_value')
            ->get([
                'physical_code_id', 'code_value', 'code_type', 'carrier_type',
                'status', 'version', 'created_at', 'updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();

        return array_merge($product->toArray(), [
            'components' => $components,
            'physical_codes' => $physicalCodes,
        ]);
    }

    private function replaceComponents(Product $product, array $components): void
    {
        if (strtoupper((string) $product->sku_type) === 'COMPONENT' && $components !== []) {
            ApiError::abort(422, 'COMPONENT_CANNOT_HAVE_CHILDREN', 'SKU loại COMPONENT không được có component con.');
        }

        DB::table('sku_component')->where('parent_product_id', $product->id)->lockForUpdate()->get();
        DB::table('sku_component')->where('parent_product_id', $product->id)->delete();

        $seen = [];
        foreach ($components as $index => $component) {
            $componentId = strtolower(trim((string) ($component['component_id'] ?? '')));
            $quantity = $component['quantity'] ?? null;

            if (! Str::isUuid($componentId)) {
                ApiError::abort(422, 'INVALID_COMPONENT', "components.{$index}.component_id không hợp lệ.");
            }
            if ($componentId === strtolower((string) $product->id)) {
                ApiError::abort(422, 'SKU_COMPONENT_SELF_REFERENCE', 'SKU không thể là component của chính nó.');
            }
            if (isset($seen[$componentId])) {
                ApiError::abort(422, 'DUPLICATE_COMPONENT', 'Danh sách component có phần tử trùng.');
            }
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                ApiError::abort(422, 'INVALID_COMPONENT_QUANTITY', 'Số lượng component phải lớn hơn 0.');
            }

            $componentProduct = Product::query()->lockForUpdate()->find($componentId);
            if ($componentProduct === null || strtoupper((string) $componentProduct->status) !== 'ACTIVE') {
                ApiError::abort(422, 'COMPONENT_NOT_ACTIVE', 'Component không tồn tại hoặc không ACTIVE.', [
                    'component_id' => $componentId,
                ]);
            }

            $this->assertNoCycle((string) $product->id, $componentId);
            DB::table('sku_component')->insert([
                'sku_component_id' => (string) Str::uuid(),
                'parent_product_id' => (string) $product->id,
                'component_product_id' => $componentId,
                'quantity' => (string) $quantity,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $seen[$componentId] = true;
        }
    }

    private function assertNoCycle(string $parentId, string $candidateId): void
    {
        $frontier = [$candidateId];
        $visited = [];

        while ($frontier !== []) {
            $frontier = array_values(array_unique(array_map('strtolower', $frontier)));
            if (in_array(strtolower($parentId), $frontier, true)) {
                ApiError::abort(422, 'SKU_COMPONENT_CYCLE', 'Quan hệ component tạo chu trình.', [
                    'parent_product_id' => $parentId,
                    'component_product_id' => $candidateId,
                ]);
            }

            foreach ($frontier as $id) {
                $visited[$id] = true;
            }
            $frontier = DB::table('sku_component')
                ->whereIn('parent_product_id', $frontier)
                ->where('status', 'ACTIVE')
                ->pluck('component_product_id')
                ->map(fn (mixed $id): string => strtolower((string) $id))
                ->reject(fn (string $id): bool => isset($visited[$id]))
                ->values()->all();
        }
    }

    private function replacePhysicalCodes(Product $product, array $codes): void
    {
        $normalized = [];
        foreach ($codes as $index => $code) {
            $value = $this->normalizePhysicalCode((array) $code, $index);
            if (isset($normalized[$value['code_value']])) {
                ApiError::abort(422, 'DUPLICATE_PHYSICAL_CODE', 'Physical code bị trùng trong payload.');
            }
            $normalized[$value['code_value']] = $value;
        }

        $current = DB::table('physical_code')->where('product_id', $product->id)->lockForUpdate()->get();
        foreach ($current as $row) {
            if (! isset($normalized[strtoupper((string) $row->code_value)])) {
                DB::table('physical_code')->where('physical_code_id', $row->physical_code_id)->update([
                    'status' => 'REVOKED',
                    'product_id' => null,
                    'assigned_object_type' => null,
                    'assigned_object_id' => null,
                    'version' => ((int) $row->version) + 1,
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ($normalized as $code) {
            $this->upsertPhysicalCode($product, $code);
        }
    }

    private function upsertPhysicalCode(Product $product, array $code): void
    {
        $code = $this->normalizePhysicalCode($code);
        $existing = DB::table('physical_code')->where('code_value', $code['code_value'])->lockForUpdate()->first();

        if ($existing !== null) {
            $productConflict = $existing->product_id !== null
                && (string) $existing->product_id !== (string) $product->id;
            $genericConflict = $existing->assigned_object_id !== null
                && (string) $existing->assigned_object_id !== (string) $product->id;
            $typeConflict = $existing->assigned_object_id !== null
                && strtoupper((string) $existing->assigned_object_type) !== 'SKU';

            if ($productConflict || $genericConflict || $typeConflict) {
                ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã được gán cho object khác.', [
                    'code_value' => $code['code_value'],
                    'product_id' => $existing->product_id,
                    'assigned_object_type' => $existing->assigned_object_type,
                    'assigned_object_id' => $existing->assigned_object_id,
                ]);
            }

            DB::table('physical_code')->where('physical_code_id', $existing->physical_code_id)->update([
                'product_id' => (string) $product->id,
                'code_type' => $code['code_type'],
                'carrier_type' => $code['carrier_type'],
                'assigned_object_type' => 'SKU',
                'assigned_object_id' => (string) $product->id,
                'status' => $code['status'],
                'version' => ((int) $existing->version) + 1,
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('physical_code')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'product_id' => (string) $product->id,
            'code_value' => $code['code_value'],
            'code_type' => $code['code_type'],
            'carrier_type' => $code['carrier_type'],
            'assigned_object_type' => 'SKU',
            'assigned_object_id' => (string) $product->id,
            'status' => $code['status'],
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizePhysicalCode(array $code, ?int $index = null): array
    {
        $prefix = $index === null ? 'physical_code' : "physical_codes.{$index}";
        $value = strtoupper(trim((string) ($code['code_value'] ?? '')));
        $type = strtoupper(trim((string) ($code['code_type'] ?? 'BARCODE')));
        $carrier = strtoupper(trim((string) ($code['carrier_type'] ?? 'UNIT')));
        $status = strtoupper(trim((string) ($code['status'] ?? 'ACTIVE')));

        if ($value === '' || mb_strlen($value) > 150) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE', "{$prefix}.code_value không hợp lệ.");
        }
        if (! in_array($type, self::CODE_TYPES, true)) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE_TYPE', "{$prefix}.code_type không hợp lệ.");
        }
        if (! in_array($carrier, self::CARRIER_TYPES, true)) {
            ApiError::abort(422, 'INVALID_CARRIER_TYPE', "{$prefix}.carrier_type không hợp lệ.");
        }
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            ApiError::abort(422, 'INVALID_PHYSICAL_CODE_STATUS', "{$prefix}.status không hợp lệ.");
        }

        return ['code_value' => $value, 'code_type' => $type, 'carrier_type' => $carrier, 'status' => $status];
    }

    private function normalizeSku(array $attributes): array
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
        foreach (['package_variant', 'sku_type', 'status'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = strtoupper($attributes[$field]);
            }
        }
        if (! isset($attributes['code']) && isset($attributes['sku_code'])) {
            $attributes['code'] = $attributes['sku_code'];
        }
        if (! isset($attributes['sku_code']) && isset($attributes['code'])) {
            $attributes['sku_code'] = $attributes['code'];
        }

        return $attributes;
    }

    private function assertCodesAvailable(array $attributes, ?string $ignoreId = null): void
    {
        foreach (['code', 'sku_code'] as $field) {
            if (! isset($attributes[$field])) {
                continue;
            }
            $query = Product::query()->where($field, $attributes[$field])->lockForUpdate();
            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }
            if ($query->exists()) {
                ApiError::abort(409, 'SKU_CODE_DUPLICATE', "{$field} đã tồn tại.", [$field => $attributes[$field]]);
            }
        }
    }

    private function lockProduct(string $productId): Product
    {
        /** @var Product|null $product */
        $product = Product::query()->lockForUpdate()->find($productId);
        if ($product === null) {
            ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
        }

        return $product;
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            ApiError::abort(409, 'SKU_VERSION_CONFLICT', 'Dữ liệu đã thay đổi bởi request khác.', [
                'expected_version' => $expected,
                'actual_version' => $actual,
            ]);
        }
    }

    private function audit(
        string $action,
        Product $product,
        ?string $actorId,
        ?string $requestId,
        array $before,
        array $after
    ): void {
        AuditLogger::record(
            $action,
            'sku',
            (string) $product->id,
            $actorId,
            $requestId,
            beforeData: $before,
            afterData: $after,
            reason: 'WWHN-172 aggregate transaction'
        );
    }
}
