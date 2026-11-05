<?php

namespace Modules\Warehouse\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

final class SkuInformationCompleteness
{
    /** Apply before pagination; the grouped OR must not bypass other filters. */
    public static function apply(Builder|EloquentBuilder $query, string $status): void
    {
        if ($status === 'PENDING') {
            $query->where(function ($missing): void {
                $missing->whereNull('brand_id')
                    ->orWhereNull('category_id')
                    ->orWhereNull('model_id');
            });
        } elseif ($status === 'COMPLETE') {
            $query->whereNotNull('brand_id')->whereNotNull('category_id')->whereNotNull('model_id');
        }
    }
}
