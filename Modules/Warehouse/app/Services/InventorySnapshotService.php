<?php

namespace Modules\Warehouse\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryReportSnapshot;

/** Chốt snapshot tồn kho cuối kỳ từ stock_movements — tối ưu tính tồn đầu kỳ cho báo cáo XNT. */
class InventorySnapshotService
{
    public function generateSnapshot(string $date, ?string $warehouseId = null): int
    {
        $start = $date.' 00:00:00';
        $toNextDayStart = date('Y-m-d H:i:s', strtotime($date.' +1 day 00:00:00'));

        $query = DB::table('stock_movements')
            ->selectRaw("
                warehouse_id,
                product_id,
                SUM(CASE WHEN occurred_at < ? THEN qty_delta ELSE 0 END) as opening_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('RECEIPT_IN','RECEIPT_REVERSED') THEN qty_delta ELSE 0 END) as receipt_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ISSUE_OUT','ISSUE_REVERSED') THEN qty_delta ELSE 0 END) as issue_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type = 'TRANSFER_IN' THEN qty_delta ELSE 0 END) as transfer_in_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type = 'TRANSFER_OUT' THEN qty_delta ELSE 0 END) as transfer_out_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ADJUST_IN') THEN qty_delta ELSE 0 END) as adjustment_in_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ADJUST_OUT') THEN qty_delta ELSE 0 END) as adjustment_out_qty,
                SUM(CASE WHEN occurred_at < ? THEN qty_delta ELSE 0 END) as closing_qty
            ", [
                $start,
                $start, $toNextDayStart,
                $start, $toNextDayStart,
                $start, $toNextDayStart,
                $start, $toNextDayStart,
                $start, $toNextDayStart,
                $start, $toNextDayStart,
                $toNextDayStart,
            ])
            ->whereNotNull('warehouse_id');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $records = $query->groupBy('warehouse_id', 'product_id')->get();

        $count = 0;
        foreach ($records as $record) {
            InventoryReportSnapshot::updateOrCreate(
                [
                    'snapshot_date' => $date,
                    'period_type' => 'daily',
                    'period_start' => $date,
                    'period_end' => $date,
                    'warehouse_id' => $record->warehouse_id,
                    'product_id' => $record->product_id,
                ],
                [
                    'opening_qty' => $record->opening_qty ?? 0,
                    'receipt_qty' => $record->receipt_qty ?? 0,
                    'issue_qty' => $record->issue_qty ?? 0,
                    'transfer_in_qty' => $record->transfer_in_qty ?? 0,
                    'transfer_out_qty' => $record->transfer_out_qty ?? 0,
                    'adjustment_in_qty' => $record->adjustment_in_qty ?? 0,
                    'adjustment_out_qty' => $record->adjustment_out_qty ?? 0,
                    'closing_qty' => $record->closing_qty ?? 0,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function generatePeriodSnapshot(string $periodType, string $date, ?string $warehouseId = null): int
    {
        [$periodStart, $periodEnd, $snapshotDate] = $this->resolvePeriod($periodType, $date);

        if ($periodType === 'daily') {
            return $this->generateSnapshot($snapshotDate, $warehouseId);
        }

        $records = $this->buildRangeRecords($periodStart, $periodEnd, $warehouseId);

        $count = 0;
        foreach ($records as $record) {
            InventoryReportSnapshot::updateOrCreate(
                [
                    'period_type' => $periodType,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'warehouse_id' => $record->warehouse_id,
                    'product_id' => $record->product_id,
                ],
                [
                    'snapshot_date' => $snapshotDate,
                    'opening_qty' => $record->opening_qty ?? 0,
                    'receipt_qty' => $record->receipt_qty ?? 0,
                    'issue_qty' => $record->issue_qty ?? 0,
                    'transfer_in_qty' => $record->transfer_in_qty ?? 0,
                    'transfer_out_qty' => $record->transfer_out_qty ?? 0,
                    'adjustment_in_qty' => $record->adjustment_in_qty ?? 0,
                    'adjustment_out_qty' => $record->adjustment_out_qty ?? 0,
                    'closing_qty' => $record->closing_qty ?? 0,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function resolvePeriod(string $periodType, string $date): array
    {
        $periodType = strtolower($periodType);
        $base = Carbon::parse($date);

        return match ($periodType) {
            'daily' => [$base->toDateString(), $base->toDateString(), $base->toDateString()],
            'weekly' => [
                $base->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $base->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                $base->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'monthly' => [
                $base->copy()->startOfMonth()->toDateString(),
                $base->copy()->endOfMonth()->toDateString(),
                $base->copy()->endOfMonth()->toDateString(),
            ],
            default => throw new \InvalidArgumentException('Unsupported inventory snapshot period type.'),
        };
    }

    private function buildRangeRecords(string $periodStart, string $periodEnd, ?string $warehouseId = null)
    {
        $start = $periodStart.' 00:00:00';
        $endExclusive = Carbon::parse($periodEnd)->addDay()->format('Y-m-d 00:00:00');

        $query = DB::table('stock_movements')
            ->selectRaw("
                warehouse_id,
                product_id,
                SUM(CASE WHEN occurred_at < ? THEN qty_delta ELSE 0 END) as opening_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('RECEIPT_IN','RECEIPT_REVERSED') THEN qty_delta ELSE 0 END) as receipt_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ISSUE_OUT','ISSUE_REVERSED') THEN qty_delta ELSE 0 END) as issue_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type = 'TRANSFER_IN' THEN qty_delta ELSE 0 END) as transfer_in_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type = 'TRANSFER_OUT' THEN qty_delta ELSE 0 END) as transfer_out_qty,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ADJUST_IN') THEN qty_delta ELSE 0 END) as adjustment_in_qty,
                -SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? AND movement_type IN ('ADJUST_OUT') THEN qty_delta ELSE 0 END) as adjustment_out_qty,
                SUM(CASE WHEN occurred_at < ? THEN qty_delta ELSE 0 END) as closing_qty
            ", [
                $start,
                $start, $endExclusive,
                $start, $endExclusive,
                $start, $endExclusive,
                $start, $endExclusive,
                $start, $endExclusive,
                $start, $endExclusive,
                $endExclusive,
            ])
            ->whereNotNull('warehouse_id');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->groupBy('warehouse_id', 'product_id')->get();
    }
}
