<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\SafeStockAlertHistory;
use Modules\Warehouse\Models\SafeStockConfig;

/**
 * Port từ SafeStockAlertService của ERP-Backend M2 — đánh giá tồn kho so với định mức
 * min/max, chỉ ghi lịch sử cảnh báo khi trạng thái (normal/low_stock/overflow) thực sự đổi.
 */
class SafeStockAlertService
{
    public const STATUS_NORMAL = 'normal';

    public const STATUS_LOW_STOCK = 'low_stock';

    public const STATUS_OVERFLOW = 'overflow';

    public function evaluateProduct(string $productId, ?string $warehouseId = null): int
    {
        $today = now()->toDateString();
        $configs = SafeStockConfig::query()
            ->where('product_id', $productId)
            ->where(function ($query) use ($warehouseId) {
                if ($warehouseId === null) {
                    $query->whereNull('warehouse_id');
                } else {
                    $query->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id');
                }
            })
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->get();

        $created = 0;
        foreach ($configs as $config) {
            if ($this->evaluateConfig($config)) {
                $created++;
            }
        }

        return $created;
    }

    public function evaluateConfig(SafeStockConfig $config): bool
    {
        $currentQty = $this->currentQty($config);
        $newStatus = $this->statusFor($currentQty, $config);

        $latestLog = SafeStockAlertHistory::query()
            ->where('product_id', $config->product_id)
            ->where('warehouse_id', $config->warehouse_id)
            ->orderByDesc('created_at')
            ->first();

        if (! $latestLog && $newStatus === self::STATUS_NORMAL) {
            return false;
        }

        $oldStatus = $latestLog?->new_status;
        if ($oldStatus === $newStatus) {
            return false;
        }

        SafeStockAlertHistory::create([
            'product_id' => $config->product_id,
            'warehouse_id' => $config->warehouse_id,
            'current_qty' => $currentQty,
            'min_qty' => $config->min_qty,
            'max_qty' => $config->max_qty,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'created_at' => now(),
        ]);

        return true;
    }

    public function statusFor(float $currentQty, SafeStockConfig $config): string
    {
        if ($config->min_qty !== null && $currentQty < (float) $config->min_qty) {
            return self::STATUS_LOW_STOCK;
        }

        if ($config->max_qty !== null && $currentQty > (float) $config->max_qty) {
            return self::STATUS_OVERFLOW;
        }

        return self::STATUS_NORMAL;
    }

    private function currentQty(SafeStockConfig $config): float
    {
        $query = InventoryBalance::query()->where('product_id', $config->product_id);

        if ($config->warehouse_id !== null) {
            $query->where('warehouse_id', $config->warehouse_id);
        }

        return (float) $query->sum('available_qty');
    }
}
