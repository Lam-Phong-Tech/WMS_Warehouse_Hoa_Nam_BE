<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NotificationPushQueueConcurrencyTest extends TestCase
{
    public function test_two_processes_share_slots_and_leases_without_duplicate_push_after_restart(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run separately against the dedicated PostgreSQL test cluster on port 55438.');
        }
        $c = config('database.connections.pgsql');
        $this->assertSame('testing', app()->environment());
        $this->assertSame(['127.0.0.1', '55438', 'hn_notification_queue_test', 'hn_notification_test'],
            [$c['host'], (string) $c['port'], $c['database'], $c['username']], 'Refuse destructive tests outside the dedicated disposable DB');
        $this->assertEmpty($c['url'] ?? null);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $clock = CarbonImmutable::parse('2026-11-13T12:00:00Z');
        $u = User::create(['username' => 'concurrent_'.Str::random(10), 'email' => Str::random(10).'@example.test',
            'display_name' => 'Concurrency test', 'password_hash' => Hash::make('TEST-ONLY'), 'status' => 'ACTIVE', 'version' => 1]);
        $this->assertSame([false, true], $this->race('admit', $u->user_id, $clock));
        $this->assertSame(1, DB::table('notification_push_rate_limits')->count());
        DB::table('notification_push_rate_limits')->delete();
        Schema::create('notification_push_test_sends', function (Blueprint $t): void {
            $t->increments('id');
            $t->uuid('delivery_id');
        });
        $family = (string) Str::uuid();
        RefreshToken::create(['user_id' => $u->user_id, 'auth_session_id' => $family,
            'token_hash' => hash('sha256', Str::random(50)), 'expires_at' => $clock->addDays(10)]);
        $installation = (string) Str::uuid();
        $binding = (string) Str::uuid();
        $stamp = $clock->toIso8601String();
        DB::table('notification_delivery_installations')->insert(['id' => $installation, 'proof_hash' => str_repeat('a', 64),
            'platform' => 'ANDROID', 'binding_version' => 1, 'current_binding_id' => $binding, 'created_at' => $stamp, 'updated_at' => $stamp]);
        DB::table('notification_delivery_bindings')->insert(['id' => $binding, 'installation_id' => $installation,
            'user_id' => $u->user_id, 'auth_session_id' => $family, 'binding_version' => 1, 'status' => 'ACTIVE',
            'token_fingerprint' => hash('sha256', 'TEST-ONLY'), 'token_ciphertext' => Crypt::encryptString('TEST-ONLY'),
            'expires_at' => $clock->addDays(10)->toIso8601String(), 'created_at' => $stamp, 'updated_at' => $stamp]);
        foreach (range(1, 2) as $i) {
            $n = (string) Str::uuid();
            $r = (string) Str::uuid();
            DB::table('notifications')->insert(['id' => $n, 'event_key' => 'system.notification.test', 'source_module' => 'notification',
                'title' => 'TEST', 'body' => 'TEST', 'severity' => 'info', 'created_at' => $stamp]);
            DB::table('notification_recipients')->insert(['id' => $r, 'notification_id' => $n, 'user_id' => $u->user_id, 'created_at' => $stamp]);
            DB::table('notification_deliveries')->insert(['id' => (string) Str::uuid(), 'recipient_id' => $r, 'registration_id' => $binding,
                'binding_version' => 1, 'state' => 'PENDING', 'attempts' => 0, 'available_at' => $stamp,
                'expires_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        $this->assertSame([0, 1], $this->race('drain', $u->user_id, $clock));
        $this->assertSame(1, DB::table('notification_push_test_sends')->count());
        $this->assertSame([0, 1], $this->race('drain', $u->user_id, $clock->addSecond()));
        $this->assertSame(2, DB::table('notification_push_test_sends')->distinct()->count('delivery_id'));
        $this->assertSame(2, DB::table('notification_deliveries')->where('state', 'PROVIDER_ACCEPTED')->count());
        $this->assertSame(2, DB::table('notification_recipients')->whereNull('read_at')->count());
        Schema::dropIfExists('notification_push_test_sends');
        // The parent holds the shared user row while both workers begin admission.
        DB::table('notification_push_rate_limits')->update(['next_send_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String()]);
        DB::beginTransaction();
        DB::table('notification_push_rate_limits')->where('user_id', $u->user_id)->lockForUpdate()->first();
        $releasedAt = null;
        $this->assertSame([false, true], $this->race('admit-live', $u->user_id, CarbonImmutable::now('UTC'), function () use (&$releasedAt): void {
            usleep(1_200_000);
            $releasedAt = CarbonImmutable::now('UTC');
            DB::commit();
        }));
        $nextSlot = CarbonImmutable::parse(DB::table('notification_push_rate_limits')->where('user_id', $u->user_id)->value('next_send_at'));
        $this->assertTrue($nextSlot->greaterThanOrEqualTo($releasedAt->addSecond()),
            'The slot starts after the blocking row lock is acquired, not before waiting');
    }

    private function race(string $mode, string $user, CarbonImmutable $clock, ?callable $afterRelease = null): array
    {
        $dir = storage_path('framework/testing/push-race-'.Str::uuid());
        mkdir($dir, 0700, true);
        $barrier = $dir.'/go';
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $workers[$i] = new Process([PHP_BINARY, base_path('tests/Fixtures/notification-push-concurrency-worker.php'),
                    $mode, $user, $barrier, $dir.'/'.$i.'.json', $clock->toIso8601String()], base_path(), [
                        'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
                        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55438', 'DB_DATABASE' => 'hn_notification_queue_test',
                        'DB_USERNAME' => 'hn_notification_test', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array',
                        'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
                    ], null, 60);
                $workers[$i]->start();
            }
            $deadline = microtime(true) + 40;
            while (! is_file($dir.'/0.json.ready') || ! is_file($dir.'/1.json.ready')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail($worker->getErrorOutput().$worker->getOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach the shared barrier');
                }
                usleep(20_000);
                clearstatcache();
            }
            file_put_contents($barrier, 'go');
            if ($afterRelease !== null) {
                $afterRelease();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $results = [json_decode(file_get_contents($dir.'/0.json'), true), json_decode(file_get_contents($dir.'/1.json'), true)];
            sort($results);

            return $results;
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
