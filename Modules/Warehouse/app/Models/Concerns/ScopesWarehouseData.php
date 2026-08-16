<?php

namespace Modules\Warehouse\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Auth\Models\User;

/**
 * Applies the authenticated user's warehouse data scope to warehouse-owned
 * projections/ledgers. System/super-admin users keep unrestricted access;
 * users without an effective warehouse scope fail closed to an empty query.
 *
 * This is a server-side data boundary. FE filtering/route guards are UX only.
 */
trait ScopesWarehouseData
{
    public static function bootScopesWarehouseData(): void
    {
        static::addGlobalScope('warehouse-data-scope', function (Builder $builder): void {
            $user = auth('api')->user();
            if (! $user instanceof User) {
                return;
            }

            $warehouseIds = $user->warehouseScopeIds();
            if ($warehouseIds === null) {
                return;
            }

            if ($warehouseIds === []) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->whereIn($builder->qualifyColumn('warehouse_id'), $warehouseIds);
        });
    }
}
