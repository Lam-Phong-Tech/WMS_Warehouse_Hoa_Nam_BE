<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Product;

/**
 * Transaction boundary and invariant guard for container + container_item.
 */
class ContainerService
{
    /** @var array<int, string> */
    private const TYPES = ['PALLET', 'CARTON', 'BOX', 'TRAY', 'BIN', 'BAG', 'OTHER'];

    /** @var array<int, string> */
    private const MUTABLE_STATUSES = ['ACTIVE', 'INACTIVE'];

    public function create(array $payload, ?string $actorId, ?string $requestId): array
    {
        return DB::transaction(function () use ($payload, $actorId, $requestId): array {
            $containerId = (string) Str::uuid();
            $attributes = $this->normalizeContainer($payload);
            $attributes['container_id'] = $containerId;
            $attributes['status'] = $attributes['status'] ?? 'ACTIVE';
            $attributes['version'] = 1;
            $attributes['sealed_at'] = null;
            $attributes['created_at'] = now();
            $attributes['updated_at'] = now();

            $this->assertContainerCodeAvailable($attributes['container_code']);
            $this->assertParentValid($containerId, $attributes['parent_container_id'] ?? null, $attributes['warehouse_id'] ?? null);
            $this->assertPrimaryProduct($attributes['primary_product_id'] ?? null);

            DB::table('container')->insert($attributes);
            $this->bindPhysicalCode($containerId, $attributes['physical_code_id'] ?? null);
            $this->replaceItems($containerId, (array) ($payload['items'] ?? []));
            $this->assertPrimaryProductMatchesItems($containerId, $attributes['primary_product_id'] ?? null);

            $after = $this->snapshot($containerId, true);
            AuditLogger::record(
                'CONTAINER_CREATE',
                'container',
                $containerId,
                $actorId,
                $requestId,
                afterData: $after,
                reason: 'WWHN-172 aggregate create'
            );

            return $after;
        }, 3);
    }

    public function update(
        string $containerId,
        array $payload,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($containerId, $payload, $expectedVersion, $actorId, $requestId): array {
            $container = DB::table('container')
                ->where('container_id', $containerId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($container === null) {
                ApiError::abort(404, 'CONTAINER_NOT_FOUND', 'Không tìm thấy container.');
            }

            $this->assertExpectedVersion((int) $container->version, $expectedVersion);
            if (! in_array(strtoupper((string) $container->status), self::MUTABLE_STATUSES, true)) {
                ApiError::abort(409, 'CONTAINER_IMMUTABLE', 'Container SEALED/CLOSED không được chỉnh sửa.', [
                    'status' => $container->status,
                ]);
            }

            $before = $this->snapshot($containerId, true);
            $oldPhysicalCodeId = $container->physical_code_id;
            $attributes = $this->normalizeContainer($payload, true);

            if (isset($attributes['container_code'])) {
                $this->assertContainerCodeAvailable($attributes['container_code'], $containerId);
            }

            $parentId = array_key_exists('parent_container_id', $attributes)
                ? $attributes['parent_container_id']
                : $container->parent_container_id;
            $warehouseId = array_key_exists('warehouse_id', $attributes)
                ? $attributes['warehouse_id']
                : $container->warehouse_id;

            $this->assertParentValid($containerId, $parentId, $warehouseId);
            if (array_key_exists('primary_product_id', $attributes)) {
                $this->assertPrimaryProduct($attributes['primary_product_id']);
            }

            $attributes['version'] = ((int) $container->version) + 1;
            $attributes['updated_at'] = now();
            DB::table('container')->where('container_id', $containerId)->update($attributes);

            if (array_key_exists('physical_code_id', $attributes)) {
                $this->unbindPhysicalCode($containerId, $oldPhysicalCodeId, $attributes['physical_code_id']);
                $this->bindPhysicalCode($containerId, $attributes['physical_code_id']);
            }

            if (array_key_exists('items', $payload)) {
                $this->replaceItems($containerId, (array) $payload['items']);
            }

            $currentPrimaryProduct = array_key_exists('primary_product_id', $attributes)
                ? $attributes['primary_product_id']
                : $container->primary_product_id;
            $this->assertPrimaryProductMatchesItems($containerId, $currentPrimaryProduct);

            $after = $this->snapshot($containerId, true);
            AuditLogger::record(
                'CONTAINER_UPDATE',
                'container',
                $containerId,
                $actorId,
                $requestId,
                beforeData: $before,
                afterData: $after,
                reason: 'WWHN-172 optimistic aggregate update'
            );

            return $after;
        }, 3);
    }

    public function seal(
        string $containerId,
        int $expectedVersion,
        ?string $actorId,
        ?string $requestId
    ): array {
        return DB::transaction(function () use ($containerId, $expectedVersion, $actorId, $requestId): array {
            $container = DB::table('container')
                ->where('container_id', $containerId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($container === null) {
                ApiError::abort(404, 'CONTAINER_NOT_FOUND', 'Không tìm thấy container.');
            }

            $this->assertExpectedVersion((int) $container->version, $expectedVersion);
            if (strtoupper((string) $container->status) !== 'ACTIVE') {
                ApiError::abort(409, 'CONTAINER_STATE_CONFLICT', 'Chỉ container ACTIVE mới được seal.', [
                    'status' => $container->status,
                ]);
            }

            // Lock concrete rows rather than combining FOR UPDATE with COUNT().
            // PostgreSQL rejects row locks on aggregate queries; ordering also
            // preserves the deterministic aggregate lock order across engines.
            $activeItemIds = DB::table('container_item')
                ->where('container_id', $containerId)
                ->where('status', 'ACTIVE')
                ->orderBy('container_item_id')
                ->lockForUpdate()
                ->pluck('container_item_id');
            if ($activeItemIds->isEmpty()) {
                ApiError::abort(422, 'EMPTY_CONTAINER_CANNOT_SEAL', 'Container rỗng không được seal.');
            }

            $before = $this->snapshot($containerId, true);
            DB::table('container')->where('container_id', $containerId)->update([
                'status' => 'SEALED',
                'sealed_at' => now(),
                'version' => ((int) $container->version) + 1,
                'updated_at' => now(),
            ]);

            $after = $this->snapshot($containerId, true);
            AuditLogger::record(
                'CONTAINER_SEAL',
                'container',
                $containerId,
                $actorId,
                $requestId,
                beforeData: $before,
                afterData: $after,
                reason: 'WWHN-172 immutable seal transition'
            );

            return $after;
        }, 3);
    }

    public function snapshot(string $containerId, bool $failIfMissing = false): array
    {
        $container = DB::table('container')
            ->where('container_id', $containerId)
            ->whereNull('deleted_at')
            ->first();

        if ($container === null) {
            if ($failIfMissing) {
                ApiError::abort(404, 'CONTAINER_NOT_FOUND', 'Không tìm thấy container.');
            }

            return [];
        }

        $items = DB::table('container_item as ci')
            ->leftJoin('products as p', 'p.id', '=', 'ci.product_id')
            ->leftJoin('inventory_serials as s', 's.id', '=', 'ci.inventory_serial_id')
            ->where('ci.container_id', $containerId)
            ->where('ci.status', 'ACTIVE')
            ->orderBy('ci.item_key')
            ->get([
                'ci.container_item_id', 'ci.item_key', 'ci.inventory_serial_id',
                'ci.product_id', 'p.code as product_code', 'p.name as product_name',
                's.serial_no', 'ci.quantity', 'ci.status', 'ci.version',
                'ci.created_at', 'ci.updated_at',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return array_merge((array) $container, ['items' => $items]);
    }

    private function replaceItems(string $containerId, array $items): void
    {
        $container = DB::table('container')->where('container_id', $containerId)->lockForUpdate()->first();
        if ($container === null) {
            ApiError::abort(404, 'CONTAINER_NOT_FOUND', 'Không tìm thấy container.');
        }

        DB::table('container_item')
            ->where('container_id', $containerId)
            ->lockForUpdate()
            ->get();

        DB::table('container_item')->where('container_id', $containerId)->delete();

        $seen = [];
        foreach ($items as $index => $item) {
            $serialId = $this->nullableUuid($item['inventory_serial_id'] ?? null, "items.{$index}.inventory_serial_id");
            $productId = $this->nullableUuid($item['product_id'] ?? null, "items.{$index}.product_id");
            $quantity = $item['quantity'] ?? null;

            if ($serialId === null && $productId === null) {
                ApiError::abort(422, 'CONTAINER_ITEM_TARGET_REQUIRED', "items.{$index} phải có inventory_serial_id hoặc product_id.");
            }

            if ($serialId !== null) {
                $serial = DB::table('inventory_serials')->where('id', $serialId)->lockForUpdate()->first();
                if ($serial === null) {
                    ApiError::abort(422, 'INVENTORY_SERIAL_NOT_FOUND', 'Inventory serial không tồn tại.', [
                        'inventory_serial_id' => $serialId,
                    ]);
                }
                if (! in_array(strtoupper((string) $serial->status), ['IN_STOCK', 'IN_TRANSIT'], true)) {
                    ApiError::abort(409, 'SERIAL_NOT_CONTAINERIZABLE', 'Serial không ở trạng thái có thể đưa vào container.', [
                        'inventory_serial_id' => $serialId,
                        'status' => $serial->status,
                        'allowed_statuses' => ['IN_STOCK', 'IN_TRANSIT'],
                    ]);
                }
                if ($container->warehouse_id !== null
                    && $serial->warehouse_id !== null
                    && (string) $container->warehouse_id !== (string) $serial->warehouse_id) {
                    ApiError::abort(422, 'SERIAL_WAREHOUSE_MISMATCH', 'Serial và container phải cùng warehouse.', [
                        'inventory_serial_id' => $serialId,
                        'serial_warehouse_id' => $serial->warehouse_id,
                        'container_warehouse_id' => $container->warehouse_id,
                    ]);
                }

                if ($productId !== null && (string) $serial->product_id !== $productId) {
                    ApiError::abort(422, 'SERIAL_PRODUCT_MISMATCH', 'inventory_serial_id không thuộc product_id đã gửi.');
                }

                $productId = (string) $serial->product_id;
                $quantity = $quantity ?? 1;
                if ((float) $quantity !== 1.0) {
                    ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Item theo serial phải có quantity = 1.');
                }

                $conflict = DB::table('container_item')
                    ->where('inventory_serial_id', $serialId)
                    ->where('status', 'ACTIVE')
                    ->where('container_id', '!=', $containerId)
                    ->lockForUpdate()
                    ->first(['container_id']);
                if ($conflict !== null) {
                    ApiError::abort(409, 'SERIAL_ALREADY_CONTAINERIZED', 'Serial đang thuộc container khác.', [
                        'inventory_serial_id' => $serialId,
                        'container_id' => $conflict->container_id,
                    ]);
                }

                $itemKey = 'SERIAL:'.strtolower($serialId);
            } else {
                $this->assertProductActive($productId);
                if (! is_numeric($quantity) || (float) $quantity <= 0) {
                    ApiError::abort(422, 'INVALID_CONTAINER_QUANTITY', 'Bulk item phải có quantity > 0.', [
                        'product_id' => $productId,
                    ]);
                }
                $itemKey = 'PRODUCT:'.strtolower($productId);
            }

            if (isset($seen[$itemKey])) {
                ApiError::abort(422, 'DUPLICATE_CONTAINER_ITEM', 'Item bị trùng trong container payload.', [
                    'item_key' => $itemKey,
                ]);
            }

            DB::table('container_item')->insert([
                'container_item_id' => (string) Str::uuid(),
                'container_id' => $containerId,
                'item_key' => $itemKey,
                'inventory_serial_id' => $serialId,
                'product_id' => $productId,
                'quantity' => (string) $quantity,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $seen[$itemKey] = true;
        }
    }

    private function normalizeContainer(array $payload, bool $partial = false): array
    {
        $allowed = [
            'container_code', 'container_type', 'parent_container_id', 'warehouse_id',
            'primary_product_id', 'zone_code', 'location_hint', 'status', 'physical_code_id',
        ];
        $attributes = Arr::only($payload, $allowed);

        foreach (['container_code', 'container_type', 'zone_code', 'status'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = strtoupper(trim((string) $attributes[$field]));
            }
        }
        if (array_key_exists('location_hint', $attributes) && $attributes['location_hint'] !== null) {
            $attributes['location_hint'] = trim((string) $attributes['location_hint']);
        }

        if (! $partial || array_key_exists('container_code', $attributes)) {
            if (($attributes['container_code'] ?? '') === '' || mb_strlen($attributes['container_code']) > 150) {
                ApiError::abort(422, 'INVALID_CONTAINER_CODE', 'container_code không hợp lệ.');
            }
        }
        if (! $partial || array_key_exists('container_type', $attributes)) {
            if (! in_array($attributes['container_type'] ?? '', self::TYPES, true)) {
                ApiError::abort(422, 'INVALID_CONTAINER_TYPE', 'container_type không hợp lệ.', [
                    'allowed' => self::TYPES,
                ]);
            }
        }
        if (array_key_exists('status', $attributes)
            && ! in_array($attributes['status'], self::MUTABLE_STATUSES, true)) {
            ApiError::abort(422, 'INVALID_CONTAINER_STATUS', 'API create/update chỉ chấp nhận ACTIVE hoặc INACTIVE.');
        }

        foreach (['parent_container_id', 'warehouse_id', 'primary_product_id', 'physical_code_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->nullableUuid($attributes[$field], $field);
            }
        }

        return $attributes;
    }

    private function assertParentValid(string $containerId, ?string $parentId, ?string $warehouseId): void
    {
        if ($parentId === null) {
            return;
        }
        if (strtolower($containerId) === strtolower($parentId)) {
            ApiError::abort(422, 'CONTAINER_SELF_PARENT', 'Container không thể là parent của chính nó.');
        }

        $parent = DB::table('container')
            ->where('container_id', $parentId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if ($parent === null) {
            ApiError::abort(422, 'PARENT_CONTAINER_NOT_FOUND', 'Parent container không tồn tại.');
        }
        if (in_array(strtoupper((string) $parent->status), ['CLOSED', 'INACTIVE'], true)) {
            ApiError::abort(409, 'PARENT_CONTAINER_NOT_AVAILABLE', 'Parent container không khả dụng.', [
                'status' => $parent->status,
            ]);
        }
        if ($warehouseId !== null && $parent->warehouse_id !== null && (string) $parent->warehouse_id !== $warehouseId) {
            ApiError::abort(422, 'CONTAINER_WAREHOUSE_MISMATCH', 'Container con và parent phải cùng warehouse.');
        }

        $frontier = [$parentId];
        $visited = [];
        while ($frontier !== []) {
            $normalized = array_values(array_unique(array_map('strtolower', $frontier)));
            if (in_array(strtolower($containerId), $normalized, true)) {
                ApiError::abort(422, 'CONTAINER_PARENT_CYCLE', 'Quan hệ parent container tạo chu trình.');
            }

            $next = DB::table('container')
                ->whereIn('container_id', $normalized)
                ->whereNotNull('parent_container_id')
                ->pluck('parent_container_id')
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

    private function bindPhysicalCode(string $containerId, ?string $physicalCodeId): void
    {
        if ($physicalCodeId === null) {
            return;
        }

        $code = DB::table('physical_code')
            ->where('physical_code_id', $physicalCodeId)
            ->lockForUpdate()
            ->first();
        if ($code === null || strtoupper((string) $code->status) !== 'ACTIVE') {
            ApiError::abort(422, 'PHYSICAL_CODE_NOT_ACTIVE', 'Physical code của container không tồn tại hoặc không ACTIVE.');
        }

        $otherContainer = DB::table('container')
            ->where('physical_code_id', $physicalCodeId)
            ->where('container_id', '!=', $containerId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['container_id']);
        if ($otherContainer !== null) {
            ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã được gán cho container khác.', [
                'physical_code_id' => $physicalCodeId,
                'container_id' => $otherContainer->container_id,
            ]);
        }

        $productConflict = $code->product_id !== null;
        $genericConflict = $code->assigned_object_id !== null
            && (string) $code->assigned_object_id !== $containerId;
        $typeConflict = $code->assigned_object_id !== null
            && strtoupper((string) $code->assigned_object_type) !== 'CONTAINER';

        if ($productConflict || $genericConflict || $typeConflict) {
            ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã được gán cho object khác.', [
                'physical_code_id' => $physicalCodeId,
                'product_id' => $code->product_id,
                'assigned_object_type' => $code->assigned_object_type,
                'assigned_object_id' => $code->assigned_object_id,
            ]);
        }

        DB::table('physical_code')->where('physical_code_id', $physicalCodeId)->update([
            'product_id' => null,
            'assigned_object_type' => 'CONTAINER',
            'assigned_object_id' => $containerId,
            'version' => ((int) $code->version) + 1,
            'updated_at' => now(),
        ]);
    }

    private function unbindPhysicalCode(string $containerId, ?string $oldPhysicalCodeId, ?string $newPhysicalCodeId): void
    {
        if ($oldPhysicalCodeId === null || $oldPhysicalCodeId === $newPhysicalCodeId) {
            return;
        }

        $old = DB::table('physical_code')
            ->where('physical_code_id', $oldPhysicalCodeId)
            ->lockForUpdate()
            ->first();
        if ($old === null || (string) $old->assigned_object_id !== $containerId) {
            return;
        }

        DB::table('physical_code')->where('physical_code_id', $oldPhysicalCodeId)->update([
            'assigned_object_type' => null,
            'assigned_object_id' => null,
            'version' => ((int) $old->version) + 1,
            'updated_at' => now(),
        ]);
    }

    private function assertPrimaryProduct(?string $productId): void
    {
        if ($productId !== null) {
            $this->assertProductActive($productId);
        }
    }

    private function assertProductActive(?string $productId): void
    {
        if ($productId === null) {
            ApiError::abort(422, 'PRODUCT_REQUIRED', 'product_id là bắt buộc.');
        }

        /** @var Product|null $product */
        $product = Product::query()->lockForUpdate()->find($productId);
        if ($product === null || strtoupper((string) $product->status) !== 'ACTIVE') {
            ApiError::abort(422, 'PRODUCT_NOT_ACTIVE', 'Product không tồn tại hoặc không ACTIVE.', [
                'product_id' => $productId,
            ]);
        }
    }

    private function assertPrimaryProductMatchesItems(string $containerId, ?string $primaryProductId): void
    {
        if ($primaryProductId === null) {
            return;
        }

        $foreignProduct = DB::table('container_item')
            ->where('container_id', $containerId)
            ->where('status', 'ACTIVE')
            ->whereNotNull('product_id')
            ->where('product_id', '!=', $primaryProductId)
            ->first(['product_id']);

        if ($foreignProduct !== null) {
            ApiError::abort(422, 'PRIMARY_PRODUCT_MISMATCH', 'Container có item không thuộc primary_product_id.', [
                'primary_product_id' => $primaryProductId,
                'conflicting_product_id' => $foreignProduct->product_id,
            ]);
        }
    }

    private function assertContainerCodeAvailable(string $code, ?string $ignoreId = null): void
    {
        $query = DB::table('container')->where('container_code', $code)->whereNull('deleted_at')->lockForUpdate();
        if ($ignoreId !== null) {
            $query->where('container_id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            ApiError::abort(409, 'CONTAINER_CODE_DUPLICATE', 'container_code đã tồn tại.', [
                'container_code' => $code,
            ]);
        }
    }

    private function assertExpectedVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            ApiError::abort(409, 'CONTAINER_VERSION_CONFLICT', 'Container đã thay đổi bởi request khác.', [
                'expected_version' => $expected,
                'actual_version' => $actual,
            ]);
        }
    }

    private function nullableUuid(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if (! Str::isUuid($value)) {
            ApiError::abort(422, 'INVALID_UUID', "{$field} không phải UUID hợp lệ.");
        }

        return $value;
    }
}
