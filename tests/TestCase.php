<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Warehouse\Models\Warehouse;

abstract class TestCase extends BaseTestCase
{
    /**
     * REQ-SRS-001 + uk_warehouses_single_active: hệ thống chỉ có đúng một
     * warehouse ACTIVE. Fixture nào cần "kho tổng" phải tái sử dụng bản ghi
     * ACTIVE hiện hữu (đổi code/name theo test, rollback theo transaction)
     * thay vì tạo bản thứ hai và vỡ unique guard.
     */
    protected function ensureActiveWarehouse(string $code, ?string $name = null): Warehouse
    {
        $existing = Warehouse::query()->where('status', 'ACTIVE')->first();
        if ($existing !== null) {
            if ($existing->code !== $code || ($name !== null && $existing->name !== $name)) {
                $existing->forceFill(['code' => $code, 'name' => $name ?? $code])->save();
            }

            return $existing->refresh();
        }

        return Warehouse::create([
            'code' => $code,
            'name' => $name ?? $code,
            'status' => 'ACTIVE',
        ]);
    }
}
