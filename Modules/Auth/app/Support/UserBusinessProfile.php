<?php

namespace Modules\Auth\Support;

use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;

/** IAM-owned job title, independent of permissions and never read back into history. */
final class UserBusinessProfile
{
    public static function read(string $user): ?array
    {
        $row = DB::table('user_business_profiles')->where('user_id', $user)->first();

        return $row === null ? null : ['job_title' => $row->job_title, 'source' => $row->source,
            'assigned_at' => DocumentTimestamp::utc($row->assigned_at, 'UTC'),
            'semantics' => 'CURRENT_IAM_BUSINESS_PROFILE_NOT_HISTORICAL_SNAPSHOT'];
    }

    public static function put(string $user, ?string $title, ?string $actor, string $source = 'IAM_USER_PROFILE'): void
    {
        DB::table('user_business_profiles')->updateOrInsert(['user_id' => $user], [
            'job_title' => $title, 'assigned_by' => $actor, 'assigned_at' => now('UTC')->toISOString(), 'source' => $source,
        ]);
        AuditLogger::record(action: 'BUSINESS_PROFILE_UPDATE', objectType: 'user',
            objectId: $user, actorId: $actor, afterData: ['changed_fields' => ['job_title'], 'source' => $source]);
    }
}
