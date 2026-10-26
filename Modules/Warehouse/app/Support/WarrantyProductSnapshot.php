<?php

namespace Modules\Warehouse\Support;

use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;

final class WarrantyProductSnapshot
{
    public static function record(string $id, ?object $item, ?string $temporary): void
    {
        $sku = $item === null ? null : DB::table('sku')->where('sku_id', $item->sku_id)->first(['sku_code', 'sku_name']);
        DB::table('warranty_product_snapshots')->insert(['case_id' => $id, 'item_id' => $item?->item_id,
            'sku_id' => $item?->sku_id, 'serial_number' => $item?->serial_number,
            'sku_code' => $sku?->sku_code, 'sku_name' => $sku?->sku_name,
            'temporary_item_code' => $temporary, 'captured_at' => now('UTC')->toISOString()]);
    }

    public static function read(string $id): array
    {
        $row = DB::table('warranty_product_snapshots')->where('case_id', $id)->first();

        return ['contract' => 'warranty-product-snapshot-v1', 'availability' => $row === null ? 'NOT_RECORDED' : 'RECORDED',
            'case_id' => $id, 'item_id' => $row?->item_id, 'sku_id' => $row?->sku_id,
            'serial_number' => $row?->serial_number, 'sku_code' => $row?->sku_code, 'sku_name' => $row?->sku_name,
            'temporary_item_code' => $row?->temporary_item_code,
            'captured_at' => DocumentTimestamp::utc($row?->captured_at, 'UTC'),
            'timestamp_source' => $row === null ? null : 'warranty_product_snapshots.captured_at',
            'capture_policy' => 'AT_WARRANTY_INTAKE_NOT_CURRENT_CATALOG'];
    }
}
