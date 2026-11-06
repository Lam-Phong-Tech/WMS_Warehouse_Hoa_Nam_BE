<?php

namespace Modules\Warehouse\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/** The Dashboard and warranty list share one calendar-day SLA policy. */
final class WarrantyOverdue
{
    // Keep legacy aliases as well as the current canonical terminal statuses.
    private const CLOSED_STATUSES = ['COMPLETED', 'RETURNED', 'RETURNED_TO_CUSTOMER', 'CLOSED', 'CANCELLED'];

    public static function today(): Carbon
    {
        return Carbon::today('Asia/Ho_Chi_Minh');
    }

    public static function apply(Builder $query): Builder
    {
        return $query->whereNotNull('estimated_completion_date')
            ->whereDate('estimated_completion_date', '<', self::today()->toDateString())
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }
}
