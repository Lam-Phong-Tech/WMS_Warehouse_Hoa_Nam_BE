<?php

namespace Modules\Notification\Support;

use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Capture at dispatch, before a queued listener can observe later directory edits. */
final class EventSourceSnapshot
{
    public static function capture(?string $actor, ?string $warehouse, string $policy = 'AT_DOMAIN_EVENT_DISPATCH'): array
    {
        $user = $actor === null ? null : DB::table('app_user')->where('user_id', $actor)->where('status', 'ACTIVE')->first(['display_name']);
        $profile = $actor === null || ! Schema::hasTable('user_business_profiles') ? null
            : DB::table('user_business_profiles')->where('user_id', $actor)->first(['job_title']);
        $name = $warehouse === null ? null : DB::table('warehouses')->where('id', $warehouse)->value('name');

        return ['actor_id' => $actor, 'actor_name' => $user?->display_name, 'job_title' => $profile?->job_title,
            'warehouse_id' => $warehouse, 'warehouse_name' => $name,
            'captured_at' => now('UTC')->toISOString(), 'capture_policy' => $policy];
    }

    public static function presentation(string $id, ?object $row = null): array
    {
        return ['contract' => 'notification-source-snapshot-v1',
            'actor_id' => $row?->actor_id,
            'availability' => $row === null ? 'NOT_RECORDED' : 'RECORDED',
            'actor_name_snapshot' => $row?->actor_name,
            'actor_job_title_snapshot' => $row?->job_title,
            'actor_job_title_status' => $row?->job_title === null ? 'NOT_RECORDED' : 'RECORDED',
            'warehouse_name_snapshot' => $row?->warehouse_name,
            'warehouse_id' => $row?->warehouse_id,
            'captured_at' => DocumentTimestamp::utc($row?->captured_at, 'UTC'),
            'timestamp_source' => $row === null ? null : 'notification_source_snapshots.captured_at',
            'capture_policy' => $row?->capture_policy,
            'job_title_source' => $row?->job_title === null ? null : 'user_business_profiles.job_title AT_CAPTURE_NOT_CURRENT_IAM_ROLE'];
    }
}
