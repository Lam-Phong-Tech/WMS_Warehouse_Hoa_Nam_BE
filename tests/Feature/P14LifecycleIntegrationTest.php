<?php

namespace Tests\Feature;

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
        $this->getJson(self::SHIFTS.'/current', $this->headers($f))->assertOk()->assertJsonPath('data', null);
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
        [$id, $scan] = $this->typedSave($f, $items);
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
        $body = ['warehouse_id' => $f['warehouse_id'], 'scan_session_id' => $scan, 'inbound_type' => 'receipt', 'items' => $items, 'note' => 'P14 private typed snapshot'];
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
