<?php

namespace Modules\Warehouse\Support;

use Illuminate\Database\Query\Builder;

/** Already scoped by P09. Latest is not the last row of an arbitrary page. */
final class P23WarrantyTimeline
{
    public static function metadata(Builder $query): array
    {
        $unknown = (clone $query)->whereNull('e.event_at')->count();
        $latest = (clone $query)->whereNotNull('e.event_at')
            ->orderByDesc('e.event_at')->orderByDesc('e.warranty_event_id')
            ->first(['e.warranty_event_id', 'e.event_at']);

        return ['timeline_contract' => 'p23-timeline-v2',
            'latest_recorded_event_id' => $latest?->warranty_event_id,
            'latest_recorded_event_at' => $latest?->event_at,
            'latest_policy' => 'GREATEST_RECORDED_EVENT_AT_THEN_EVENT_ID_IN_FULL_SCOPED_SOURCE',
            'unknown_time_count' => $unknown,
            'latest_completeness' => $unknown > 0 ? 'PARTIAL_TIME_INFORMATION' : 'ALL_RETAINED_EVENTS_HAVE_TIME',
            'time_source' => 'warranty_event.event_at',
            'page_tie_breaker' => 'warranty_event_id_SAME_DIRECTION_AS_EVENT_AT',
            'page_null_time_policy' => 'EMPTY_SORT_KEY_FIRST_ASC_LAST_DESC_NOT_A_REAL_TIMESTAMP',
            'retention_status' => 'NOT_PUBLISHED'];
    }
}
