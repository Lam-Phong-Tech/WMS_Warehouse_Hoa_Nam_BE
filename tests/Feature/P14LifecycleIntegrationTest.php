<?php

namespace Tests\Feature;

use App\Support\ExplicitReadFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable HTTP integration tests only; never run RefreshDatabase on a deployment. */
class P14LifecycleIntegrationTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const SHIFTS = '/api/v1/mini-app/work-shifts';

    protected function setUp(): void
    {
        parent::setUp();
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false, 'p14.recovery_enabled' => false]);
    }

    public function test_start_current_and_end_expose_one_scoped_lifecycle_without_enabling_recovery(): void
    {
        $f = $this->fixture();
        $this->assertSame('p14-shift-v1', $f['shift']['tracking_contract']);
        $this->assertSame('p01-p14-lifecycle-v1', $f['shift']['lifecycle']['contract']);
        $this->assertTrue($f['shift']['lifecycle']['end_supported']);
        $this->assertFalse($f['shift']['lifecycle']['local_state_verified']);
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()->assertJsonPath('data', $f['shift']);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => $f['start_key']])
            ->assertOk()->assertJsonPath('data.shift_id', $f['shift']['shift_id']);
        $receipt = $this->end($f);
        $this->assertSame($f['actor_id'], $receipt['actor_id']);
        $this->assertSame($f['shift']['auth_session_id'], $receipt['auth_session_id']);
        $this->assertSame('END', $receipt['command']);
        $this->assertSame('SUCCEEDED', $receipt['outcome']);
        $this->assertTrue($receipt['login_preserved']);
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()->assertJsonPath('data', null)
            ->assertJsonPath('meta.shift_admission.tracked_family', true)
            ->assertJsonPath('meta.shift_admission.new_tracked_start_supported', true)
            ->assertJsonPath('meta.shift_admission.local_state_verified', false);
        $this->getJson('/api/v1/auth/me', $this->headers($f))->assertOk()->assertJsonPath('data.auth_session_id', $receipt['auth_session_id']);
        $next = $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertNotSame($receipt['shift_id'], $next['shift_id']);
        $this->assertSame($receipt['auth_session_id'], $next['auth_session_id']);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => $f['start_key']])
            ->assertOk()->assertJsonPath('data.status', 'ENDED')->assertJsonPath('data.lifecycle.end_supported', false);
        $this->assertFalse(config('p14.recovery_enabled'));
        $this->assertDatabaseCount('p14_recovery_requests', 0);
    }

    public function test_legacy_active_shift_is_readable_but_is_never_silently_upgraded(): void
    {
        config(['p14.shift_enabled' => false]);
        $f = $this->fixture();
        $this->assertNull($f['shift']['tracking_contract']);
        $this->assertSame('LEGACY_UNTRACKED', $f['shift']['lifecycle']['tracking_status']);
        $this->assertFalse($f['shift']['lifecycle']['end_supported']);
        config(['p14.shift_enabled' => true]);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.shift_id', $f['shift']['shift_id'])->assertJsonPath('data.tracking_contract', null);
        $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertOk()
            ->assertJsonPath('data.can_end', false)->assertJsonPath('data.blockers.0', 'SHIFT_TRACKING_INCOMPLETE');
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('p14_shift_closures', 0);
    }

    public function test_server_generated_trace_cannot_stand_in_for_original_owner_request_identity(): void
    {
        $f = $this->fixture();
        $id = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$id, ['warehouse_id' => $f['warehouse_id'],
            'scan_session_id' => $scan, 'inbound_type' => 'receipt', 'name' => 'No inferred owner key', 'items' => []],
            $this->headers($f) + ['X-Work-Shift-ID' => $f['shift']['shift_id'], 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])
            ->assertStatus(428)->assertJsonPath('error.code', 'SHIFT_COMMAND_IDENTITY_REQUIRED');
        $this->assertDatabaseCount('mini_app_drafts', 0);
        $this->assertDatabaseCount('p14_shift_operations', 0);
    }

    public function test_explicit_reads_distinguish_no_active_shift_without_inventing_clean_or_a_new_start(): void
    {
        $f = $this->fixture();
        $fields = [ExplicitReadFields::HEADER => ExplicitReadFields::CONTRACT];
        $this->getJson($this->url($f, 'close-check'), $this->headers($f) + $fields)->assertOk()
            ->assertJsonPath('meta.contract', ExplicitReadFields::CONTRACT)
            ->assertJsonPath('data.can_end_scope', 'SERVER_CHECK_ONLY')->assertJsonPath('data.can_end', true);
        $this->end($f);
        $response = $this->getJson(self::SHIFTS.'/current', $this->headers($f) + $fields)->assertOk()
            ->assertJsonMissingPath('data')->assertJsonPath('meta.shift_admission.current_state', 'NO_ACTIVE_SHIFT')
            ->assertJsonPath('meta.shift_admission.local_state_verified', false);
        $missing = collect($response->json('meta.read_fields.missing_fields'))->keyBy('path');
        $this->assertSame('NO_ACTIVE_SHIFT', $missing['/data']['status']);
        $this->assertDatabaseCount('mini_app_work_shifts', 1); // No implicit new START.
    }

    public function test_typed_p04_save_keeps_the_native_snapshot_and_can_close_without_a_shadow_draft(): void
    {
        $f = $this->fixture();
        [$id, $scan, $key, $body] = $this->typedSave($f);
        $this->assertDatabaseCount('mini_app_drafts', 1);
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertDatabaseHas('p14_shift_operations', ['shift_id' => $f['shift']['shift_id'], 'operation' => 'OWNER_REQUEST']);
        $before = DB::table('mini_app_drafts')->where('document_id', $id)->first();
        $this->assertSame('mini-app-p04-v1', json_decode($before->form, true)['p04_contract']);
        $this->getJson('/api/v1/mini-app/inbound/drafts/'.$id.'/requests/'.$key.'?'.http_build_query([
            'warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan, 'command' => 'SAVE',
        ]), $this->headers($f))->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->end($f);
        $this->getJson($this->url($f, 'summary'), $this->headers($f))->assertOk()
            ->assertJsonPath('data.immutable', true)->assertJsonPath('data.draft_snapshot.total', 1)
            ->assertJsonPath('data.draft_snapshot.items.0.document_id', $id)
            ->assertJsonPath('data.kpis.quantity_total', null)->assertJsonPath('data.kpis.posted_total', null);
        $this->assertEquals($before, DB::table('mini_app_drafts')->where('document_id', $id)->first());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_typed_p04_record_is_counted_as_one_inbound_record_not_a_stock_post(): void
    {
        $f = $this->fixture();
        $sku = $this->productSkuFixture('P14-TYPED-'.Str::upper(Str::random(6)));
        $items = [['raw_code' => $this->compositeProductQr($sku->sku_code, 'P14-ITEM-001'), 'quantity' => 1, 'scan_source' => 'CAMERA']];
        [$id, $scan, , $body] = $this->typedSave($f, $items);
        // RECORD consumes the saved owner snapshot, never the SAVE body.
        $this->postJson('/api/v1/mini-app/inbound/drafts/'.$id.'/record', $body,
            $this->ownerHeaders($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        $this->assertDatabaseCount('p14_shift_operations', 1);
        $this->assertDatabaseCount('stock_documents', 0);
        $record = $this->postJson('/api/v1/mini-app/inbound/drafts/'.$id.'/record', [
            'warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan,
        ], $this->ownerHeaders($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])
            ->assertCreated()->assertJsonPath('data.posted', false);
        $this->assertDatabaseHas('p14_shift_operations', ['shift_id' => $f['shift']['shift_id'], 'operation' => 'INBOUND', 'business_id' => $record->json('data.document_id')]);
        $this->end($f);
        $this->getJson($this->url($f, 'summary'), $this->headers($f))->assertOk()
            ->assertJsonPath('data.kpis.recorded_documents.INBOUND', 1)
            ->assertJsonPath('data.kpis.successful_request_count', 2)
            ->assertJsonPath('data.kpis.posted_total', null)->assertJsonPath('data.draft_snapshot.total', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_two_saved_native_snapshots_keep_exact_receipts_through_end(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 2; $i++) {
            $id = (string) Str::uuid();
            $scan = (string) Str::uuid();
            $key = (string) Str::uuid();
            $this->putJson('/api/v1/mini-app/drafts/'.$id, [
                'warehouse_id' => $f['warehouse_id'], 'operation' => 'INBOUND', 'scan_session_id' => $scan,
                'codes' => [], 'form' => [],
            ], $this->ownerHeaders($f) + ['Idempotency-Key' => $key, 'If-Match' => '"0"'])->assertCreated();
            $this->assertDatabaseHas('mini_app_draft_receipts', [
                'document_id' => $id, 'request_id' => $key, 'actor_id' => $f['actor_id'],
                'auth_session_id' => $f['shift']['auth_session_id'], 'warehouse_id' => $f['warehouse_id'],
                'scan_session_id' => $scan, 'operation' => 'INBOUND', 'command' => 'SAVE', 'version' => 1,
            ]);
        }
        $this->end($f);
        $this->getJson($this->url($f, 'summary').'&per_page=1', $this->headers($f))->assertOk()
            ->assertJsonPath('data.draft_snapshot.total', 2)->assertJsonCount(1, 'data.draft_snapshot.items');
        $this->assertDatabaseCount('mini_app_drafts', 2);
        $this->assertDatabaseCount('mini_app_draft_receipts', 2);
    }

    public function test_p05_outbound_snapshot_uses_its_existing_p03_owner(): void
    {
        $f = $this->fixture();
        $id = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/drafts/'.$id, [
            'warehouse_id' => $f['warehouse_id'], 'operation' => 'OUTBOUND', 'scan_session_id' => (string) Str::uuid(),
            'codes' => [], 'form' => ['note' => 'Private isolated P05 snapshot'],
        ], $this->ownerHeaders($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertCreated();
        $this->assertDatabaseCount('mini_app_drafts', 1);
        $this->end($f);
        $this->getJson($this->url($f, 'summary'), $this->headers($f))->assertOk()
            ->assertJsonPath('data.draft_snapshot.items.0.operation', 'OUTBOUND')->assertJsonPath('data.draft_snapshot.total', 1);
    }

    public function test_p05_standalone_record_replays_its_native_idempotency_receipt_under_a_tracked_shift(): void
    {
        $f = $this->fixture();
        $sku = $this->productSkuFixture('P14-P05-STANDALONE');
        $this->seedAvailableBalance($f['warehouse_id'], $sku->sku_id, 1);
        $item = $this->seedItemInStock($sku->sku_id);
        $code = 'P14-P05-ITEM-'.Str::upper(Str::random(10));
        $this->seedScanCode($item, $code);
        $body = [
            'warehouse_id' => $f['warehouse_id'],
            'recipient_name' => 'P14 P05 isolated recipient',
            'expected_total_qty' => 1,
            'items' => [['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code, 'scan_source' => 'CAMERA']],
        ];
        $headers = $this->ownerHeaders($f, (string) Str::uuid()) + [
            'Idempotency-Key' => 'p14-p05-'.Str::lower(Str::random(16)),
        ];

        $created = $this->postJson('/api/v1/mini-app/outbound/record', $body, $headers)
            ->assertCreated()->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.name', null)->assertJsonPath('data.stock_effect', 'NONE');
        $this->postJson('/api/v1/mini-app/outbound/record', $body, $headers)
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.document_id', $created->json('data.document_id'));

        $this->assertSame(1, DB::table('p14_shift_operations')->where('shift_id', $f['shift']['shift_id'])
            ->where('operation', 'OUTBOUND')->count());
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_changing_owner_key_or_contract_is_conflict_and_rotating_trace_cannot_repeat_intent(): void
    {
        $f = $this->fixture();
        [$id, , $key, $body, $trace] = $this->typedSave($f);
        $url = '/api/v1/mini-app/inbound/drafts/'.$id;
        $base = ['If-Match' => '"0"', 'Idempotency-Key' => $key];
        $this->putJson($url, $body, $this->ownerHeaders($f, $trace) + array_replace($base, ['Idempotency-Key' => (string) Str::uuid()]))
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_REQUEST_CONFLICT');
        $this->putJson($url, $body, $this->ownerHeaders($f, $trace) + $base + ['X-Component-Issue-Contract' => 'different'])
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_REQUEST_CONFLICT');
        $this->putJson($url, $body, $this->ownerHeaders($f) + $base)
            ->assertConflict()->assertJsonPath('error.code', 'OWNER_RECONCILE_REQUIRED');
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertDatabaseCount('p14_shift_operations', 1);
    }

    public function test_async_202_and_existing_unverified_journals_remain_blocked_without_cleanup(): void
    {
        $f = $this->fixture();
        Route::post('api/v1/mini-app/p14-test-async', fn () => response()->json(['success' => true], 202))->middleware(['api', 'auth:api']);
        $this->postJson('/api/v1/mini-app/p14-test-async', [], $this->ownerHeaders($f))->assertStatus(202);
        $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertOk()->assertJsonPath('data.can_end', false)
            ->assertJsonPath('data.check_token', null)->assertJsonPath('data.blockers.0', 'OWNER_SNAPSHOT_UNSUPPORTED');
        $this->assertDatabaseHas('p14_shift_operations', ['shift_id' => $f['shift']['shift_id'], 'operation' => 'PENDING_OWNER_RESULT']);
        $this->assertDatabaseCount('p14_shift_closures', 0);
    }

    public function test_drain_preserves_fence_and_end_recovery_but_never_starts_another_shift(): void
    {
        $f = $this->fixture();
        config(['p14.shift_enabled' => false, 'p14.shift_drain_enabled' => true]);
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()
            ->assertJsonPath('data.lifecycle.mode', 'DRAINING')->assertJsonPath('data.lifecycle.end_supported', true);
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.Str::uuid(), [], $this->headers($f))->assertStatus(428);
        $this->typedSave($f);
        $receipt = $this->end($f);
        $this->getJson($this->url($f, 'requests/'.$receipt['request_id']), $this->headers($f))->assertOk()->assertJsonPath('data', $receipt);
        $this->getJson($this->url($f, 'summary'), $this->headers($f))->assertOk()->assertJsonPath('data.immutable', true);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(503)->assertJsonPath('error.code', 'SHIFT_START_PAUSED');
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => $f['start_key']])->assertOk()->assertJsonPath('data.status', 'ENDED');
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.Str::uuid(), [], $this->ownerHeaders($f))->assertConflict()->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.Str::uuid(), [], $this->headers($f))->assertStatus(428);
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('p14_shift_closures', 1);
    }

    public function test_rollout_mode_change_invalidates_an_earlier_close_check(): void
    {
        $f = $this->fixture();
        $check = $this->check($f);
        config(['p14.shift_enabled' => false, 'p14.shift_drain_enabled' => true]);
        $this->postJson(self::SHIFTS.'/'.$f['shift']['shift_id'].'/end', [
            'warehouse_id' => $f['warehouse_id'], 'check_token' => $check['check_token'], 'local_state' => 'CLEAN', 'pending_request_ids' => [],
        ], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"'])
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_CHECK_STALE');
        $this->assertDatabaseCount('p14_shift_closures', 0);
        $this->end($f);
    }

    public function test_reads_reject_body_and_uppercase_shift_id_resolves_canonical_identity(): void
    {
        $f = $this->fixture();
        $this->json('GET', $this->url($f, 'close-check'), ['local_state' => 'CLEAN'], $this->headers($f))->assertUnprocessable();
        $this->getJson(self::SHIFTS.'/'.strtoupper($f['shift']['shift_id']).'/close-check?warehouse_id='.$f['warehouse_id'], $this->headers($f))
            ->assertOk()->assertJsonPath('data.shift_id', $f['shift']['shift_id']);
        $this->assertDatabaseCount('p14_shift_operations', 0);
    }

    public function test_p07_confirm_uses_native_durable_receipt_not_a_fake_saved_draft(): void
    {
        $f = $this->fixture();
        $sku = $this->productSkuFixture('P14-NFC-'.Str::upper(Str::random(6)));
        $item = $this->seedItemInStock($sku->sku_id);
        $movementId = (string) Str::uuid();
        $row = ['id' => $movementId, 'movement_no' => 'P14-'.Str::random(12), 'business_key' => 'P14-'.$movementId,
            'movement_type' => 'OPENING', 'warehouse_id' => $f['warehouse_id'], 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1,
            'created_by' => $f['actor_id'], 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $movementId] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $row[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($row);
        $url = '/api/v1/mini-app/items/'.$item.'/nfc';
        $prepared = $this->postJson($url.'/prepare', [], $this->ownerHeaders($f))->assertOk();
        $key = (string) Str::uuid();
        $body = ['hardware_uid' => 'AABBCCDDEE', 'written_payload' => $prepared->json('data.nfc_payload'), 'reservation_token' => $prepared->json('data.reservation_token')];
        $this->patchJson($url, $body, $this->ownerHeaders($f) + ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.confirmation.state', 'SUCCEEDED');
        $this->getJson($url.'/confirmation', $this->headers($f) + ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.state', 'SUCCEEDED');
        $this->assertDatabaseCount('mini_app_drafts', 0);
        $this->assertSame(0, DB::table('p14_shift_operations')->where('operation', 'UNSUPPORTED_OWNER_SAVE')->count());
        $this->end($f);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, DB::table('physical_codes')->where('item_id', $item)->where('carrier_type', 'NFC')->count());
    }

    public function test_p04_validation_and_review_are_check_only_and_do_not_enter_shift_journal(): void
    {
        $f = $this->fixture();
        [$id, $scan] = $this->typedSave($f);
        $beforeVersion = (int) DB::table('mini_app_work_shifts')->where('shift_id', $f['shift']['shift_id'])->value('version');
        $beforeOperations = DB::table('p14_shift_operations')->where('shift_id', $f['shift']['shift_id'])->count();
        $base = ['warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan];

        $this->postJson('/api/v1/mini-app/inbound/drafts/'.$id.'/review', $base,
            $this->headers($f) + ['If-Match' => '"1"'])->assertOk()
            ->assertJsonPath('data.stock_effect', 'NONE')->assertJsonPath('data.scan_evidence_created', false);
        $this->postJson('/api/v1/mini-app/inbound/drafts/'.$id.'/validate-code', $base + [
            'client_event_id' => (string) Str::uuid(),
            'item' => ['raw_code' => 'P14-CHECK-ONLY', 'quantity' => 1, 'scan_source' => 'MANUAL', 'new_sku_type' => 'PRODUCT'],
        ], $this->headers($f) + ['If-Match' => '"1"'])->assertOk()
            ->assertJsonPath('data.stock_effect', 'NONE')->assertJsonPath('data.scan_evidence_created', false)
            ->assertJsonPath('data.event_persisted', false);

        $this->assertSame($beforeOperations, DB::table('p14_shift_operations')->where('shift_id', $f['shift']['shift_id'])->count());
        $this->assertSame($beforeVersion, (int) DB::table('mini_app_work_shifts')->where('shift_id', $f['shift']['shift_id'])->value('version'));
        $this->assertDatabaseCount('scan_record_events', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_p09_owner_receipt_replays_under_shift_and_close_check_remains_supported(): void
    {
        $f = $this->fixture();
        $createKey = 'p09-create-'.Str::lower(Str::random(12));
        $createTrace = (string) Str::uuid();
        $create = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'warehouse_id' => $f['warehouse_id'], 'customer_name' => 'P14 P09 test',
            'customer_phone' => '0901234567', 'description' => 'P14 owner coverage',
            'missing_code_reason' => 'Test fixture', 'manual_product_description' => 'Test fixture',
        ], $this->ownerHeaders($f, $createTrace) + ['Idempotency-Key' => $createKey])
            ->assertCreated()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $caseId = $create->json('data.warranty_case_id');
        $updateKey = 'p09-update-'.Str::lower(Str::random(12));
        $updateTrace = (string) Str::uuid();
        $body = ['warehouse_id' => $f['warehouse_id'], 'note' => 'P14 verified P09 note'];
        $headers = $this->ownerHeaders($f, $updateTrace) + ['Idempotency-Key' => $updateKey, 'If-Match' => '1'];
        $first = $this->patchJson('/api/v1/mini-app/warranty-cases/'.$caseId, $body, $headers)
            ->assertOk()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $this->patchJson('/api/v1/mini-app/warranty-cases/'.$caseId, $body, $headers)
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.receipt.version', $first->json('data.receipt.version'));

        $this->assertSame(2, DB::table('p14_shift_operations')->where('shift_id', $f['shift']['shift_id'])->count());
        $this->assertSame(2, DB::table('p14_shift_operations')->where('shift_id', $f['shift']['shift_id'])->where('operation', 'WARRANTY')->count());
        $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertOk()
            ->assertJsonPath('data.can_end', true)->assertJsonPath('data.blockers', []);
    }

    public function test_disabling_both_flags_never_unfences_a_tracked_active_family(): void
    {
        $f = $this->fixture();
        [$id, $scan, $key] = $this->typedSave($f);
        $before = DB::table('mini_app_drafts')->where('document_id', $id)->first();
        config(['p14.shift_enabled' => false, 'p14.shift_drain_enabled' => false]);
        $url = '/api/v1/mini-app/inbound/drafts/'.Str::uuid();
        $this->putJson($url, [], $this->headers($f))->assertStatus(428)
            ->assertJsonPath('error.code', 'SHIFT_COMMAND_IDENTITY_REQUIRED');
        $this->putJson($url, [], $this->ownerHeaders($f))->assertStatus(503)
            ->assertJsonPath('error.code', 'P14_SHIFT_NOT_ENABLED');
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()
            ->assertJsonPath('data.shift_id', $f['shift']['shift_id'])
            ->assertJsonPath('data.lifecycle.mode', 'DISABLED')->assertJsonPath('data.lifecycle.end_supported', false);
        $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertStatus(503);
        $this->getJson('/api/v1/mini-app/inbound/drafts/'.$id.'/requests/'.$key.'?'.http_build_query([
            'warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan, 'command' => 'SAVE',
        ]), $this->headers($f))->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->assertEquals($before, DB::table('mini_app_drafts')->where('document_id', $id)->first());
        $this->assertDatabaseCount('p14_shift_operations', 1);
        $this->assertDatabaseCount('p14_shift_closures', 0);
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        config(['p14.shift_drain_enabled' => true]);
        $this->end($f);
        $this->assertDatabaseCount('p14_shift_closures', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_disabling_both_flags_after_end_cannot_enable_late_owner_writes(): void
    {
        $f = $this->fixture();
        $receipt = $this->end($f);
        $closure = DB::table('p14_shift_closures')->where('shift_id', $receipt['shift_id'])->first();
        config(['p14.shift_enabled' => false, 'p14.shift_drain_enabled' => false]);
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()->assertJsonPath('data', null)
            ->assertJsonPath('meta.shift_admission.mode', 'DISABLED')
            ->assertJsonPath('meta.shift_admission.tracked_family', true)
            ->assertJsonPath('meta.shift_admission.owner_fence_required', true)
            ->assertJsonPath('meta.shift_admission.new_tracked_start_supported', false)
            ->assertJsonPath('meta.shift_admission.legacy_fallback_authorized', false);
        $url = '/api/v1/mini-app/inbound/drafts/'.Str::uuid();
        $this->putJson($url, [], $this->headers($f))->assertStatus(428);
        $this->putJson($url, [], $this->ownerHeaders($f))->assertStatus(503);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']],
            $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_LIFECYCLE_NOT_ENABLED');
        $this->assertEquals($closure, DB::table('p14_shift_closures')->where('shift_id', $receipt['shift_id'])->first());
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('mini_app_drafts', 0);
        config(['p14.shift_drain_enabled' => true]);
        $this->getJson($this->url($f, 'requests/'.$receipt['request_id']), $this->headers($f))->assertOk()->assertJsonPath('data', $receipt);
    }

    public function test_disabled_mode_preserves_untracked_family_behavior_without_fake_tracking(): void
    {
        config(['p14.shift_enabled' => false]);
        $f = $this->fixture();
        $this->assertNull($f['shift']['tracking_contract']);
        $id = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$id, [
            'warehouse_id' => $f['warehouse_id'], 'scan_session_id' => (string) Str::uuid(),
            'inbound_type' => 'receipt', 'items' => [],
        ], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertCreated();
        $this->assertDatabaseCount('mini_app_drafts', 1);
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertDatabaseCount('p14_shift_operations', 0);
        $this->assertDatabaseCount('p14_shift_closures', 0);
        $this->assertNull(DB::table('mini_app_work_shifts')->where('shift_id', $f['shift']['shift_id'])->value('tracking_contract'));
    }

    public function test_close_check_and_end_require_exact_save_receipt_not_another_command_or_202(): void
    {
        $f = $this->fixture();
        [$id, $scan, $key] = $this->typedSave($f);
        $check = $this->check($f);
        $native = DB::table('mini_app_draft_receipts')->where('document_id', $id)->where('request_id', $key)->first();
        // Fault injection only in the disposable test DB; no production repair.
        foreach ([['scan_session_id' => (string) Str::uuid()], ['operation' => 'OUTBOUND'], ['command' => 'RECORD'], ['response_status' => 202]] as $change) {
            DB::table('mini_app_draft_receipts')->where('document_id', $id)->where('request_id', $key)->update($change);
            $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertOk()
                ->assertJsonPath('data.can_end', false)->assertJsonPath('data.check_token', null)
                ->assertJsonPath('data.blockers.0', 'DRAFT_RECEIPT_UNCONFIRMED');
            $this->postJson(self::SHIFTS.'/'.$f['shift']['shift_id'].'/end', [
                'warehouse_id' => $f['warehouse_id'], 'check_token' => $check['check_token'], 'local_state' => 'CLEAN', 'pending_request_ids' => [],
            ], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"'])
                ->assertConflict()->assertJsonPath('error.code', 'SHIFT_END_BLOCKED');
            $this->assertDatabaseCount('p14_shift_closures', 0);
            $restore = array_intersect_key((array) $native, $change);
            DB::table('mini_app_draft_receipts')->where('document_id', $id)->where('request_id', $key)->update($restore);
        }
        $this->assertEquals($native, DB::table('mini_app_draft_receipts')->where('document_id', $id)->where('request_id', $key)->first());
        $this->end($f);
        $this->assertDatabaseCount('mini_app_drafts', 1);
        $this->assertDatabaseCount('mini_app_draft_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function fixture(): array
    {
        $initial = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $actor = User::findOrFail($initial['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $actor->forceFill(['email' => 'p14-life-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers(['token' => '']);
        $token = $this->browserLogin($actor->email, $secret)->assertOk()->json('data.access_token');
        $f = ['token' => $token, 'actor_id' => (string) $actor->user_id, 'warehouse_id' => (string) $warehouse->id, 'start_key' => (string) Str::uuid()];
        $f['token'] = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse_id']], $this->headers($f))->assertOk()->json('data.access_token');
        $f['shift'] = $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $f['warehouse_id']], $this->headers($f) + ['Idempotency-Key' => $f['start_key']])->assertCreated()->json('data');

        return $f;
    }

    private function headers(array $f): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$f['token']];
    }

    private function ownerHeaders(array $f, ?string $trace = null): array
    {
        return $this->headers($f) + ['X-Work-Shift-ID' => $f['shift']['shift_id'], 'X-Request-ID' => $trace ?? (string) Str::uuid()];
    }

    private function typedSave(array $f, array $items = []): array
    {
        $id = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $body = ['warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan, 'inbound_type' => 'receipt', 'name' => 'P14 isolated inbound', 'items' => $items, 'note' => 'P14 private typed snapshot'];
        $this->putJson('/api/v1/mini-app/inbound/drafts/'.$id, $body, $this->ownerHeaders($f, $trace) + ['Idempotency-Key' => $key, 'If-Match' => '"0"'])->assertCreated();

        return [$id, $scan, $key, $body, $trace];
    }

    private function url(array $f, string $suffix): string
    {
        return self::SHIFTS.'/'.$f['shift']['shift_id'].'/'.$suffix.'?warehouse_id='.$f['warehouse_id'];
    }

    private function check(array $f): array
    {
        return $this->getJson($this->url($f, 'close-check'), $this->headers($f))->assertOk()->assertJsonPath('data.can_end', true)->json('data');
    }

    private function end(array $f): array
    {
        $check = $this->check($f);

        return $this->postJson(self::SHIFTS.'/'.$f['shift']['shift_id'].'/end', [
            'warehouse_id' => $f['warehouse_id'], 'check_token' => $check['check_token'], 'local_state' => 'CLEAN', 'pending_request_ids' => [],
        ], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"'.$check['expected_version'].'"'])->assertOk()->json('data');
    }
}
