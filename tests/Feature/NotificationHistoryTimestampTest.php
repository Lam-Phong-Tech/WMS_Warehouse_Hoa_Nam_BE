<?php

namespace Tests\Feature;

use App\Support\DocumentTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Support\NotificationTimestamp;
use Modules\Warehouse\Support\HistoryTimestamp;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** All writes are synthetic fixtures in memory or the owned loopback test DB. */
final class NotificationHistoryTimestampTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $db = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $safe = $app->environment('testing') && empty($db['url'])
            && (($db['driver'] === 'sqlite' && $db['database'] === ':memory:')
                || ($db['driver'] === 'pgsql' && $db['host'] === '127.0.0.1' && $db['database'] === 'p12_contract_test'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('Timestamp tests require memory or owned loopback p12_contract_test.');
        }
        parent::setUp();
        Event::fake([NotificationPushed::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:49:36+07:00'));
    }

    public function test_notification_writer_binding_and_first_read_share_real_instant_across_pg_timezones(): void
    {
        [$user, $token] = $this->actor();
        foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $zone) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL TIME ZONE '$zone'");
            }
            $notice = app(NotificationService::class)->notify('test.timestamp', 'Synthetic', 'Test only', [$user->user_id]);
            $recipient = NotificationRecipient::where('notification_id', $notice->id)->firstOrFail();
            $expected = '2026-10-20T06:49:36.000000Z';
            $url = '/api/v1/notifications/'.$recipient->id;
            $this->getJson($url, $this->headers($token))->assertOk()->assertJsonPath('data.read_at', null)
                ->assertJsonPath('data.created_at', $expected);
            $this->assertSame($expected, NotificationTimestamp::read($notice->getRawOriginal('created_at')));
            $this->patch($url.'/read', [], $this->headers($token))->assertOk()
                ->assertJsonPath('data.read_at', $expected)->assertJsonPath('meta.changed', true);
            $raw = $recipient->fresh()->getRawOriginal('read_at');
            $this->getJson($url, $this->headers($token))->assertOk()->assertJsonPath('data.read_at', $expected);
            $this->travel(1)->minutes();
            $this->patch($url.'/read', [], $this->headers($token))->assertOk()
                ->assertJsonPath('meta.changed', false)->assertJsonPath('data.read_at', $expected);
            $this->assertSame($raw, $recipient->fresh()->getRawOriginal('read_at'));
            $this->travelBack();
            $this->travelTo(CarbonImmutable::parse('2026-10-20T13:49:36+07:00'));
        }
    }

    public function test_read_all_uses_aware_binding_and_get_never_clamps_a_bad_persisted_future_value(): void
    {
        [$user, $token] = $this->actor();
        $notice = app(NotificationService::class)->notify('test.timestamp', 'Synthetic', 'Test only', [$user->user_id]);
        $recipient = NotificationRecipient::where('notification_id', $notice->id)->firstOrFail();
        $this->postJson('/api/v1/notifications/read-all', [], $this->headers($token))->assertOk();
        $this->assertSame('2026-10-20T06:49:36.000000Z', NotificationTimestamp::read($recipient->fresh()->getRawOriginal('read_at')));
        // Reproduces the reported bad instant on a synthetic row; no repair or offset relabelling.
        DB::table('notification_recipients')->where('id', $recipient->id)->update(['read_at' => '2026-10-20 13:49:36+00:00']);
        $raw = $recipient->fresh()->getRawOriginal('read_at');
        $this->getJson('/api/v1/notifications/'.$recipient->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.read_at', '2026-10-20T13:49:36.000000Z');
        $this->assertSame($raw, $recipient->fresh()->getRawOriginal('read_at'));
    }

    public function test_history_list_detail_session_and_latest_use_same_offset_and_preserve_null_metadata(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $batch = (string) Str::uuid();
        $event = (string) Str::uuid();
        DB::table('scan_batches')->insert(['scan_batch_id' => $batch, 'source_type' => 'MANUAL',
            'warehouse_id' => $warehouse->id, 'created_by' => $user->user_id, 'created_at' => '2026-10-15 03:45:57']);
        DB::table('scan_record_events')->insert(['scan_event_id' => $event, 'scan_batch_id' => $batch,
            'raw_code' => 'Synthetic', 'result_status' => 'RESOLVED', 'created_at' => '2026-10-15 03:45:57']);
        $expected = '2026-10-14T20:45:57.000000Z';
        $query = '?warehouse_id='.$warehouse->id;
        $list = $this->getJson('/api/v1/mini-app/history/records'.$query.'&source=SCAN', $this->headers($token))->assertOk();
        $this->assertSame($expected, $list->json('data.0.time'));
        $this->getJson('/api/v1/mini-app/history/records/SCAN:'.$batch.$query.'&activityId=SCAN:'.$event, $this->headers($token))
            ->assertOk()->assertJsonPath('data.selectedActivity.time', $expected)->assertJsonPath('data.events.0.time', $expected);
        $this->getJson('/api/v1/mini-app/history/scan-sessions/'.$batch.$query, $this->headers($token))->assertOk()
            ->assertJsonPath('data.events.0.time', $expected)->assertJsonPath('data.p23.batch.latestEvent.occurredAt', $expected)
            ->assertJsonPath('data.p23.batch.startedAt', null)->assertJsonPath('data.p23.batch.endedAt', null);
        $this->getJson('/api/v1/mini-app/scan-sessions'.$query, $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.createdAt', $expected)->assertJsonPath('data.0.latestEvent.occurredAt', $expected);
        $this->assertSame('2026-10-15 03:45:57', DB::table('scan_record_events')->where('scan_event_id', $event)->value('created_at'));
    }

    public function test_offset_equivalence_year_month_midnight_microseconds_and_null_are_preserved(): void
    {
        foreach (['2026-12-31T17:00:00.123456Z', '2026-12-31T17:00:00.123456+00:00', '2027-01-01T00:00:00.123456+07:00'] as $raw) {
            $this->assertSame('2026-12-31T17:00:00.123456Z', NotificationTimestamp::read($raw));
            $this->assertSame('2027-01-01 00:00:00.123456', DocumentTimestamp::instant($raw)->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s.u'));
        }
        $this->assertSame('2026-10-31T17:00:00.000000Z', NotificationTimestamp::read('2026-11-01T00:00:00+07:00'));
        $this->assertNull(NotificationTimestamp::read(null));
        $this->assertNull(DocumentTimestamp::utc(null));
    }

    public function test_typed_history_projection_is_independent_of_php_and_pg_zone_and_keeps_microseconds(): void
    {
        $old = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $phpZone) {
                date_default_timezone_set($phpZone);
                config(['app.timezone' => $phpZone]);
                foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $pgZone) {
                    if (DB::getDriverName() === 'pgsql') {
                        DB::statement("SET LOCAL TIME ZONE '$pgZone'");
                    }
                    $naive = HistoryTimestamp::expression('scan_events', 'e.created_at');
                    $aware = HistoryTimestamp::expression('warranty_event', 'w.event_at');
                    $n = DB::getDriverName() === 'pgsql' ? "TIMESTAMP '2027-01-01 00:00:00.123456'" : "'2027-01-01 00:00:00.123456'";
                    $a = DB::getDriverName() === 'pgsql' ? "TIMESTAMPTZ '2026-12-31T17:00:00.123456Z'" : "'2026-12-31T17:00:00.123456Z'";
                    $rows = DB::select("SELECT $naive AS t FROM (SELECT $n AS created_at) e UNION ALL SELECT $aware AS t FROM (SELECT $a AS event_at) w");
                    foreach ($rows as $row) {
                        $this->assertSame('2026-12-31T17:00:00.123456Z', HistoryTimestamp::utc($row->t));
                    }
                    $this->assertNull(HistoryTimestamp::utc(DB::selectOne("SELECT $naive AS t FROM (SELECT ".(DB::getDriverName() === 'pgsql' ? 'NULL::timestamp' : 'NULL').' AS created_at) e')->t));
                }
            }
        } finally {
            date_default_timezone_set($old);
            config(['app.timezone' => $old]);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL TIME ZONE 'UTC'");
            }
        }
    }

    public function test_history_date_filter_keeps_vietnam_calendar_day_before_count_and_cursor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-02T00:00:00+07:00'));
        [$user, $token, $warehouse] = $this->actor();
        $batch = (string) Str::uuid();
        DB::table('scan_batches')->insert(['scan_batch_id' => $batch, 'source_type' => 'MANUAL',
            'warehouse_id' => $warehouse->id, 'created_by' => $user->user_id, 'created_at' => '2026-12-31 23:59:59']);
        foreach (['2026-12-31 23:59:59', '2027-01-01 00:00:00'] as $raw) {
            DB::table('scan_record_events')->insert(['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $batch,
                'raw_code' => 'Synthetic', 'result_status' => 'RESOLVED', 'created_at' => $raw]);
        }
        $query = '?warehouse_id='.$warehouse->id.'&source=SCAN&date_mode=DATE&date=2026-12-31';
        $this->getJson('/api/v1/mini-app/history/records'.$query, $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.time', '2026-12-31T16:59:59.000000Z');
        $this->getJson('/api/v1/mini-app/history/summary'.$query, $this->headers($token))->assertOk()
            ->assertJsonPath('data.availableTotal', 1);
    }

    private function actor(): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'time_'.$suffix, 'display_name' => 'Synthetic time actor',
            'email' => 'time_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'TIME_'.$suffix, 'role_name' => 'Test only', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['history.read', 'inventory.view'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $token = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($token))
            ->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
