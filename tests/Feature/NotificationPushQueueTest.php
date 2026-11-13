<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Delivery\DeliverySender;
use Modules\Notification\Delivery\FcmAccessTokenSource;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\NotificationOutbox;
use Modules\Notification\Delivery\PruneNotificationDeliveryState;
use Modules\Notification\Delivery\PushDeliveryThrottle;
use Modules\Notification\Jobs\DispatchNotificationDeliveries;
use Modules\Notification\Services\NotificationService;
use Tests\Feature\Concerns\PushDeliveryTestCase;

class NotificationPushQueueTest extends PushDeliveryTestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['notification_delivery.max_push_per_user_per_minute' => 60]);
        $this->travelTo(now()->startOfSecond());
    }

    private function registered(): array
    {
        [$u, $token] = $this->actor();
        $r = $this->putJson(self::BASE.'/installations/'.Str::uuid(), $this->body('TEST-TOKEN-'.Str::uuid()),
            $this->headersFor($token, $this->proof()))->assertCreated();

        return [$u, $r->json('data.registration_id')];
    }

    /** Existing committed rows, using the legacy eager expiry, to exercise recovery too. */
    private function seedDeliveries($u, string $binding, int $count): void
    {
        $stamp = now()->utc()->toIso8601String();
        $notifications = $recipients = $deliveries = [];
        foreach (range(1, $count) as $i) {
            $n = (string) Str::uuid();
            $r = (string) Str::uuid();
            $notifications[] = ['id' => $n, 'event_key' => 'system.notification.test', 'title' => 'TEST', 'body' => 'TEST',
                'severity' => 'info', 'source_module' => 'notification', 'created_at' => $stamp];
            $recipients[] = ['id' => $r, 'notification_id' => $n, 'user_id' => $u->user_id, 'created_at' => $stamp];
            $deliveries[] = ['id' => (string) Str::uuid(), 'recipient_id' => $r, 'registration_id' => $binding,
                'binding_version' => 1, 'state' => 'PENDING', 'attempts' => 0, 'available_at' => $stamp,
                'expires_at' => now()->utc()->addHour()->toIso8601String(), 'created_at' => $stamp, 'updated_at' => $stamp];
        }
        foreach (array_chunk($notifications, 50) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }
        foreach (array_chunk($recipients, 50) as $chunk) {
            DB::table('notification_recipients')->insert($chunk);
        }
        foreach (array_chunk($deliveries, 50) as $chunk) {
            DB::table('notification_deliveries')->insert($chunk);
        }
    }

    private function sender(string $outcome = 'PROVIDER_ACCEPTED', ?int $retry = null): DeliverySender
    {
        return new class($outcome, $retry) implements DeliverySender
        {
            public array $seen = [];

            public function __construct(private string $outcome, private ?int $retry) {}

            public function send(string $token, array $data): array
            {
                $this->seen[] = $data;

                return ['state' => $this->outcome, 'provider_id' => 'TEST-ID', 'retry_after' => $this->retry];
            }
        };
    }

    public function test_a_thousand_pushes_are_durable_and_resume_without_repeating_accepted_rows(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1000);
        $sender = $this->sender();
        $outbox = app(NotificationOutbox::class);
        $this->assertSame(1, $outbox->drain($sender, 100));
        $this->assertSame(999, DB::table('notification_deliveries')->where('state', 'PENDING')->count());
        $this->assertSame(0, (int) DB::table('notification_deliveries')->where('last_code', 'RATE_DEFERRED')->max('attempts'));
        // New service instance simulates worker restart. Every remaining row eventually progresses.
        $outbox = new NotificationOutbox;
        for ($i = 1; $i < 1000; $i++) {
            $this->travel(1)->seconds();
            $this->assertSame(1, $outbox->drain($sender, 1));
        }
        $this->assertCount(1000, $sender->seen);
        $this->assertCount(1000, array_unique(array_column($sender->seen, 'delivery_id')));
        $this->assertSame(1000, DB::table('notification_deliveries')->where('state', 'PROVIDER_ACCEPTED')->count());
        $this->assertSame(1000, DB::table('notification_recipients')->whereNull('read_at')->count());
        $this->assertSame(0, $outbox->drain($sender));
    }

    public function test_new_push_waits_two_hours_without_spending_transport_ttl(): void
    {
        [$u] = $this->registered();
        app(NotificationService::class)->notify('system.notification.test', 'TEST', 'TEST', [$u->user_id], dispatchKey: 'wait-ttl');
        $this->assertNull(DB::table('notification_deliveries')->value('expires_at'));
        $this->travel(2)->hours();
        $sender = $this->sender();
        $this->assertSame(1, app(NotificationOutbox::class)->drain($sender));
        $this->assertSame(now()->utc()->addHour()->toIso8601String(), $sender->seen[0]['expires_at']);
    }

    public function test_retry_after_is_preserved_and_does_not_extend_first_attempt_deadline(): void
    {
        [$u] = $this->registered();
        app(NotificationService::class)->notify('system.notification.test', 'TEST', 'TEST', [$u->user_id], dispatchKey: 'retry-ttl');
        $sender = $this->sender('RETRYABLE', 120);
        $outbox = app(NotificationOutbox::class);
        $this->assertSame(1, $outbox->drain($sender));
        $expiry = DB::table('notification_deliveries')->value('expires_at');
        $this->assertSame(0, $outbox->drain($sender));
        $this->travel(120)->seconds();
        $this->assertSame(1, $outbox->drain($sender));
        $this->assertSame($expiry, DB::table('notification_deliveries')->value('expires_at'));
        $this->assertSame(2, (int) DB::table('notification_deliveries')->value('attempts'));
    }

    public function test_users_have_independent_slots_and_bad_config_defers_instead_of_sending(): void
    {
        [$a, $bindingA] = $this->registered();
        [$b, $bindingB] = $this->registered();
        $this->seedDeliveries($a, $bindingA, 2);
        $this->seedDeliveries($b, $bindingB, 1);
        $sender = $this->sender();
        $outbox = app(NotificationOutbox::class);
        $this->assertSame(2, $outbox->drain($sender));
        $this->travel(1)->seconds();
        config(['notification_delivery.max_push_per_user_per_minute' => 0]);
        $this->assertSame(0, $outbox->drain($sender));
        $this->assertCount(2, $sender->seen);
        $this->assertSame(1, DB::table('notification_deliveries')->where('state', 'PENDING')->count());
    }

    public function test_pruning_keeps_waiting_rows_even_with_old_deadlines(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        DB::table('notification_deliveries')->update(['expires_at' => now()->subDays(40)->toIso8601String(), 'updated_at' => now()->subDays(40)->toIso8601String()]);
        app(PruneNotificationDeliveryState::class)->prune(250);
        $this->assertSame(1, DB::table('notification_deliveries')->count());
    }

    public function test_warehouse_display_opt_out_does_not_enqueue_push_or_delete_history(): void
    {
        [$u] = $this->registered();
        DB::transaction(fn () => ProfilePreferences::put($u->user_id, ['notifications' => ['inventory' => ['in_app' => false]]], 1));
        $n = app(NotificationService::class)->notify('stock.shortage_detected', 'TEST', 'TEST', [$u->user_id], sourceModule: 'inventory', sourceSnapshot: []);
        $this->assertNotNull($n);
        $this->assertDatabaseHas('notification_recipients', ['notification_id' => $n->id]);
        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    public function test_push_jobs_use_separate_queue_and_continue_waiting_work_only_when_async(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 2);
        $job = new DispatchNotificationDeliveries;
        $this->assertSame('notification-delivery', $job->queue);
        config(['queue.default' => 'redis']);
        app()->instance(FcmHttpSender::class, new FcmHttpSender(
            new class implements FcmAccessTokenSource
            {
                public function accessToken(): string
                {
                    return 'FAKE';
                }
            }
        ));
        Http::fake(['*' => Http::response(['name' => 'projects/hoa-nam-scanner-devtest/messages/TEST'], 200)]);
        app()->call([$job, 'handle']);
        Queue::assertPushed(DispatchNotificationDeliveries::class, fn ($next) => $next->queue === 'notification-delivery' && $next->delay !== null);
        $this->assertSame(1, DB::table('notification_deliveries')->where('state', 'PENDING')->count());
    }

    public function test_monitor_alerts_for_outbox_backlog_even_when_job_queue_is_empty(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 2);
        config(['notification_delivery.monitor_delivery_threshold' => 1]);
        $this->artisan('notifications:monitor', ['--json' => true])->expectsOutputToContain('"status":"alert"')->assertSuccessful();
    }

    public function test_monitor_alerts_for_overdue_or_unknown_deliveries(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        DB::table('notification_deliveries')->update(['available_at' => now()->subSeconds(301)->toIso8601String()]);
        $this->artisan('notifications:monitor', ['--json' => true])->expectsOutputToContain('"status":"alert"')->assertSuccessful();
        DB::table('notification_deliveries')->update(['state' => 'UNKNOWN']);
        $this->artisan('notifications:monitor', ['--json' => true])->expectsOutputToContain('"status":"alert"')->assertSuccessful();
    }

    public function test_multiple_devices_share_the_same_user_admission_limit(): void
    {
        [$u, $binding] = $this->registered();
        $old = DB::table('notification_delivery_bindings')->where('id', $binding)->first();
        $installation = (array) DB::table('notification_delivery_installations')->where('id', $old->installation_id)->first();
        $second = (array) $old;
        $second['id'] = (string) Str::uuid();
        $second['installation_id'] = (string) Str::uuid();
        $second['token_fingerprint'] = hash('sha256', 'SECOND-TEST-TOKEN');
        $installation['id'] = $second['installation_id'];
        $installation['current_binding_id'] = $second['id'];
        DB::table('notification_delivery_installations')->insert($installation);
        DB::table('notification_delivery_bindings')->insert($second);
        $this->seedDeliveries($u, $binding, 1);
        $this->seedDeliveries($u, $second['id'], 1);
        $sender = $this->sender();
        $outbox = app(NotificationOutbox::class);
        $this->assertSame(1, $outbox->drain($sender));
        $this->travel(1)->seconds();
        $this->assertSame(1, $outbox->drain($sender));
        $this->assertCount(2, $sender->seen);
    }

    public function test_mandatory_account_messages_do_not_wait_for_optional_push_slots(): void
    {
        [$u] = $this->registered();
        for ($i = 0; $i < 2; $i++) {
            app(NotificationService::class)->notify('user.account_changed', 'TEST', 'TEST', [$u->user_id],
                sourceModule: 'auth', subjectType: 'user', subjectId: $u->user_id, sourceSnapshot: [], dispatchKey: 'mandatory-'.$i);
        }
        $this->assertSame(2, app(NotificationOutbox::class)->drain($this->sender()));
    }

    public function test_admission_supports_fractional_seconds_and_rejects_invalid_limits(): void
    {
        [$u] = $this->registered();
        $clock = CarbonImmutable::now('UTC')->startOfSecond();
        $gate = app(PushDeliveryThrottle::class);
        foreach ([[1, 60000000], [60, 1000000], [600, 100000]] as [$limit, $micros]) {
            DB::table('notification_push_rate_limits')->delete();
            config(['notification_delivery.max_push_per_user_per_minute' => $limit]);
            $this->assertNull($gate->admit($u->user_id, $clock));
            $this->assertTrue($clock->addMicroseconds($micros)->equalTo($gate->admit($u->user_id, $clock)));
            $this->assertNull($gate->admit($u->user_id, $clock->addMicroseconds($micros)));
        }
        foreach ([0, -1, 601, 'bad', '1.5'] as $bad) {
            config(['notification_delivery.max_push_per_user_per_minute' => $bad]);
            try {
                $gate->admit($u->user_id, $clock);
                $this->fail('Invalid push limit must not admit a send');
            } catch (\UnexpectedValueException $e) {
                $this->assertSame('NOTIFICATION_PUSH_RATE_LIMIT_INVALID', $e->getMessage());
            }
        }
    }

    public function test_sync_connection_never_calls_provider_inline_and_rollback_preserves_atomicity(): void
    {
        [$u] = $this->registered();
        Http::preventStrayRequests();
        Queue::assertNothingPushed();
        DB::beginTransaction();
        app(NotificationService::class)->notify('system.notification.test', 'TEST', 'TEST', [$u->user_id], dispatchKey: 'rollback-queue');
        DB::rollBack();
        $this->assertSame(0, DB::table('notification_deliveries')->count());
        $this->assertSame(0, DB::table('notification_recipients')->count());
        app(NotificationService::class)->notify('system.notification.test', 'TEST', 'TEST', [$u->user_id], dispatchKey: 'sync-queue');
        $this->assertSame(1, DB::table('notification_deliveries')->where('state', 'PENDING')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_lease_expiring_during_policy_checks_never_reaches_the_sender(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        $expired = false;
        DB::listen(function ($query) use (&$expired): void {
            if (! $expired && str_contains($query->sql, 'select') && str_contains($query->sql, 'app_user')) {
                $expired = true;
                $this->travel(121)->seconds();
            }
        });
        $sender = $this->sender();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->assertSame([], $sender->seen);
        $this->assertSame('UNKNOWN', DB::table('notification_deliveries')->value('state'));
    }

    public function test_drain_budget_leaves_unclaimed_work_for_the_next_job(): void
    {
        [$a, $one] = $this->registered();
        [$b, $two] = $this->registered();
        $this->seedDeliveries($a, $one, 1);
        $this->seedDeliveries($b, $two, 1);
        config(['notification_delivery.drain_time_budget_seconds' => 1]);
        $sender = new class implements DeliverySender
        {
            public function send(string $token, array $data): array
            {
                usleep(1_100_000);

                return ['state' => 'PROVIDER_ACCEPTED'];
            }
        };
        $this->assertSame(1, app(NotificationOutbox::class)->drain($sender));
        $pending = DB::table('notification_deliveries')->where('state', 'PENDING')->first();
        $this->assertNotNull($pending);
        $this->assertNull($pending->lease_token);
        $this->assertNull($pending->last_code);
    }

    public function test_failed_queue_wakeup_keeps_committed_history_and_push_recoverable(): void
    {
        [$u] = $this->registered();
        config(['queue.default' => 'redis']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('TEST queue outage'));
        $n = app(NotificationService::class)->notify('system.notification.test', 'TEST', 'TEST', [$u->user_id], dispatchKey: 'queue-outage');
        $this->assertNotNull($n);
        $this->assertDatabaseHas('notification_recipients', ['notification_id' => $n->id]);
        $this->assertSame(1, DB::table('notification_deliveries')->where('state', 'PENDING')->count());
        $this->assertSame(1, app(NotificationOutbox::class)->drain($this->sender()));
    }

    public function test_monitor_still_reports_database_backlog_when_queue_metrics_are_unavailable(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        Queue::shouldReceive('size')->andThrow(new \RuntimeException('TEST queue unavailable'));
        $this->artisan('notifications:monitor', ['--json' => true])->expectsOutputToContain('"status":"alert"')->assertSuccessful();
    }

    public function test_old_replaced_binding_cannot_cascade_delete_waiting_transport_rows(): void
    {
        [$u, $oldId] = $this->registered();
        $old = (array) DB::table('notification_delivery_bindings')->where('id', $oldId)->first();
        DB::table('notification_delivery_bindings')->where('id', $oldId)->update(['status' => 'REPLACED',
            'token_ciphertext' => null, 'token_fingerprint' => null, 'updated_at' => now()->subDays(40)->toIso8601String()]);
        $current = $old;
        $current['id'] = (string) Str::uuid();
        $current['binding_version'] = 2;
        DB::table('notification_delivery_bindings')->insert($current);
        DB::table('notification_delivery_installations')->where('id', $old['installation_id'])->update(['current_binding_id' => $current['id'], 'binding_version' => 2]);
        $this->seedDeliveries($u, $oldId, 4);
        foreach (DB::table('notification_deliveries')->pluck('id') as $i => $id) {
            DB::table('notification_deliveries')->where('id', $id)->update(['state' => ['PENDING', 'RETRYABLE', 'CONFIGURATION_ERROR', 'SENDING'][$i]]);
        }
        app(PruneNotificationDeliveryState::class)->prune(250);
        $this->assertSame(4, DB::table('notification_deliveries')->count());
        $this->assertDatabaseHas('notification_delivery_bindings', ['id' => $oldId]);
    }

    public function test_admission_rechecks_lease_after_waiting_for_the_user_lock(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        $expired = false;
        DB::listen(function ($query) use (&$expired): void {
            if (! $expired && str_contains($query->sql, 'select') && str_contains($query->sql, 'notification_push_rate_limits')) {
                $expired = true;
                $this->travel(121)->seconds();
            }
        });
        $sender = $this->sender();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->assertSame([], $sender->seen);
        $this->assertSame('UNKNOWN', DB::table('notification_deliveries')->value('state'));
        $this->assertSame(0, DB::table('notification_push_rate_limits')->count(), 'Aborted admission must roll back its slot');
    }

    public function test_scheduler_backstop_and_monitor_run_even_when_cache_mutexes_throw(): void
    {
        $cache = \Mockery::mock(SchedulingMutex::class);
        $cache->shouldReceive('create')->andThrow(new \RuntimeException('TEST Redis scheduler mutex unavailable'));
        app()->instance(SchedulingMutex::class, $cache);
        $schedule = app(Schedule::class);
        $events = collect($schedule->events())->filter(fn ($event) => str_contains($event->command ?? '', 'notification-delivery:drain') || str_contains($event->command ?? '', 'notifications:monitor'));
        $this->assertCount(2, $events);
        $executed = 0;
        foreach ($events as $event) {
            $mutex = \Mockery::mock(EventMutex::class);
            $mutex->shouldReceive('create', 'exists', 'forget')->andThrow(new \RuntimeException('TEST Redis event mutex unavailable'));
            $event->mutex = $mutex;
            // Replace only the external child process, keeping the registered schedule and mutex path.
            $event->command = escapeshellarg(PHP_BINARY).' -r "exit(0);"';
            $event->before(function () use (&$executed): void {
                $executed++;
            });
        }
        // Run Laravel's real schedule command, including its global pause-cache polling.
        // Deployment config gives the scheduler its own DB-backed cache, not Redis.
        Cache::setDefaultDriver('notification_scheduler');
        app()->forgetInstance('cache.store');
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('TEST Redis unavailable'));
        (new \ReflectionProperty($schedule, 'events'))->setValue($schedule, $events->values()->all());
        $this->artisan('schedule:run', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame(2, $executed);
    }

    public function test_transport_deadline_is_rechecked_after_the_user_lock(): void
    {
        [$u, $binding] = $this->registered();
        $this->seedDeliveries($u, $binding, 1);
        DB::table('notification_deliveries')->update(['expires_at' => now()->addSecond()->toIso8601String()]);
        $expired = false;
        DB::listen(function ($query) use (&$expired): void {
            if (! $expired && str_contains($query->sql, 'select') && str_contains($query->sql, 'notification_push_rate_limits')) {
                $expired = true;
                $this->travel(2)->seconds();
            }
        });
        $sender = $this->sender();
        $this->assertSame(0, app(NotificationOutbox::class)->drain($sender));
        $this->assertSame('EXPIRED', DB::table('notification_deliveries')->value('state'));
        $this->assertSame(0, DB::table('notification_push_rate_limits')->count());
    }
}
