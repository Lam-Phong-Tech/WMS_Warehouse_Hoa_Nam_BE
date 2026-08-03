<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StocktakeGrainBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $this->backfillSnapshot();
        $this->backfillCount();
        $this->backfillVariance();

        foreach (['stocktake_snapshot', 'stocktake_count', 'stocktake_variance'] as $table) {
            if (DB::table($table)->whereNull('grain_key')->exists()) {
                throw new RuntimeException("{$table} còn grain_key null sau seed backfill.");
            }
            $duplicate = DB::table($table)
                ->select('session_id', 'grain_key')
                ->groupBy('session_id', 'grain_key')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicate) {
                throw new RuntimeException("{$table} có duplicate session/grain_key sau seed.");
            }
        }
    }

    private function backfillSnapshot(): void
    {
        DB::table('stocktake_snapshot')->whereNull('grain_key')->orderBy('snapshot_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            DB::table('stocktake_snapshot')->where('snapshot_id', $row->snapshot_id)->update([
                'grain_type' => 'SKU',
                'grain_id' => $row->product_id,
                'grain_key' => 'SKU|'.$row->product_id.'|'.$status,
                'stock_status' => $status,
            ]);
        });
    }

    private function backfillCount(): void
    {
        DB::table('stocktake_count')->whereNull('grain_key')->orderBy('count_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            $grainType = $row->inventory_serial_id ? 'ITEM' : ($row->container_id ? 'CONTAINER' : 'SKU');
            $grainId = $row->inventory_serial_id ?: ($row->container_item_id ?: ($row->container_id ?: $row->product_id));
            DB::table('stocktake_count')->where('count_id', $row->count_id)->update([
                'grain_type' => $grainType,
                'grain_id' => $grainId,
                'grain_key' => $grainType.'|'.$grainId.'|'.$status,
                'stock_status' => $status,
            ]);
        });
    }

    private function backfillVariance(): void
    {
        DB::table('stocktake_variance')->whereNull('grain_key')->orderBy('variance_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            DB::table('stocktake_variance')->where('variance_id', $row->variance_id)->update([
                'grain_type' => 'SKU',
                'grain_id' => $row->product_id,
                'grain_key' => 'SKU|'.$row->product_id.'|'.$status,
                'stock_status' => $status,
                'inventory_effect_qty' => $row->variance_qty,
            ]);
        });
    }
}
