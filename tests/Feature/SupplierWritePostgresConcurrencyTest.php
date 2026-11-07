<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class SupplierWritePostgresConcurrencyTest extends TestCase
{
    use InteractsWithBrowserSession;

    public function test_parallel_creates_and_competing_edits_use_real_postgresql_locks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent PostgreSQL connections are required to verify supplier locks.');
        }
        $db = DB::connection();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', $db->getConfig('host'));
        $this->assertSame(55439, (int) $db->getConfig('port'));
        $this->assertSame('supplier_api_test', $db->getDatabaseName());
        $this->assertSame('supplier_test', $db->getConfig('username'));
        $this->assertEmpty($db->getConfig('url'));
        // Committed fixtures must be visible to worker connections. Rebuild only
        // this explicitly selected disposable DB; never run supplier down(),
        // which deliberately refuses to remove assigned business identities.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->ensureActiveWarehouse('KHO-TONG-HN');
        $role = Role::firstOrCreate(['role_code' => 'SUPER_ADMIN'], ['role_name' => 'Test admin', 'scope_type' => 'GLOBAL', 'status' => 'ACTIVE']);
        $tokens = [];
        foreach (range(1, 4) as $index) {
            $suffix = substr((string) Str::uuid(), 0, 8);
            $user = User::create(['username' => 'supplier_race_'.$suffix, 'display_name' => 'Test writer',
                'email' => 'race_'.$suffix.'@example.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => null]);
            $this->app['auth']->forgetGuards();
            $this->app['tymon.jwt']->unsetToken();
            $tokens[] = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');
        }
        $workers = [];
        try {
            foreach ($tokens as $index => $token) {
                $workers[] = $this->worker('POST', '/api/v1/mini-app/inbound/suppliers', ['name' => 'Parallel '.$index], $token);
            }
            $responses = $this->responses($workers);
            $codes = [];
            foreach ($responses as $response) {
                $this->assertSame(201, $response['status'], json_encode($response));
                $codes[] = $response['body']['data']['code'];
            }
            sort($codes);
            $this->assertSame(['NCC-001', 'NCC-002', 'NCC-003', 'NCC-004'], $codes);
            $this->assertSame(4, DB::table('inbound_suppliers')->count());
            $id = $responses[0]['body']['data']['id'];
            $workers = [];
            foreach (array_slice($tokens, 0, 2) as $index => $token) {
                $workers[] = $this->worker('PATCH', '/api/v1/mini-app/inbound/suppliers/'.$id, ['name' => 'Competing '.$index], $token, '"1"');
            }
            $updates = $this->responses($workers);
            $statuses = array_column($updates, 'status');
            sort($statuses);
            $this->assertSame([200, 412], $statuses);
            $this->assertSame(2, (int) DB::table('inbound_suppliers')->where('id', $id)->value('version'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    private function worker(string $method, string $path, array $body, string $token, ?string $version = null): Process
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!$app->environment('testing') || $input['db']['driver'] !== 'pgsql'
            || !in_array($input['db']['host'], ['127.0.0.1', 'localhost'], true)
            || !str_contains($input['db']['database'], 'test')) { exit(2); }
        config(['database.default' => 'supplier_race', 'database.connections.supplier_race' => $input['db'],
            'app.key' => $input['app_key'], 'jwt.secret' => $input['jwt_secret'], 'cache.default' => 'array']);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$input['token']];
        if ($input['version'] !== null) { $headers['HTTP_IF_MATCH'] = $input['version']; }
        $request = Illuminate\Http\Request::create($input['path'], $input['method'], [], [], [], $headers, json_encode($input['body']));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
        $kernel->terminate($request, $response);
        PHP;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
        $worker->setInput(json_encode(['db' => DB::connection()->getConfig(), 'app_key' => config('app.key'),
            'jwt_secret' => config('jwt.secret'), 'method' => $method, 'path' => $path, 'body' => $body,
            'token' => $token, 'version' => $version], JSON_THROW_ON_ERROR));
        $worker->setTimeout(120);
        $worker->start();

        return $worker;
    }

    private function responses(array $workers): array
    {
        $responses = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), 'Isolated supplier worker failed: '.$worker->getErrorOutput());
            $responses[] = json_decode(trim($worker->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }

        return $responses;
    }
}
