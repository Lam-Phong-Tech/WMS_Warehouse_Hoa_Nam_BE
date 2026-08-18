<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;

/**
 * WWHN-172 SKU aggregate transaction boundary.
 *
 * `sku` / Sku is the canonical SKU source of truth. The WWHN extension tables
 * deliberately retain legacy FK column names such as parent_product_id and
 * product_id, but those FKs point to sku.sku_id.
 */
class SkuAggregateService
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    private const SKU_FIELDS = [
        'code', 'sku_code', 'name', 'sku_name', 'unit', 'sku_type',
        'brand_code', 'category_code', 'model_code', 'power_or_voltage',
        'package_variant', 'published_to_app', 'is_serial_tracked', 'status',
    ];

    private const CODE_TYPES = ['BARCODE', 'QR', 'RFID', 'SERIAL'];

    // ERD bảng 21 / DR-CARRIER-001: carrier hợp lệ là BARCODE/QR/RFID/NFC/MANUAL
    // (enum UNIT/PACKAGE/LABEL/TAG cũ đã bị baseline thay thế; DB CHECK enforce).
    private const CARRIER_TYPES = ['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'];

    public function create(array $payload, ?string $actorId, ?string $requestId): array
    {
        return DB::transaction(function () use ($payload, $actorId, $requestId): array {
            $attributes = $this->normalizeSku(Arr::only($payload, self::SKU_FIELDS));
            $attributes['sku_type'] = $attributes['sku_type'] ?? 'PRODUCT';
            $attributes['status'] = $attributes['status'] ?? 'ACTIVE';
            $attributes['version_code'] = 'V1';
            $this->assertSkuCodeAvailable((string) $attributes['sku_code']);

            /** @var Sku $sku */
            $sku = Sku::query()->create($attributes);
            $this->replaceComponents($sku, (array) ($payload['components'] ?? []));
            $this->replacePhysicalCodes($sku, (array) ($payload['physical_codes'] ?? []));

            // Ghi qua Eloquent trực tiếp, không qua ProductRepository::create() — tự
            // invalidate để GET/list SKU không trả cũ sau khi tạo qua endpoint này
            // (mục 7).
            $this->products->forgetCache((string) $sku->getKey());

            $after = $this->snapshot((string) $sku->getKey(), true);
            $this->audit('SKU_CREATE', $sku, $actorId, $requestId, [], $after);

            return $after;
        }, 3);
    }

    public function update(
        string $skuId,
        array $payload,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($skuId, $payload, $expectedVersion, $actorId, $requestId): array {
            $sku = $this->lockSku($skuId);
            $actualVersion = $this->versionNumber($sku);
            $this->assertVersion($actualVersion, $expectedVersion);
            $before = $this->snapshot($skuId, true);

            $attributes = $this->normalizeSku(Arr::only($payload, self::SKU_FIELDS), false);
            if (isset($attributes['sku_code'])) {
                $this->assertSkuCodeAvailable((string) $attributes['sku_code'], $skuId);
            }
            $sku->fill($attributes);

            if (array_key_exists('components', $payload)) {
                $this->replaceComponents($sku, (array) $payload['components']);
            }
            if (array_key_exists('physical_codes', $payload)) {
                $this->replacePhysicalCodes($sku, (array) $payload['physical_codes']);
            }

            $sku->version_code = 'V'.($actualVersion + 1);
            $sku->save();
            $this->products->forgetCache($skuId);

            $after = $this->snapshot($skuId, true);
            $this->audit('SKU_UPDATE', $sku, $actorId, $requestId, $before, $after);

            return $after;
        }, 3);
    }

    public function addPhysicalCode(
        string $skuId,
        array $physicalCode,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($skuId, $physicalCode, $expectedVersion, $actorId, $requestId): array {
            $sku = $this->lockSku($skuId);
            $actualVersion = $this->versionNumber($sku);
            $this->assertVersion($actualVersion, $expectedVersion);
            $before = $this->snapshot($skuId, true);

            $this->upsertPhysicalCode($sku, $physicalCode);
            $sku->version_code = 'V'.($actualVersion + 1);
            $sku->save();
            $this->products->forgetCache($skuId);

            $after = $this->snapshot($skuId, true);
            $this->audit('SKU_PHYSICAL_CODE_ASSIGN', $sku, $actorId, $requestId, $before, $after);

            return $after;
        }, 3);
    }

    public function revokePhysicalCode(
        string $skuId,
        string $physicalCodeId,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($skuId, $physicalCodeId, $expectedVersion, $actorId, $requestId): array {
            $sku = $this->lockSku($skuId);
            $actualVersion = $this->versionNumber($sku);
            $this->assertVersion($actualVersion, $expectedVersion);
            $before = $this->snapshot($skuId, true);

            $code = DB::table('physical_code')
                ->where('physical_code_id', $physicalCodeId)
                ->lockForUpdate()
                ->first();
            if ($code === null || (string) $code->product_id !== $skuId) {
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

            $sku->version_code = 'V'.($actualVersion + 1);
            $sku->save();
            $this->products->forgetCache($skuId);

            $after = $this->snapshot($skuId, true);
            $this->audit('SKU_PHYSICAL_CODE_REVOKE', $sku, $actorId, $requestId, $before, $after);

            return $after;
        }, 3);
    }

    public function snapshot(string $skuId, bool $failIfMissing = false): array
    {
        /** @var Sku|null $sku */
        $sku = Sku::query()->find($skuId);
        if ($sku === null) {
            if ($failIfMissing) {
                ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
            }

            return [];
        }

        $components = DB::table('sku_component as sc')
            ->join('sku as s', 's.sku_id', '=', 'sc.component_product_id')
            ->where('sc.parent_product_id', $skuId)
            ->where('sc.status', 'ACTIVE')
            ->orderBy('s.sku_code')
            ->get([
                'sc.sku_component_id', 'sc.component_product_id',
                's.sku_code as component_code', 's.sku_code as component_sku_code',
                's.sku_name as component_name', 'sc.quantity', 'sc.version',
            ])->map(fn (object $row): array => (array) $row)->all();

        $physicalCodes = DB::table('physical_code')
            ->where('product_id', $skuId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('code_value')
            ->get([
                'physical_code_id', 'code_value', 'code_type', 'carrier_type',
                'status', 'version', 'created_at', 'updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();

        $data = $sku->toArray();
        $data['id'] = (string) $sku->getKey();
        $data['code'] = (string) $sku->sku_code;
        $data['name'] = (string) $sku->sku_name;
        $data['version'] = $this->versionNumber($sku);
        $data['components'] = $components;
        $data['physical_codes'] = $physicalCodes;

        return $data;
    }

    private function replaceComponents(Sku $sku, array $components): void
    {
        if (strtoupper((string) $sku->sku_type) === 'COMPONENT' && $components !== []) {
            ApiError::abort(422, 'COMPONENT_CANNOT_HAVE_CHILDREN', 'SKU loại COMPONENT không được có component con.');
        }

        $skuId = (string) $sku->getKey();
        DB::table('sku_component')->where('parent_product_id', $skuId)->lockForUpdate()->get();
        DB::table('sku_component')->where('parent_product_id', $skuId)->delete();

        $seen = [];
        foreach ($components as $index => $component) {
            $componentId = strtolower(trim((string) ($component['component_id'] ?? '')));
            $quantity = $component['quantity'] ?? null;

            if (! Str::isUuid($componentId)) {
                ApiError::abort(422, 'INVALID_COMPONENT', "components.{$index}.component_id không hợp lệ.");
            }
            if ($componentId === strtolower($skuId)) {
                ApiError::abort(422, 'SKU_COMPONENT_SELF_REFERENCE', 'SKU không thể là component của chính nó.');
            }
            if (isset($seen[$componentId])) {
                ApiError::abort(422, 'DUPLICATE_COMPONENT', 'Danh sách component có phần tử trùng.');
            }
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                ApiError::abort(422, 'INVALID_COMPONENT_QUANTITY', 'Số lượng component phải lớn hơn 0.');
            }

            $componentSku = Sku::query()->lockForUpdate()->find($componentId);
            if ($componentSku === null || strtoupper((string) $componentSku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'COMPONENT_NOT_ACTIVE', 'Component không tồn tại hoặc không ACTIVE.', [
                    'component_id' => $componentId,
                ]);
            }

            $this->assertNoCycle($skuId, $componentId);
            DB::table('sku_component')->insert([
                'sku_component_id' => (string) Str::uuid(),
                'parent_product_id' => $skuId,
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

    private function replacePhysicalCodes(Sku $sku, array $codes): void
    {
        $normalized = [];
        foreach ($codes as $index => $code) {
            $value = $this->normalizePhysicalCode((array) $code, $index);
            if (isset($normalized[$value['code_value']])) {
                ApiError::abort(422, 'DUPLICATE_PHYSICAL_CODE', 'Physical code bị trùng trong payload.');
            }
            $normalized[$value['code_value']] = $value;
        }

        $skuId = (string) $sku->getKey();
        $current = DB::table('physical_code')->where('product_id', $skuId)->lockForUpdate()->get();
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
            $this->upsertPhysicalCode($sku, $code);
        }
    }

    private function upsertPhysicalCode(Sku $sku, array $code): void
    {
        $code = $this->normalizePhysicalCode($code);
        $skuId = (string) $sku->getKey();
        $existing = DB::table('physical_code')->where('code_value', $code['code_value'])->lockForUpdate()->first();

        if ($existing !== null) {
            $productConflict = $existing->product_id !== null && (string) $existing->product_id !== $skuId;
            $genericConflict = $existing->assigned_object_id !== null && (string) $existing->assigned_object_id !== $skuId;
            $typeConflict = $existing->assigned_object_id !== null && strtoupper((string) $existing->assigned_object_type) !== 'SKU';

            if ($productConflict || $genericConflict || $typeConflict) {
                ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã được gán cho object khác.', [
                    'code_value' => $code['code_value'],
                    'product_id' => $existing->product_id,
                    'assigned_object_type' => $existing->assigned_object_type,
                    'assigned_object_id' => $existing->assigned_object_id,
                ]);
            }

            DB::table('physical_code')->where('physical_code_id', $existing->physical_code_id)->update([
                'product_id' => $skuId,
                'code_type' => $code['code_type'],
                'carrier_type' => $code['carrier_type'],
                'assigned_object_type' => 'SKU',
                'assigned_object_id' => $skuId,
                'status' => $code['status'],
                'version' => ((int) $existing->version) + 1,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('physical_code')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'product_id' => $skuId,
            'code_value' => $code['code_value'],
            'code_type' => $code['code_type'],
            'carrier_type' => $code['carrier_type'],
            'assigned_object_type' => 'SKU',
            'assigned_object_id' => $skuId,
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
        $carrier = strtoupper(trim((string) ($code['carrier_type'] ?? 'BARCODE')));
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

    private function normalizeSku(array $attributes, bool $requireCodeAndName = true): array
    {
        $code = $attributes['sku_code'] ?? $attributes['code'] ?? null;
        $name = $attributes['sku_name'] ?? $attributes['name'] ?? null;

        if ($code !== null) {
            $code = strtoupper(trim((string) $code));
            if ($code === '' || mb_strlen($code) > 100) {
                ApiError::abort(422, 'INVALID_SKU_CODE', 'sku_code không hợp lệ.');
            }
            $attributes['sku_code'] = $code;
        } elseif ($requireCodeAndName) {
            ApiError::abort(422, 'SKU_CODE_REQUIRED', 'code hoặc sku_code là bắt buộc.');
        }

        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 255) {
                ApiError::abort(422, 'INVALID_SKU_NAME', 'sku_name không hợp lệ.');
            }
            $attributes['sku_name'] = $name;
        } elseif ($requireCodeAndName) {
            ApiError::abort(422, 'SKU_NAME_REQUIRED', 'name hoặc sku_name là bắt buộc.');
        }

        unset($attributes['code'], $attributes['name'], $attributes['brand_code'], $attributes['category_code'], $attributes['model_code']);

        foreach (['unit', 'power_or_voltage', 'package_variant', 'sku_type', 'status'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = trim((string) $attributes[$field]);
            }
        }
        foreach (['package_variant', 'sku_type', 'status'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = strtoupper((string) $attributes[$field]);
            }
        }

        return $attributes;
    }

    private function assertSkuCodeAvailable(string $code, ?string $ignoreId = null): void
    {
        $query = Sku::query()->where('sku_code', $code)->lockForUpdate();
        if ($ignoreId !== null) {
            $query->where('sku_id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            ApiError::abort(409, 'SKU_CODE_DUPLICATE', 'sku_code đã tồn tại.', ['sku_code' => $code]);
        }
    }

    private function lockSku(string $skuId): Sku
    {
        /** @var Sku|null $sku */
        $sku = Sku::query()->lockForUpdate()->find($skuId);
        if ($sku === null) {
            ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
        }

        return $sku;
    }

    private function versionNumber(Sku $sku): int
    {
        $raw = strtoupper(trim((string) $sku->version_code));
        if (preg_match('/^V?(\d+)$/', $raw, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 1;
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
        Sku $sku,
        ?string $actorId,
        ?string $requestId,
        array $before,
        array $after
    ): void {
        AuditLogger::record(
            $action,
            'sku',
            (string) $sku->getKey(),
            $actorId,
            $requestId,
            beforeData: $before,
            afterData: $after,
            reason: 'WWHN-172 aggregate transaction'
        );
    }
}
