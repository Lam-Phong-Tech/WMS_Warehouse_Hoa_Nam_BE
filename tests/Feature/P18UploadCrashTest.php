<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** A committed file DB, not RefreshDatabase savepoints, shared with a dying worker. */
class P18UploadCrashTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;

    public function test_process_exit_keeps_intent_and_blocks_end_and_both_retry_keys(): void
    {
        $root = storage_path('framework/testing/p18-crash-test-'.Str::uuid());
        File::makeDirectory($root, 0700, true);
        $db = $root.'/isolated-test.sqlite';
        touch($db);
        $previous = config('database.default');
        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db,
                'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
                'filesystems.disks.local.root' => $root.'/private', 'documents.disk' => 'local',
                'documents.attachment_scan_command' => '', 'p14.shift_enabled' => false]);
            DB::purge('sqlite');
            Storage::forgetDisk('local');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertSame(0, DB::transactionLevel());
            $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
            $actor = User::create(['username' => 'isolated_crash', 'email' => 'crash@wms-hoanam.test',
                'display_name' => 'Isolated worker', 'password_hash' => Hash::make('fixture-test-only'), 'status' => 'ACTIVE', 'version' => 1]);
            $role = Role::create(['role_code' => 'ISOLATED_CRASH', 'role_name' => 'Fixture', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
            $actor->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
            foreach (['inbound.create', 'inbound.scan', 'inbound.view', 'documents.read'] as $key) {
                $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
                DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                    'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $token = $this->browserLogin($actor->email, 'fixture-test-only')->assertOk()->json('data.access_token');
            $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
            $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $headers)
                ->assertOk()->json('data.access_token');
            $headers['Authorization'] = 'Bearer '.$token;
            $document = $this->createInboundHeader($headers, $warehouse->id)['document_id'];
            config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
            $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
                $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.shift_id');
            $base = '/api/v1/mini-app/work-shifts/'.$shift;
            $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $headers)->assertOk()->json('data');
            $file = UploadedFile::fake()->image('crash-test.png');
            $key = (string) Str::uuid();
            $path = '/api/v1/mini-app/inbound-documents/'.$document.'/attachments';
            $upload = $headers + ['Idempotency-Key' => $key, 'X-Work-Shift-ID' => $shift, 'X-Request-ID' => (string) Str::uuid(),
                'X-Upload-Contract' => AttachmentUploadReceipts::CONTRACT, 'X-Upload-Warehouse-ID' => $warehouse->id,
                'X-Upload-SHA256' => hash_file('sha256', $file->getRealPath())];
            $config = [];
            foreach (['app.env', 'app.key', 'app.timezone', 'jwt', 'database.default', 'database.connections.sqlite',
                'filesystems.disks.local', 'documents', 'p14', 'cache.default', 'queue.default', 'mail.default', 'session.driver'] as $name) {
                $config[$name] = config($name);
            }
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/p18_upload_crash_worker.php')], base_path(), null, null, 60);
            $worker->setInput(json_encode(['config' => $config, 'headers' => $upload, 'path' => $path, 'file' => $file->getRealPath()], JSON_THROW_ON_ERROR));
            $exit = $worker->run();
            $this->assertSame(77, $exit, substr(preg_replace('/Bearer\s+\S+/i', '[REDACTED]', $worker->getOutput().$worker->getErrorOutput()), 0, 1200));
            $this->assertSame('', $worker->getOutput());
            $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE, ['operation_key' => $key, 'state' => 'PENDING']);
            $this->assertDatabaseCount('document_attachments', 0);
            $this->assertDatabaseCount('p14_shift_operations', 0);
            $this->assertCount(1, Storage::disk('local')->allFiles()); // Orphan, not a successful attachment.
            $url = $path.'/requests/'.$key.'?'.http_build_query(['warehouse_id' => $warehouse->id, 'file_sha256' => $upload['X-Upload-SHA256']]);
            $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')
                ->assertJsonPath('data.request_state', 'PENDING')->assertJsonPath('data.retry_policy', 'CHECK_ONLY')->assertJsonPath('data.receipt', null);
            $blocked = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $headers)
                ->assertOk()->assertJsonPath('data.can_end', false)->json('data.blockers');
            $this->assertContains('UPLOAD_OUTCOME_UNRESOLVED', $blocked);
            $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
                'local_state' => 'CLEAN', 'pending_request_ids' => []], $headers + ['Idempotency-Key' => (string) Str::uuid(),
                    'If-Match' => '"'.$check['expected_version'].'"'])->assertStatus(409);
            foreach ([$key, (string) Str::uuid()] as $retry) {
                $upload['Idempotency-Key'] = $retry;
                $this->post($path, ['file' => $file], $upload)->assertStatus(409)->assertJsonPath('error.code', 'UPLOAD_OUTCOME_UNKNOWN');
            }
            $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 1);
            $this->assertDatabaseCount('stock_movements', 0);
        } finally {
            DB::disconnect('sqlite');
            config(['database.default' => $previous]);
            // Only remove the unique test directory created by this method.
            $resolved = realpath($root);
            $testing = realpath(storage_path('framework/testing'));
            if ($resolved !== false && $testing !== false && str_starts_with($resolved, $testing.DIRECTORY_SEPARATOR)
                && str_starts_with(basename($resolved), 'p18-crash-test-')) {
                File::deleteDirectory($resolved);
            }
        }
    }
}
