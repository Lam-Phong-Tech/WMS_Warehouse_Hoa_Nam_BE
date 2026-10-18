<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Tests\TestCase;

/** Real independent PHP processes and PostgreSQL row locks, never a deployment DB. */
class AuthSessionReplacementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        // Each committed-process test owns a disposable schema. Recreate it for
        // isolation; do not exercise unrelated historical down() migrations.
        // TestCase verifies the target contains "test" before this hook runs.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_parallel_logins_leave_exactly_one_active_family(): void
    {
        $user = $this->fixture();
        $results = $this->race([
            ['command' => 'LOGIN', 'user_id' => $user->user_id],
            ['command' => 'LOGIN', 'user_id' => $user->user_id],
        ], $user);
        $this->assertNotSame($results[0]['id'], $results[1]['id']);
        $active = RefreshToken::query()->where('user_id', $user->user_id)->active()->get();
        $this->assertCount(1, $active);
        $this->assertContains((string) $active->first()->id, array_column($results, 'id'));
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)
            ->whereNotNull('replaced_by_auth_session_id')->count());
    }

    public function test_login_racing_refresh_cannot_resurrect_or_revoke_the_new_family(): void
    {
        $user = $this->fixture();
        $a = app(RefreshTokenService::class)->completeLogin($user, Request::create('/'), true);
        $results = $this->race([
            ['command' => 'LOGIN', 'user_id' => $user->user_id],
            ['command' => 'REFRESH', 'raw' => $a['session']['raw']],
        ], $user);
        $active = RefreshToken::query()->where('user_id', $user->user_id)->active()->get();
        $this->assertCount(1, $active);
        $this->assertSame($results[0]['id'], (string) $active->first()->id);
        $old = RefreshToken::query()->findOrFail($a['session']['id']);
        $this->assertNotNull($old->replaced_by_auth_session_id);
        $this->assertNull(app(RefreshTokenService::class)->rotate($a['session']['raw'], Request::create('/')));
        $this->assertNull($active->first()->fresh()->revoked_at);
    }

    private function fixture(): User
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent-process concurrency requires disposable PostgreSQL.');
        }
        config()->set('refresh_token.single_active_session.enabled', true);

        return User::create(['username' => 'session-race-'.Str::uuid(), 'display_name' => 'Isolated race',
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make(bin2hex(random_bytes(24))),
            'status' => 'ACTIVE', 'version' => 1]);
    }

    private function race(array $commands, User $user): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => 'disable',
            'APP_CONFIG_CACHE' => storage_path('framework/nonexistent-race-config-'.Str::uuid().'.php'),
            'APP_KEY' => (string) config('app.key'), 'JWT_SECRET' => bin2hex(random_bytes(32)),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        ]);
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            foreach ($commands as $command) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/auth-session-race-worker.php')],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 30);
                fwrite($pipes[0], json_encode($command, JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("READY\n", fgets($pipes[1]), 'Worker must reach the independent-connection barrier.');
            }
        } finally {
            DB::rollBack();
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Discard arbitrary stderr, never expose credentials/bindings.
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), 'Isolated auth worker failed.');
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
