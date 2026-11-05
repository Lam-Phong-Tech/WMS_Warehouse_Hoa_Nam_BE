<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Models\AuditLog;
use Tests\TestCase;

final class PostgresTimestampRoundTripTest extends TestCase
{
    public function test_audit_and_query_builder_round_trip_independent_of_database_zone(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires an owned PostgreSQL test database.');
        }
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertStringContainsString('test', DB::connection()->getDatabaseName());
        DB::statement('CREATE TEMP TABLE audit_log (id uuid, action text, object_type text, created_at timestamptz(6))');
        DB::statement('CREATE TEMP TABLE timestamp_probe (aware timestamptz(6), naive timestamp(6))');
        $at = CarbonImmutable::parse('2026-11-05T16:51:12.123456+07:00');
        try {
            foreach (['UTC', 'Asia/Ho_Chi_Minh', 'America/New_York'] as $zone) {
                DB::select("SELECT set_config('TimeZone', ?, false)", [$zone]);
                $event = AuditLog::recordPayload(['action' => 'LOGIN', 'object_type' => 'user', 'created_at' => $at]);
                $this->assertSame('2026-11-05T09:51:12.123456Z', $event->fresh()->toArray()['created_at']);
                DB::table('timestamp_probe')->insert(['aware' => $at, 'naive' => $at]);
                $row = DB::selectOne("SELECT to_char(aware AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS utc, to_char(naive, 'YYYY-MM-DD HH24:MI:SS.US') AS wall FROM timestamp_probe LIMIT 1");
                $this->assertSame('2026-11-05 09:51:12.123456', $row->utc);
                $this->assertSame('2026-11-05 16:51:12.123456', $row->wall);
                $this->assertSame(1, DB::table('timestamp_probe')->where('aware', '<=', $at)->count());
                DB::table('timestamp_probe')->delete();
            }
        } finally {
            DB::statement('DROP TABLE IF EXISTS pg_temp.audit_log, pg_temp.timestamp_probe');
            DB::statement('RESET TIME ZONE');
        }
    }
}
