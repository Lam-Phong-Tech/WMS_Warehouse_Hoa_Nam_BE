<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;

class PvProfileProvisioner
{
    /** Provision administration metadata without making an unpublished SKU public. */
    public static function ensure(Sku $sku): void
    {
        $relation = PvRelations::profile();
        if (DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->exists()) {
            return;
        }

        DB::table($relation['table'])->insertOrIgnore([
            $relation['key'] => (string) Str::uuid(),
            $relation['sku'] => $sku->sku_id,
            'display_name' => $sku->sku_name,
            'published' => (bool) $sku->published_to_app,
            'published_at' => $sku->published_to_app ? now() : null,
            'sort_order' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
