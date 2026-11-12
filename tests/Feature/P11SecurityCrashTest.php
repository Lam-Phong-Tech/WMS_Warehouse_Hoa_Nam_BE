<?php

namespace Tests\Feature;

use App\Support\Security\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use Modules\Auth\Support\P11SecurityCommands as Native;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** A committed disposable DB shared with a dying process, not PHPUnit savepoint evidence. */
class P11SecurityCrashTest extends TestCase
{
    use InteractsWithBrowserSession;

    public function test_process_death_keeps_intent_but_not_password_effect_and_never_allows_replay(): void
    {
        $root = storage_path('framework/testing/p11-crash-test-'.Str::uuid());
        File::makeDirectory($root, 0700, true);
        touch($root.'/isolated-test.sqlite');
        $previous = config('database.default');
        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/isolated-test.sqlite',
                'database.connections.sqlite.url' => null, 'p11.security_commands_enabled' => true]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertSame(0, DB::transactionLevel());
            $old = bin2hex(random_bytes(18));
            $new = 'New#Pass2026';
            $user = User::create(['username' => 'p11_crash', 'email' => 'p11crash@wms-hoanam.test',
                'display_name' => 'Isolated worker', 'password_hash' => Hash::make($old), 'status' => 'ACTIVE', 'version' => 1]);
            $token = $this->browserLogin($user->email, $old)->assertOk()->json('data.access_token');
            UserMfa::create(['user_id' => $user->user_id, 'secret' => Totp::generateSecret(), 'confirmed_at' => now()->utc()]);
            $key = (string) Str::uuid();
            $headers = ['Authorization' => 'Bearer '.$token, Native::HEADER => Native::CONTRACT,
                'Idempotency-Key' => $key, 'X-Request-ID' => (string) Str::uuid(), 'Accept' => 'application/json'];
            $body = ['current_password' => $old, 'new_password' => $new, 'new_password_confirmation' => $new];
            $config = [];
            foreach (['app.env', 'app.key', 'app.timezone', 'jwt', 'database.default', 'database.connections.sqlite',
                'pii', 'p11', 'cache.default', 'queue.default', 'mail.default', 'session.driver'] as $name) {
                $config[$name] = config($name);
            }
            $child = new Process([PHP_BINARY, base_path('tests/Fixtures/p11_security_crash_worker.php')], base_path(), null, null, 60);
            $child->setInput(json_encode(compact('config', 'headers', 'body'), JSON_THROW_ON_ERROR));
            $this->assertSame(77, $child->run(), 'Worker must exit at the injected native commit boundary.');
            $this->assertSame('', $child->getOutput());
            $this->assertTrue(Hash::check($old, $user->fresh()->password_hash));
            $this->assertFalse(Hash::check($new, $user->fresh()->password_hash));
            $this->assertDatabaseHas(Native::TABLE, ['actor_id' => $user->user_id, 'operation_key' => $key, 'state' => 'PENDING']);
            $this->resetGuards();
            $this->get('/api/v1/auth/security-requests/'.$key, $headers)->assertOk()
                ->assertJsonPath('data.receipt.state', 'UNKNOWN')->assertJsonPath('data.receipt.retry_policy', 'CHECK_ONLY');
            $this->resetGuards();
            $this->postJson('/api/v1/auth/change-password', $body, $headers)->assertStatus(503);
            $this->resetGuards();
            $headers['Idempotency-Key'] = (string) Str::uuid();
            $this->postJson('/api/v1/auth/change-password', $body, $headers)->assertConflict()
                ->assertJsonPath('error.code', 'SECURITY_COMMAND_UNKNOWN');
            $this->assertDatabaseCount(Native::TABLE, 1);
            $this->assertDatabaseCount('stock_movements', 0);
        } finally {
            DB::disconnect('sqlite');
            config(['database.default' => $previous]);
            $resolved = realpath($root);
            $testing = realpath(storage_path('framework/testing'));
            if ($resolved !== false && $testing !== false && str_starts_with($resolved, $testing.DIRECTORY_SEPARATOR)
                && str_starts_with(basename($resolved), 'p11-crash-test-')) {
                File::deleteDirectory($resolved);
            }
        }
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
