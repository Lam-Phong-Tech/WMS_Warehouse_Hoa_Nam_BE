<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P18UploadReceiptTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const RIGHTS = ['inbound.view', 'inbound.create', 'inbound.scan', 'outbound.view', 'outbound.scan', 'warranty.view', 'warranty.manage', 'documents.read'];

    public function test_document_upload_receipt_replays_and_reconciles_without_new_attachment(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('private-document.png');
        $key = (string) Str::uuid();
        $headers = $this->uploadHeaders($token, $warehouse, $file, $key);
        $path = $this->path($id);
        $first = $this->post($path, ['file' => $file, 'attachment_type' => 'SOURCE_DOCUMENT'], $headers)->assertCreated();
        $attachment = $first->json('data.id');
        $first->assertJsonPath('meta.upload_receipt.request_key', $key)->assertJsonPath('meta.upload_receipt.outcome', 'SUCCEEDED')
            ->assertJsonPath('meta.upload_receipt.attachment_id', $attachment)->assertJsonPath('meta.upload_receipt.scan_result', 'UNSCANNED');
        $this->post($path, ['file' => $file, 'attachment_type' => 'SOURCE_DOCUMENT'], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.id', $attachment);
        $read = $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt.attachment_id', $attachment)
            ->assertJsonPath('data.current_attachment.status', 'ACTIVE')->assertJsonPath('data.cancel_supported', false)
            ->assertJsonPath('data.retry_policy', 'NONE');
        $this->assertStringNotContainsString('private-document.png', $read->getContent());
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('stock_movements', 0);
        fwrite(STDOUT, "\nP18_ISOLATED_RECEIPT=".json_encode($read->json('data.receipt'), JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_changed_file_metadata_or_parent_cannot_reuse_upload_key(): void
    {
        [, $token, $warehouse] = $this->actor();
        $a = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $b = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        $this->post($this->path($a), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertCreated();
        $this->post($this->path($a), ['file' => $file, 'note' => 'Changed'], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->post($this->path($b), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertConflict();
        $wrongHash = $this->uploadHeaders($token, $warehouse, $file, (string) Str::uuid());
        $wrongHash['X-Upload-SHA256'] = str_repeat('0', 64);
        $this->post($this->path($a), ['file' => $file], $wrongHash)->assertStatus(422)->assertJsonPath('error.code', 'UPLOAD_FILE_HASH_MISMATCH');
        $this->get($this->receiptUrl($b, $key, $warehouse, $file), $this->headers($token))->assertConflict()
            ->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
        $this->assertDatabaseCount('document_attachments', 1);
    }

    public function test_tracked_upload_verifies_receipt_and_rejection_survives_outer_shift_transaction(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false, 'documents.attachment_scan_command' => 'false']);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $file = UploadedFile::fake()->image('blocked-shift.png');
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $identity = ['X-Work-Shift-ID' => $shift['shift_id'], 'X-Request-ID' => $trace];
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key) + $identity)
            ->assertStatus(422)->assertJsonPath('meta.upload_receipt.outcome', 'REJECTED');
        $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE, ['operation_key' => $key, 'state' => 'REJECTED']);
        $this->assertDatabaseHas('p14_shift_operations', ['shift_id' => $shift['shift_id'], 'request_id' => $trace, 'operation' => 'OWNER_REJECTED']);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'REJECTED')->assertJsonPath('data.retry_policy', 'NONE');
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key) + $identity)
            ->assertStatus(422)->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount('p14_shift_operations', 1);
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertDatabaseHas('mini_app_work_shifts', ['shift_id' => $shift['shift_id'], 'version' => 2]);
        $base = '/api/v1/mini-app/work-shifts/'.$shift['shift_id'];
        $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->assertJsonPath('data.can_end', true)->json('data');
        $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
            'local_state' => 'CLEAN', 'pending_request_ids' => []], $this->headers($token) + [
                'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"',
            ])->assertOk();
        $this->getJson($base.'/summary?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.kpis.successful_request_count', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_tracked_successful_upload_has_exact_native_proof_and_invalidates_old_close_check(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $base = '/api/v1/mini-app/work-shifts/'.$shift['shift_id'];
        $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->json('data');
        $file = UploadedFile::fake()->image('shift.png');
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $identity = ['X-Work-Shift-ID' => $shift['shift_id'], 'X-Request-ID' => $trace];
        $upload = $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key) + $identity)
            ->assertCreated()->assertJsonPath('meta.upload_receipt.outcome', 'SUCCEEDED');
        $this->assertDatabaseHas('p14_shift_operations', ['shift_id' => $shift['shift_id'], 'request_id' => $trace, 'operation' => 'OWNER_REQUEST']);
        $proof = DB::table('audit_log')->where('action', 'WORK_SHIFT_OWNER_VERIFIED')->where('request_id', $trace)->first();
        $this->assertNotNull($proof);
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key) + $identity)
            ->assertCreated()->assertJsonPath('data.id', $upload->json('data.id'))->assertHeader('Idempotent-Replay', 'true');
        $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
            'local_state' => 'CLEAN', 'pending_request_ids' => []], $this->headers($token) + [
                'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"',
            ])->assertStatus(412);
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertDatabaseCount('p14_shift_operations', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_tracked_upload_failure_keeps_durable_unknown_and_blocks_end_and_key_rotation(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $base = '/api/v1/mini-app/work-shifts/'.$shift['shift_id'];
        $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->json('data');
        $file = UploadedFile::fake()->image('unknown-shift.png');
        $key = (string) Str::uuid();
        $identity = ['X-Work-Shift-ID' => $shift['shift_id'], 'X-Request-ID' => (string) Str::uuid()];
        DB::listen(static function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, AttachmentUploadReceipts::TABLE)
                && in_array('SUCCEEDED', $event->bindings, true)) {
                throw new \RuntimeException('Isolated terminal receipt persistence failure');
            }
        });
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key) + $identity)->assertStatus(500);
        $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE, ['operation_key' => $key, 'state' => 'UNKNOWN']);
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('p14_shift_operations', 0);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)->assertJsonPath('data.retry_policy', 'CHECK_ONLY');
        $blocked = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->assertJsonPath('data.can_end', false)->assertJsonPath('data.check_token', null)->json('data.blockers');
        $this->assertContains('UPLOAD_OUTCOME_UNRESOLVED', $blocked);
        $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
            'local_state' => 'CLEAN', 'pending_request_ids' => []], $this->headers($token) + [
                'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"',
            ])->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_END_BLOCKED');
        foreach ([$key, (string) Str::uuid()] as $retryKey) {
            $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $retryKey) + $identity)
                ->assertStatus(409)->assertJsonPath('error.code', 'UPLOAD_OUTCOME_UNKNOWN');
        }
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 1);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_tracked_upload_cannot_strip_contract_or_reserve_after_shift_ended(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $identity = ['X-Work-Shift-ID' => $shift['shift_id'], 'X-Request-ID' => (string) Str::uuid()];
        $file = UploadedFile::fake()->image('ended-shift.png');
        $this->post($this->path($id), ['file' => $file], $this->headers($token) + $identity)
            ->assertStatus(428)->assertJsonPath('error.code', 'UPLOAD_IDENTITY_REQUIRED');
        $base = '/api/v1/mini-app/work-shifts/'.$shift['shift_id'];
        $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->json('data');
        $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
            'local_state' => 'CLEAN', 'pending_request_ids' => []], $this->headers($token) + [
                'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"',
            ])->assertOk();
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, (string) Str::uuid()) + $identity)
            ->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 0);
        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_terminal_receipt_change_without_shift_version_invalidates_old_close_check(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('before-shift.png');
        $key = (string) Str::uuid();
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertCreated();
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.shift_id');
        $base = '/api/v1/mini-app/work-shifts/'.$shift;
        $check = $this->getJson($base.'/close-check?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->json('data');
        // Disposable native-row change without a shift-version increment.
        DB::table(AttachmentUploadReceipts::TABLE)->where('operation_key', $key)->update(['updated_at' => now()->addSecond()->utc()->toISOString()]);
        $this->postJson($base.'/end', ['warehouse_id' => $warehouse->id, 'check_token' => $check['check_token'],
            'local_state' => 'CLEAN', 'pending_request_ids' => []], $this->headers($token) + [
                'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"',
            ])->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_CHECK_STALE');
    }

    public function test_soft_deleted_parent_cannot_reserve_upload_intent(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        DB::table('stock_documents')->where('id', $id)->update(['deleted_at' => now()]);
        $file = UploadedFile::fake()->image('deleted-parent.png');
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, (string) Str::uuid()))->assertNotFound();
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 0);
        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_absent_or_pending_receipt_is_unknown_even_if_current_attachment_exists(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        $url = $this->receiptUrl($id, $key, $warehouse, $file);
        $this->get($url, $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null);
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertCreated();
        // Simulated incomplete operation state, not production data repair.
        DB::table(AttachmentUploadReceipts::TABLE)->update(['state' => 'PENDING', 'receipt' => null, 'response_body' => null]);
        $this->get($url, $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')
            ->assertJsonPath('data.current_attachment.status', 'ACTIVE')->assertJsonPath('data.receipt', null)->assertJsonPath('data.retry_policy', 'CHECK_ONLY');
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertConflict()->assertJsonPath('error.code', 'UPLOAD_OUTCOME_UNKNOWN');
        $this->assertDatabaseCount('document_attachments', 1);
    }

    public function test_malware_quarantine_has_durable_rejection_and_never_downloads(): void
    {
        [, $token, $warehouse] = $this->actor();
        config(['documents.attachment_scan_command' => 'false']);
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('blocked.png');
        $key = (string) Str::uuid();
        $response = $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertStatus(422)->assertJsonPath('error.code', 'ATTACHMENT_MALWARE_DETECTED')
            ->assertJsonPath('meta.upload_receipt.outcome', 'REJECTED');
        $attachment = $response->json('meta.upload_receipt.attachment_id');
        $this->assertNotEmpty($attachment);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'REJECTED')->assertJsonPath('data.current_attachment.status', 'QUARANTINED');
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertStatus(422)->assertHeader('Idempotent-Replay', 'true');
        $this->get('/api/v1/mini-app/document-attachments/'.$attachment.'/download', $this->headers($token))->assertNotFound();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('document_attachments', 1);
    }

    public function test_invalid_content_receipt_does_not_fabricate_attachment(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->createWithContent('not-image.png', 'not image content');
        $key = (string) Str::uuid();
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertStatus(422)->assertJsonPath('meta.upload_receipt.outcome', 'REJECTED')->assertJsonPath('meta.upload_receipt.attachment_id', null);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'REJECTED')->assertJsonPath('data.current_attachment', null);
        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_receipt_persistence_failure_rolls_back_attachment_and_cleans_owned_blob(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, AttachmentUploadReceipts::TABLE)
                && in_array('SUCCEEDED', $event->bindings, true)) {
                throw new \RuntimeException('Isolated terminal receipt write failure');
            }
        });
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertStatus(500);
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas(AttachmentUploadReceipts::TABLE, ['operation_key' => $key, 'state' => 'UNKNOWN', 'cleanup_status' => 'CLEANED']);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN');
    }

    public function test_receipt_is_actor_session_scoped_and_refresh_keeps_identity(): void
    {
        [$actor, $token, $warehouse, $cookie] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        $upload = $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertCreated();
        $session = $upload->json('meta.upload_receipt.auth_session_id');
        $this->headers($token);
        $next = $this->browserRefresh($cookie)->assertOk()->json('data.access_token');
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($next))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.auth_session_id', $session);
        [, $other] = $this->actor($warehouse);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($other))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN');
        [$new] = $this->login($actor, $warehouse);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($new))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN');
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($new, $warehouse, $file, $key))
            ->assertConflict()->assertJsonPath('error.code', 'UPLOAD_SESSION_MISMATCH');
        $this->assertDatabaseCount('document_attachments', 1);
    }

    public function test_revoked_read_permission_and_wrong_warehouse_deny_receipts(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        $this->post($this->path($id), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))->assertCreated();
        $other = Warehouse::create(['code' => 'P18-OUTSIDE', 'name' => 'Isolated denied warehouse', 'status' => 'INACTIVE']);
        $this->get($this->receiptUrl($id, $key, $other, $file), $this->headers($token))->assertForbidden();
        $role = $actor->roles()->first();
        DB::table('role_permission')->where('role_id', $role->role_id)->whereIn('permission_id', Permission::where('permission_key', 'inbound.view')->pluck('permission_id'))->delete();
        $actor->forceFill(['version' => $actor->version + 1])->save();
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), $this->headers($token))->assertForbidden();
    }

    public function test_warranty_upload_uses_own_policy_and_persisted_owner(): void
    {
        [, $token, $warehouse] = $this->actor();
        $case = $this->postJson('/api/v1/warranty-cases', ['warehouse_id' => $warehouse->id, 'customer_name' => 'Isolated P18 customer',
            'customer_phone' => '0900000000', 'description' => 'Isolated attachment contract', 'missing_code_reason' => 'Test missing code',
            'manual_product_description' => 'Test unidentified object'], $this->headers($token))->assertCreated()->json('data.warranty_case_id');
        $file = UploadedFile::fake()->image('private-warranty.png');
        $key = (string) Str::uuid();
        $this->post($this->path($case, 'WARRANTY'), ['file' => $file, 'attachment_type' => 'INTAKE'], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertCreated()->assertJsonPath('meta.upload_receipt.parent_type', 'WARRANTY');
        $read = $this->get($this->receiptUrl($case, $key, $warehouse, $file, 'WARRANTY'), $this->headers($token))->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->assertStringNotContainsString('private-warranty.png', $read->getContent());
        $this->post($this->path($case, 'WARRANTY'), ['file' => $file, 'note' => 'Not in owner schema'], $this->uploadHeaders($token, $warehouse, $file, (string) Str::uuid()))
            ->assertStatus(422)->assertJsonPath('error.code', 'UPLOAD_FIELD_NOT_ALLOWED');
        $pdf = UploadedFile::fake()->createWithContent('unsupported.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $pdfKey = (string) Str::uuid();
        $this->post($this->path($case, 'WARRANTY'), ['file' => $pdf], $this->uploadHeaders($token, $warehouse, $pdf, $pdfKey))
            ->assertStatus(422)->assertJsonPath('error.code', 'ATTACHMENT_CONTENT_TYPE_INVALID');
        $this->assertDatabaseCount('warranty_attachments', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_upload_identity_headers_and_parent_auth_are_required(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $file = UploadedFile::fake()->image('file.png');
        $key = (string) Str::uuid();
        $headers = $this->uploadHeaders($token, $warehouse, $file, $key);
        unset($headers['Idempotency-Key']);
        $this->post($this->path($id), ['file' => $file], $headers)->assertStatus(428)->assertJsonPath('error.code', 'UPLOAD_IDENTITY_REQUIRED');
        $this->headers($token);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file), ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertDatabaseCount(AttachmentUploadReceipts::TABLE, 0);
    }

    public function test_new_routes_are_get_only_and_mutation_owners_stay_unchanged(): void
    {
        $reads = [];
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('/p18/', '/'.$route->uri().'/');
            if (str_contains($route->uri(), '/attachments/requests/')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $reads[] = $route->uri();
            }
        }
        $this->assertCount(3, $reads);
        $contract = json_decode(file_get_contents(base_path('contracts/p18/owner-attachments-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($contract['production_activation']);
        $this->assertFalse($contract['cancel_supported']);
        $this->assertSame('P09_APPROVED_OWNER_HANDOVER_V1', $contract['handover']['structured_fields']);
        $this->assertSame('NOT_TRACKED', $contract['location']['breakdown']);
    }

    public function test_outbound_receipt_is_historical_and_does_not_restore_deleted_attachment(): void
    {
        [, $token, $warehouse] = $this->actor();
        $sku = $this->productSkuFixture('P18-OUT-'.Str::upper(Str::random(8)));
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'P18-REQ-'.Str::upper(Str::random(8)));
        $document = $this->outboundDocumentFixture($warehouse->id, $requestId);
        $id = $document['document_id'];
        $file = UploadedFile::fake()->image('outbound.png');
        $key = (string) Str::uuid();
        $first = $this->post($this->path($id, 'OUTBOUND'), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertCreated()->assertJsonPath('meta.upload_receipt.parent_type', 'OUTBOUND');
        $attachment = $first->json('data.id');
        // Simulate a later owner-authorized state change; P18 invokes no DELETE.
        DB::table('document_attachments')->where('id', $attachment)->update(['status' => 'DELETED', 'version' => 2]);
        $this->get($this->receiptUrl($id, $key, $warehouse, $file, 'OUTBOUND'), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt.attachment_status_at_completion', 'ACTIVE')
            ->assertJsonPath('data.current_attachment.status', 'DELETED')->assertJsonPath('data.current_attachment.version', 2);
        $this->post($this->path($id, 'OUTBOUND'), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.id', $attachment);
        $this->get('/api/v1/mini-app/document-attachments/'.$attachment.'/download', $this->headers($token))->assertNotFound();
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_image_size_limits_stay_distinct_for_each_upload_owner(): void
    {
        [, $token, $warehouse] = $this->actor();
        $inbound = $this->createInboundHeader($this->headers($token), $warehouse->id)['document_id'];
        $case = $this->postJson('/api/v1/warranty-cases', ['warehouse_id' => $warehouse->id, 'customer_name' => 'Isolated size test',
            'customer_phone' => '0900000000', 'description' => 'CI', 'missing_code_reason' => 'CI', 'manual_product_description' => 'CI'],
            $this->headers($token))->assertCreated()->json('data.warranty_case_id');
        foreach ([['INBOUND', $inbound, 15361], ['WARRANTY', $case, 10241]] as [$owner, $id, $kilobytes]) {
            $file = UploadedFile::fake()->image('over-limit.png')->size($kilobytes);
            $key = (string) Str::uuid();
            $this->post($this->path($id, $owner), ['file' => $file], $this->uploadHeaders($token, $warehouse, $file, $key))
                ->assertStatus(422)->assertJsonPath('meta.upload_receipt.outcome', 'REJECTED');
            $this->get($this->receiptUrl($id, $key, $warehouse, $file, $owner), $this->headers($token))->assertOk()
                ->assertJsonPath('data.outcome', 'REJECTED')->assertJsonPath('data.current_attachment', null);
        }
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('warranty_attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    private function actor(?Warehouse $warehouse = null): array
    {
        Storage::fake('local');
        config(['documents.disk' => 'local', 'documents.attachment_scan_command' => '', 'warranty.photo_disk' => 'local', 'warranty.attachment_scan_command' => '']);
        $warehouse ??= $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated P18 warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p18_'.$suffix, 'display_name' => 'Isolated P18 actor', 'email' => 'p18_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P18_'.$suffix, 'role_name' => 'Not an authorization inference', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (self::RIGHTS as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $cookie] = $this->login($user, $warehouse);

        return [$user, $token, $warehouse, $cookie];
    }

    private function login(User $actor, Warehouse $warehouse): array
    {
        $this->headers('');
        $login = $this->browserLogin($actor->email, 'secret123')->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($login->json('data.access_token')))
            ->assertOk()->json('data.access_token');

        return [$token, $this->refreshTokenFromCookie($login)];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function uploadHeaders(string $token, Warehouse $warehouse, UploadedFile $file, string $key): array
    {
        return $this->headers($token) + ['X-Upload-Contract' => AttachmentUploadReceipts::CONTRACT, 'X-Upload-SHA256' => hash_file('sha256', $file->getRealPath()),
            'X-Upload-Warehouse-ID' => $warehouse->id, 'Idempotency-Key' => $key];
    }

    private function path(string $id, string $owner = 'INBOUND'): string
    {
        return '/api/v1/mini-app/'.($owner === 'WARRANTY' ? 'warranty-cases' : ($owner === 'INBOUND' ? 'inbound-documents' : 'outbound-documents')).'/'.$id.'/attachments';
    }

    private function receiptUrl(string $id, string $key, Warehouse $warehouse, UploadedFile $file, string $owner = 'INBOUND'): string
    {
        return $this->path($id, $owner).'/requests/'.$key.'?'.http_build_query(['warehouse_id' => $warehouse->id, 'file_sha256' => hash_file('sha256', $file->getRealPath())]);
    }
}
