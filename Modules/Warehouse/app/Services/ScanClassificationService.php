<?php

namespace Modules\Warehouse\Services;

use App\Support\Database\SchemaCache;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\Warehouse;

/**
 * Read-only resolver for scan/classify.
 *
 * This service must never create scan events, posting events, inventory movements,
 * balance mutations or idempotency records. Posting remains exclusively owned by
 * the inbound/outbound/component-issue/reversal posting services.
 */
class ScanClassificationService
{
    public const CONTRACT_VERSION = 'WWHN-150.1.1.0';

    public const STOCK_EFFECT_NONE = 'NONE';

    /**
     * @return array<string, mixed>
     */
    public function classify(
        string $rawCode,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence = false
    ): array {
        $code = trim($rawCode);

        $physicalCode = (SchemaCache::hasTable('physical_code') || SchemaCache::hasView('physical_code'))
            ? DB::table('physical_code')->where('code_value', $code)->first()
            : null;

        if ($physicalCode !== null) {
            $this->assertActiveStatus(
                (string) ($physicalCode->status ?? 'ACTIVE'),
                'SCAN_CODE_INACTIVE',
                'Mã vật lý đang không hoạt động.'
            );

            $resolved = $this->resolvePhysicalCode($physicalCode, $context, $warehouse, $hideCrossWarehouseExistence);
            if ($resolved !== null) {
                return $this->withContractMetadata($resolved, 'physical_code');
            }
        }

        $item = $this->resolveInventorySerialByCode($code, $context, $warehouse, $hideCrossWarehouseExistence);
        if ($item !== null) {
            return $this->withContractMetadata($item, 'inventory_serials.serial_no');
        }

        $container = $this->resolveContainerByCode($code, $context, $warehouse, $hideCrossWarehouseExistence);
        if ($container !== null) {
            return $this->withContractMetadata($container, 'container.container_code');
        }

        $sku = $this->resolveSkuByCode($code, $warehouse);
        if ($sku !== null) {
            return $this->withContractMetadata($sku, 'products.code');
        }

        ApiError::abort(404, 'SCAN_CODE_NOT_FOUND', "Không tìm thấy mã scan \"{$code}\".");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePhysicalCode(
        object $physicalCode,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): ?array {
        $assignedType = strtoupper(trim((string) ($physicalCode->assigned_object_type ?? '')));
        $assignedId = $physicalCode->assigned_object_id ?? null;
        $physicalCodeId = (string) $physicalCode->physical_code_id;

        if (in_array($assignedType, ['ITEM', 'INVENTORY_SERIAL', 'ITEM_INSTANCE'], true)) {
            return $this->resolveItemByIdOrPhysicalCode($assignedId, $physicalCodeId, $context, $warehouse, $hideCrossWarehouseExistence);
        }

        if ($assignedType === 'CONTAINER') {
            return $this->resolveContainerByIdOrPhysicalCode($assignedId, $physicalCodeId, $context, $warehouse, $hideCrossWarehouseExistence);
        }

        if (in_array($assignedType, ['SKU', 'PRODUCT'], true)) {
            return $this->resolveSkuById($assignedId, $warehouse);
        }

        return $this->resolveItemByIdOrPhysicalCode($assignedId, $physicalCodeId, $context, $warehouse, $hideCrossWarehouseExistence)
            ?? $this->resolveContainerByIdOrPhysicalCode($assignedId, $physicalCodeId, $context, $warehouse, $hideCrossWarehouseExistence)
            ?? $this->resolveSkuById($assignedId, $warehouse);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveInventorySerialByCode(
        string $code,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): ?array {
        if (! SchemaCache::hasTable('inventory_serials')) {
            return null;
        }

        $item = DB::table('inventory_serials')->where('serial_no', $code)->first();

        return $item === null ? null : $this->inventorySerialPayload($item, $context, $warehouse, $hideCrossWarehouseExistence);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveItemByIdOrPhysicalCode(
        mixed $assignedId,
        string $physicalCodeId,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): ?array {
        if (SchemaCache::hasTable('inventory_serials')) {
            $query = DB::table('inventory_serials');
            if (is_string($assignedId) && $assignedId !== '') {
                $query->where('id', $assignedId);
            } elseif (SchemaCache::hasColumn('inventory_serials', 'physical_code_id')) {
                $query->where('physical_code_id', $physicalCodeId);
            } else {
                $query->whereRaw('1 = 0');
            }

            $item = $query->first();
            if ($item !== null) {
                return $this->inventorySerialPayload($item, $context, $warehouse, $hideCrossWarehouseExistence);
            }
        }

        // Canonical subledger là item_instances (số nhiều) — luồng Post Receipt ghi vào
        // đây (DEC-INV-001); bảng không có warehouse_id vì REQ-SRS-001 một kho ACTIVE.
        if (SchemaCache::hasTable('item_instances') || SchemaCache::hasView('item_instances')) {
            $query = DB::table('item_instances');
            if (is_string($assignedId) && $assignedId !== '') {
                $query->where('item_id', $assignedId);
            } elseif (SchemaCache::hasColumn('item_instances', 'physical_code_id')) {
                $query->where('physical_code_id', $physicalCodeId);
            } else {
                $query->whereRaw('1 = 0');
            }

            $item = $query->first();
            if ($item !== null) {
                $condition = strtoupper((string) ($item->item_condition ?? 'NORMAL'));
                $stockStatus = $condition === 'NORMAL' ? 'AVAILABLE' : $condition;
                $this->assertContextCompatible(
                    'ITEM',
                    (string) ($item->item_status ?? 'IN_STOCK'),
                    $stockStatus,
                    $context
                );

                return [
                    'classification' => 'ITEM',
                    'item_id' => (string) $item->item_id,
                    'sku_id' => $item->sku_id ?? null,
                    'product_id' => $item->sku_id ?? null,
                    'serial_no' => $item->serial_number ?? null,
                    'stock_status' => $stockStatus,
                    'object_status' => (string) ($item->item_status ?? 'IN_STOCK'),
                    'container_id' => $item->current_container_id ?? $item->container_id ?? null,
                    'location_hint' => $item->location_hint ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inventorySerialPayload(
        object $item,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): array {
        $this->assertWarehouseMatch($item->warehouse_id ?? null, $warehouse, $hideCrossWarehouseExistence);
        $status = (string) ($item->status ?? 'IN_STOCK');
        $stockStatus = (string) ($item->stock_condition ?? 'AVAILABLE');
        $this->assertContextCompatible('ITEM', $status, $stockStatus, $context);

        return [
            'classification' => 'ITEM',
            'item_id' => (string) $item->id,
            'sku_id' => $item->product_id ?? null,
            'product_id' => $item->product_id ?? null,
            'serial_no' => $item->serial_no ?? null,
            'stock_status' => $stockStatus,
            'object_status' => $status,
            'container_id' => null,
            'location_hint' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContainerByCode(
        string $code,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): ?array {
        if (! SchemaCache::hasTable('container') && ! SchemaCache::hasView('container')) {
            return null;
        }

        $container = DB::table('container')->where('container_code', $code)->first();

        return $container === null ? null : $this->containerPayload($container, $context, $warehouse, $hideCrossWarehouseExistence);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContainerByIdOrPhysicalCode(
        mixed $assignedId,
        string $physicalCodeId,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): ?array {
        if (! SchemaCache::hasTable('container') && ! SchemaCache::hasView('container')) {
            return null;
        }

        $query = DB::table('container');
        if (is_string($assignedId) && $assignedId !== '') {
            $query->where('container_id', $assignedId);
        } elseif (SchemaCache::hasColumn('container', 'physical_code_id')) {
            $query->where('physical_code_id', $physicalCodeId);
        } else {
            return null;
        }

        $container = $query->first();

        return $container === null ? null : $this->containerPayload($container, $context, $warehouse, $hideCrossWarehouseExistence);
    }

    /**
     * @return array<string, mixed>
     */
    private function containerPayload(
        object $container,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): array {
        // ERD bảng 22: containers không có warehouse_id (REQ-SRS-001 một kho ACTIVE);
        // legacy view chiếu NULL nên chỉ assert khi object thật sự mang warehouse_id.
        $containerWarehouseId = $container->warehouse_id ?? null;
        if (is_string($containerWarehouseId) && $containerWarehouseId !== '') {
            $this->assertWarehouseMatch($containerWarehouseId, $warehouse, $hideCrossWarehouseExistence);
        }
        $status = (string) ($container->status ?? 'ACTIVE');
        // ERD bảng 22: containers.status ∈ EMPTY/IN_STOCK/PARTIAL/ISSUED/
        // RECEIVED_PENDING_PUTAWAY/STORED/CLOSED — không có 'ACTIVE'. Chỉ
        // CLOSED/ISSUED mới là "không còn dùng được" cho scan.
        if (in_array(strtoupper($status), ['CLOSED', 'ISSUED'], true)) {
            ApiError::abort(409, 'SCAN_CONTAINER_INACTIVE', 'Container đã CLOSED/ISSUED nên không dùng cho scan mới.', ['status' => $status]);
        }
        $this->assertContextCompatible('CONTAINER', $status, 'AVAILABLE', $context);

        $productId = $container->primary_product_id ?? $container->sku_id ?? null;

        return [
            'classification' => 'CONTAINER',
            'item_id' => null,
            'sku_id' => $productId,
            'product_id' => $productId,
            'stock_status' => 'AVAILABLE',
            'object_status' => $status,
            'container_id' => (string) $container->container_id,
            'container_code' => $container->container_code ?? null,
            // Inbound Carton Scan v1.0 §3.1: FE cần phân biệt CARTON với BOX/TRAY
            // ngay tại classify để chỉ CARTON mới được đặt làm "Thùng đang quét".
            'container_type' => $container->container_type ?? null,
            'location_hint' => $container->location_hint ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSkuByCode(string $code, Warehouse $warehouse): ?array
    {
        if (! SchemaCache::hasTable('products') && ! SchemaCache::hasView('products')) {
            return null;
        }

        $query = DB::table('products')->where('code', $code);
        if (SchemaCache::hasColumn('products', 'sku_code')) {
            $query->orWhere('sku_code', $code);
        }

        $product = $query->first();

        return $product === null ? null : $this->skuPayload($product, $warehouse);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSkuById(mixed $assignedId, Warehouse $warehouse): ?array
    {
        if (! SchemaCache::hasTable('products') && ! SchemaCache::hasView('products') || ! is_string($assignedId) || $assignedId === '') {
            return null;
        }

        $product = DB::table('products')->where('id', $assignedId)->first();

        return $product === null ? null : $this->skuPayload($product, $warehouse);
    }

    /**
     * @return array<string, mixed>
     */
    private function skuPayload(object $product, Warehouse $warehouse): array
    {
        $this->assertActiveStatus(
            (string) ($product->status ?? 'ACTIVE'),
            'SCAN_SKU_INACTIVE',
            'SKU đang không hoạt động.'
        );

        $balance = SchemaCache::hasTable('inventory_balances')
            ? DB::table('inventory_balances')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->where('stock_status', 'AVAILABLE')
                ->first()
            : null;

        return [
            'classification' => 'SKU',
            'item_id' => null,
            'sku_id' => (string) $product->id,
            'product_id' => (string) $product->id,
            'sku_code' => $product->sku_code ?? $product->code ?? null,
            'stock_status' => 'AVAILABLE',
            'object_status' => (string) ($product->status ?? 'ACTIVE'),
            'container_id' => null,
            'location_hint' => null,
            'on_hand_qty' => $balance->on_hand_qty ?? null,
            'available_qty' => $balance->available_qty ?? null,
        ];
    }

    private function assertWarehouseMatch(
        mixed $objectWarehouseId,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence
    ): void {
        if (! is_string($objectWarehouseId) || $objectWarehouseId === '') {
            ApiError::abort(
                409,
                'SCAN_LOCATION_UNKNOWN',
                'Đối tượng scan chưa có kho hiện tại nên không thể xác nhận ngữ cảnh.'
            );
        }

        $acceptableIds = [(string) $warehouse->id];
        if ((SchemaCache::hasTable('warehouse') || SchemaCache::hasView('warehouse'))
            && SchemaCache::hasColumn('warehouse', 'warehouse_id')
            && SchemaCache::hasColumn('warehouse', 'warehouse_code')) {
            $mirrorId = DB::table('warehouse')
                ->where('warehouse_code', $warehouse->code)
                ->value('warehouse_id');
            if (is_string($mirrorId) && $mirrorId !== '') {
                $acceptableIds[] = $mirrorId;
            }
        }

        if (! in_array($objectWarehouseId, array_values(array_unique($acceptableIds)), true)) {
            if ($hideCrossWarehouseExistence) {
                ApiError::abort(404, 'SCAN_CODE_NOT_FOUND', 'Không tìm thấy mã scan trong phạm vi được phép.');
            }
            // Do not disclose the object's actual warehouse across data scopes.
            ApiError::abort(409, 'SCAN_WAREHOUSE_MISMATCH', 'Mã scan không thuộc kho được yêu cầu.');
        }
    }

    private function assertContextCompatible(
        string $classification,
        string $objectStatus,
        string $stockStatus,
        string $context
    ): void {
        $normalizedObjectStatus = strtoupper($objectStatus);
        $normalizedStockStatus = strtoupper($stockStatus);

        // Container dùng vòng đời riêng theo ERD bảng 22: STORED/PARTIAL vẫn là
        // container đang chứa hàng nên hợp lệ cho ngữ cảnh OUTBOUND.
        $outboundReadyStatuses = $classification === 'CONTAINER'
            ? ['IN_STOCK', 'ACTIVE', 'STORED', 'PARTIAL']
            : ['IN_STOCK', 'ACTIVE'];

        if ($context === 'OUTBOUND'
            && (! in_array($normalizedObjectStatus, $outboundReadyStatuses, true)
                || $normalizedStockStatus !== 'AVAILABLE')) {
            ApiError::abort(409, 'SCAN_CONTEXT_CONFLICT', 'Đối tượng scan không đủ điều kiện cho ngữ cảnh OUTBOUND.', [
                'classification' => $classification,
                'object_status' => $objectStatus,
                'stock_status' => $stockStatus,
                'context' => $context,
            ]);
        }
    }

    private function assertActiveStatus(string $status, string $errorCode, string $message): void
    {
        if (strtoupper($status) !== 'ACTIVE') {
            ApiError::abort(409, $errorCode, $message, ['status' => $status]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withContractMetadata(array $payload, string $resolvedSource): array
    {
        return $payload + [
            'resolved_source' => $resolvedSource,
            'contract_version' => self::CONTRACT_VERSION,
            'stock_effect' => self::STOCK_EFFECT_NONE,
            'movement_created' => false,
            'posting_event_created' => false,
            'idempotency_cache_written' => false,
        ];
    }
}
