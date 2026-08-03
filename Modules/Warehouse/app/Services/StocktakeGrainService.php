<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\StocktakeSession;

class StocktakeGrainService
{
    private const SCALE = 3;

    /** @return array{SKU:int,ITEM:int,CONTAINER:int} */
    public function createSnapshots(string $warehouseId, string $sessionId, Carbon $startedAt): array
    {
        $rows = [];

        foreach (DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->orderBy('product_id')
            ->orderBy('stock_status')
            ->get() as $balance) {
            $rows[] = $this->snapshotRow(
                $sessionId,
                StocktakeService::GRAIN_SKU,
                (string) $balance->product_id,
                (string) $balance->product_id,
                (string) $balance->stock_status,
                (string) $balance->on_hand_qty,
                $startedAt
            );
        }

        foreach (DB::table('inventory_serials')
            ->where('warehouse_id', $warehouseId)
            ->orderBy('id')
            ->get() as $item) {
            $qty = $item->status === 'IN_STOCK' ? '1.000' : '0.000';
            $rows[] = $this->snapshotRow(
                $sessionId,
                StocktakeService::GRAIN_ITEM,
                (string) $item->id,
                (string) $item->product_id,
                (string) ($item->stock_condition ?: 'AVAILABLE'),
                $qty,
                $startedAt,
                inventorySerialId: (string) $item->id
            );
        }

        foreach (DB::table('container_item as ci')
            ->join('container as c', 'c.container_id', '=', 'ci.container_id')
            ->where('c.warehouse_id', $warehouseId)
            ->whereNull('c.deleted_at')
            ->where('ci.status', 'ACTIVE')
            ->whereNotNull('ci.product_id')
            ->orderBy('ci.container_item_id')
            ->get(['ci.container_item_id', 'ci.container_id', 'ci.product_id', 'ci.quantity']) as $containerItem) {
            $rows[] = $this->snapshotRow(
                $sessionId,
                StocktakeService::GRAIN_CONTAINER,
                (string) $containerItem->container_item_id,
                (string) $containerItem->product_id,
                'AVAILABLE',
                (string) ($containerItem->quantity ?? '0'),
                $startedAt,
                containerId: (string) $containerItem->container_id,
                containerItemId: (string) $containerItem->container_item_id
            );
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('stocktake_snapshot')->insert($chunk);
        }

        return [
            StocktakeService::GRAIN_SKU => collect($rows)->where('grain_type', StocktakeService::GRAIN_SKU)->count(),
            StocktakeService::GRAIN_ITEM => collect($rows)->where('grain_type', StocktakeService::GRAIN_ITEM)->count(),
            StocktakeService::GRAIN_CONTAINER => collect($rows)->where('grain_type', StocktakeService::GRAIN_CONTAINER)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function resolveCountGrain(StocktakeSession $session, array $line): array
    {
        $grainType = strtoupper((string) $line['grain_type']);
        $grainId = (string) $line['grain_id'];
        $productId = (string) $line['product_id'];
        $stockStatus = strtoupper((string) $line['stock_status']);
        $grainKey = $this->grainKey($grainType, $grainId, $stockStatus);

        $snapshot = DB::table('stocktake_snapshot')
            ->where('session_id', $session->session_id)
            ->where('grain_key', $grainKey)
            ->first();
        if (! $snapshot) {
            ApiError::abort(422, 'COUNT_GRAIN_NOT_IN_SNAPSHOT', 'Grain không tồn tại trong snapshot của session; không được thêm grain phát sinh sau khi start.', [
                'grain_type' => $grainType,
                'grain_id' => $grainId,
                'stock_status' => $stockStatus,
            ]);
        }
        if ((string) $snapshot->product_id !== $productId) {
            ApiError::abort(422, 'COUNT_GRAIN_PRODUCT_MISMATCH', 'product_id không khớp grain trong snapshot.');
        }

        $actualQty = bcadd((string) $line['actual_qty'], '0', self::SCALE);
        if ($grainType === StocktakeService::GRAIN_ITEM && ! in_array($actualQty, ['0.000', '1.000'], true)) {
            ApiError::abort(422, 'ITEM_COUNT_MUST_BE_BINARY', 'Item grain chỉ chấp nhận actual_qty bằng 0 hoặc 1.');
        }

        return [
            'grain_type' => $grainType,
            'grain_id' => $grainId,
            'grain_key' => $grainKey,
            'product_id' => $productId,
            'stock_status' => $stockStatus,
            'inventory_serial_id' => $snapshot->inventory_serial_id,
            'container_id' => $snapshot->container_id,
            'container_item_id' => $snapshot->container_item_id,
            'actual_qty' => $actualQty,
            'counted_at' => isset($line['counted_at']) ? Carbon::parse($line['counted_at']) : now(),
        ];
    }

    public function grainKey(string $grainType, string $grainId, string $stockStatus): string
    {
        return strtoupper($grainType).'|'.$grainId.'|'.strtoupper($stockStatus);
    }

    /** @return array<string, mixed> */
    private function snapshotRow(
        string $sessionId,
        string $grainType,
        string $grainId,
        string $productId,
        string $stockStatus,
        string $qty,
        Carbon $startedAt,
        ?string $inventorySerialId = null,
        ?string $containerId = null,
        ?string $containerItemId = null
    ): array {
        return [
            'snapshot_id' => (string) Str::uuid(),
            'session_id' => $sessionId,
            'grain_type' => $grainType,
            'grain_id' => $grainId,
            'grain_key' => $this->grainKey($grainType, $grainId, $stockStatus),
            'product_id' => $productId,
            'stock_status' => strtoupper($stockStatus),
            'inventory_serial_id' => $inventorySerialId,
            'container_id' => $containerId,
            'container_item_id' => $containerItemId,
            'snapshot_qty' => bcadd($qty, '0', self::SCALE),
            'stocktake_started_at' => $startedAt,
            'created_at' => now(),
        ];
    }
}
