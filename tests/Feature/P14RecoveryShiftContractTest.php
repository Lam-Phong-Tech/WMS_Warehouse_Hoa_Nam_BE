<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class P14RecoveryShiftContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const RECOVERY = '/api/v1/auth/recovery-requests';

    private const SHIFTS = '/api/v1/mini-app/work-shifts';

    protected function setUp(): void
    {
        parent::setUp();
        config(['p14.recovery_enabled' => true, 'p14.shift_enabled' => true,
            'p14.support_email' => 'support@p14.example.test', 'p14.recovery_ip_per_hour' => 100,
            'p14.recovery_username_per_hour' => 100]);
    }

    public function test_recovery_is_disabled_until_support_is_configured(): void
    {
        config(['p14.recovery_enabled' => false]);
        $this->postJson(self::RECOVERY, ['username' => 'anything'], $this->proof())->assertStatus(503);
        config(['p14.recovery_enabled' => true, 'p14.support_email' => null]);
        $this->postJson(self::RECOVERY, ['username' => 'anything'], $this->proof())->assertStatus(503);
        $this->assertDatabaseCount('p14_recovery_requests', 0);
    }

    public function test_recovery_existing_and_unknown_username_have_identical_public_semantics(): void
    {
        [$user] = $this->actor();
        foreach ([$user->username, 'p14-nonexistent'] as $name) {
            $response = $this->postJson(self::RECOVERY, ['username' => $name], $this->proof())->assertStatus(202)
                ->assertJsonPath('data.state', 'RECEIVED')->assertJsonPath('data.account_access_granted', false);
            $this->assertStringNotContainsString($name, $response->getContent());
            $this->assertArrayNotHasKey('account_exists', $response->json('data'));
            $row = DB::table('p14_recovery_requests')->where('operation_id', $response->json('data.operation_id'))->first();
            $this->assertSame($name, Crypt::decryptString($row->username_ciphertext));
            $this->assertNotSame($name, $row->username_ciphertext);
        }
        $this->assertSame($user->password_hash, $user->fresh()->password_hash);
    }

    public function test_recovery_receipt_replay_and_reconcile_do_not_create_a_second_request(): void
    {
        $headers = $this->proof();
        $first = $this->postJson(self::RECOVERY, ['username' => 'submitted-user'], $headers)->assertStatus(202);
        $id = $first->json('data.operation_id');
        $this->postJson(self::RECOVERY, ['username' => 'submitted-user'], $headers)->assertStatus(202)->assertJsonPath('data', $first->json('data'));
        $this->getJson(self::RECOVERY.'/operations/'.$id, ['X-Recovery-Proof' => $headers['X-Recovery-Proof']])->assertOk()->assertJsonPath('data', $first->json('data'));
        $this->postJson(self::RECOVERY, ['username' => 'changed'], $headers)->assertConflict();
        $this->assertDatabaseCount('p14_recovery_requests', 1);
        $this->assertStringNotContainsString($headers['X-Recovery-Proof'], $first->getContent());
    }

    public function test_recovery_proof_scope_expiry_and_invalid_ids_are_not_account_oracles(): void
    {
        $h = $this->proof();
        $id = $this->postJson(self::RECOVERY, ['username' => 'submitted-user'], $h)->assertStatus(202)->json('data.operation_id');
        foreach ([$id, (string) Str::uuid(), '123'] as $candidate) {
            $this->getJson(self::RECOVERY.'/operations/'.$candidate, ['X-Recovery-Proof' => $this->proof()['X-Recovery-Proof']])
                ->assertNotFound()->assertJsonPath('error.code', 'RECOVERY_REQUEST_NOT_FOUND');
        }
        $this->getJson(self::RECOVERY.'/operations/'.$id)->assertNotFound();
        $this->travel(25)->hours();
        $this->getJson(self::RECOVERY.'/operations/'.$id, ['X-Recovery-Proof' => $h['X-Recovery-Proof']])->assertStatus(410);
        $this->artisan('p14:recovery-purge')->assertSuccessful();
        $this->assertDatabaseCount('p14_recovery_requests', 0);
    }

    public function test_recovery_rate_limit_and_allowlist_are_enforced(): void
    {
        $this->postJson(self::RECOVERY, ['username' => 'a', 'account_id' => Str::uuid()], $this->proof())->assertStatus(422);
        config(['p14.recovery_username_per_hour' => 1]);
        $this->postJson(self::RECOVERY, ['username' => 'limited-name'], $this->proof())->assertStatus(202);
        $this->postJson(self::RECOVERY, ['username' => 'limited-name'], $this->proof())->assertStatus(429);
        $this->assertDatabaseCount('p14_recovery_requests', 1);
    }

    public function test_support_delivery_is_durable_and_does_not_reset_an_account(): void
    {
        $id = $this->postJson(self::RECOVERY, ['username' => 'support-only'], $this->proof())->assertStatus(202)->json('data.operation_id');
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->artisan('p14:recovery-dispatch')->assertSuccessful();
        $this->artisan('p14:recovery-dispatch')->assertSuccessful();
        $this->assertNotNull(DB::table('p14_recovery_requests')->where('operation_id', $id)->value('delivered_at'));
    }

    public function test_four_shift_routes_require_authentication_and_enabled_gate(): void
    {
        $id = Str::uuid();
        foreach (['close-check', 'summary', 'requests/'.Str::uuid()] as $suffix) {
            $this->getJson(self::SHIFTS.'/'.$id.'/'.$suffix)->assertUnauthorized();
        }
        $this->postJson(self::SHIFTS.'/'.$id.'/end')->assertUnauthorized();
        [, $token, $warehouse, $shift] = $this->actor();
        config(['p14.shift_enabled' => false]);
        $this->getJson($this->url($shift, 'close-check', $warehouse), $this->headers($token))->assertStatus(503);
    }

    public function test_end_is_atomic_idempotent_and_keeps_login_for_the_next_shift(): void
    {
        [, $token, $w, $shift, $cookie, $startKey] = $this->actor();
        $check = $this->check($token, $w, $shift);
        $body = $this->endBody($w, $check);
        $key = (string) Str::uuid();
        $before = DB::table('stock_movements')->count();
        $receipt = $this->postJson(self::SHIFTS.'/'.$shift.'/end', $body, $this->endHeaders($token, $key, $check))->assertOk()
            ->assertJsonPath('data.status', 'ENDED')->assertJsonPath('data.login_preserved', true)->json('data');
        $this->travel(6)->minutes();
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $body, $this->endHeaders($token, $key, $check))->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data', $receipt);
        $this->getJson($this->url($shift, 'requests/'.$key, $w), $this->headers($token))->assertOk()->assertJsonPath('data', $receipt);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk()->assertJsonPath('data.auth_session_id', $receipt['auth_session_id']);
        $this->getJson(self::SHIFTS.'/current', $this->headers($token))->assertOk()->assertJsonPath('data', null);
        $next = $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $w->id], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertNotSame($shift, $next['shift_id']);
        $this->assertSame($receipt['auth_session_id'], $next['auth_session_id']);
        $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $w->id], $this->headers($token) + ['Idempotency-Key' => $startKey])->assertOk()->assertJsonPath('data.shift_id', $shift)->assertJsonPath('data.status', 'ENDED');
        $this->assertDatabaseCount('p14_shift_closures', 1);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_end_rejects_local_dirty_unknown_and_pending_owner_requests(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $check = $this->check($token, $w, $shift);
        foreach ([['local_state' => 'DIRTY'], ['local_state' => 'UNKNOWN'], ['pending_request_ids' => [(string) Str::uuid()]]] as $change) {
            $this->postJson(self::SHIFTS.'/'.$shift.'/end', array_replace($this->endBody($w, $check), $change),
                $this->endHeaders($token, (string) Str::uuid(), $check))->assertConflict()->assertJsonPath('error.code', 'SHIFT_LOCAL_WORK_PENDING');
        }
        $this->assertDatabaseCount('p14_shift_closures', 0);
        $this->assertNull(DB::table('mini_app_work_shifts')->where('shift_id', $shift)->value('ended_at'));
    }

    public function test_end_revalidates_version_scope_expiry_and_body(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $check = $this->check($token, $w, $shift);
        $body = $this->endBody($w, $check);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $body, $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(428);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $body + ['actor_id' => Str::uuid()], $this->endHeaders($token, (string) Str::uuid(), $check))->assertStatus(422);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', array_replace($body, ['check_token' => 'invalid']), $this->endHeaders($token, (string) Str::uuid(), $check))->assertStatus(422);
        $this->travel(6)->minutes();
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $body, $this->endHeaders($token, (string) Str::uuid(), $check))->assertConflict()->assertJsonPath('error.code', 'SHIFT_CHECK_EXPIRED');
        $this->assertDatabaseCount('p14_shift_closures', 0);
    }

    public function test_other_user_other_session_and_warehouse_cannot_end_or_read_a_shift(): void
    {
        [$user, $token, $w, $shift] = $this->actor();
        [, $other] = $this->actor();
        $this->getJson($this->url($shift, 'close-check', $w), $this->headers($other))->assertNotFound();
        $this->getJson($this->url($shift, 'summary', $w), $this->headers($other))->assertNotFound();
        $this->getJson(self::SHIFTS.'/'.$shift.'/close-check?warehouse_id='.Str::uuid(), $this->headers($token))->assertConflict();
        $this->headers($token);
        $newLogin = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');
        $this->getJson($this->url($shift, 'close-check', $w), $this->headers($newLogin))->assertNotFound();
        $this->assertDatabaseCount('p14_shift_closures', 0);
    }

    public function test_inactive_warehouse_and_legacy_shift_are_explicit_blockers(): void
    {
        [, $token, $w, $shift] = $this->actor();
        DB::table('mini_app_work_shifts')->where('shift_id', $shift)->update(['tracking_contract' => null]);
        $this->getJson($this->url($shift, 'close-check', $w), $this->headers($token))->assertOk()->assertJsonPath('data.can_end', false)
            ->assertJsonPath('data.blockers.0', 'SHIFT_TRACKING_INCOMPLETE');
        DB::table('warehouses')->where('id', $w->id)->update(['status' => 'INACTIVE']);
        $response = $this->getJson($this->url($shift, 'close-check', $w), $this->headers($token))->assertOk()->assertJsonPath('data.can_end', false);
        $this->assertContains('WAREHOUSE_INACTIVE', $response->json('data.blockers'));
    }

    public function test_request_not_found_is_unknown_not_permission_to_retry(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $this->getJson($this->url($shift, 'requests/'.Str::uuid(), $w), $this->headers($token))->assertNotFound()
            ->assertJsonPath('error.code', 'SHIFT_REQUEST_UNKNOWN');
        $this->getJson($this->url($shift, 'summary', $w), $this->headers($token))->assertConflict()->assertJsonPath('error.code', 'SHIFT_SUMMARY_NOT_READY');
    }

    public function test_saved_p03_snapshot_is_immutable_paged_and_does_not_change_owner(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $this->saveDraft($token, $w, $shift);
        $this->saveDraft($token, $w, $shift);
        $rows = DB::table('mini_app_drafts')->orderBy('document_id')->get()->map(fn ($r): array => (array) $r)->all();
        $check = $this->check($token, $w, $shift);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $this->endBody($w, $check), $this->endHeaders($token, (string) Str::uuid(), $check))->assertOk();
        $response = $this->getJson($this->url($shift, 'summary', $w).'&per_page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('data.immutable', true)->assertJsonPath('data.draft_snapshot.total', 2)->assertJsonCount(1, 'data.draft_snapshot.items')
            ->assertJsonPath('data.kpis.quantity_total', null)->assertJsonPath('data.kpis.successful_request_count', 2);
        $this->getJson($this->url($shift, 'summary', $w).'&per_page=1&page=2', $this->headers($token))->assertOk()->assertJsonPath('data.draft_snapshot.items.0.document_id', $rows[1]['document_id']);
        $after = DB::table('mini_app_drafts')->orderBy('document_id')->get()->map(fn ($r): array => (array) $r)->all();
        $this->assertSame($rows, $after);
        DB::table('mini_app_drafts')->where('document_id', $rows[0]['document_id'])->update(['title' => 'later owner update']);
        $this->getJson($this->url($shift, 'summary', $w).'&per_page=1', $this->headers($token))->assertOk()->assertJsonPath('data', $response->json('data'));
    }

    public function test_owner_save_after_close_check_invalidates_end_and_late_write_is_rejected(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $old = $this->check($token, $w, $shift);
        $this->saveDraft($token, $w, $shift);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $this->endBody($w, $old), $this->endHeaders($token, (string) Str::uuid(), $old))->assertStatus(412);
        $check = $this->check($token, $w, $shift);
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $this->endBody($w, $check), $this->endHeaders($token, (string) Str::uuid(), $check))->assertOk();
        $this->putJson('/api/v1/mini-app/drafts/'.Str::uuid(), $this->draftBody($w),
            $this->headers($token) + ['X-Work-Shift-ID' => $shift, 'X-Request-ID' => (string) Str::uuid(), 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_NOT_ACTIVE');
        $this->assertDatabaseCount('mini_app_drafts', 1);
    }

    public function test_protocol_requires_explicit_shift_identity_and_does_not_fabricate_native_drafts(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $this->putJson('/api/v1/mini-app/drafts/'.Str::uuid(), $this->draftBody($w),
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertStatus(428);
        $this->assertDatabaseCount('mini_app_drafts', 0);
        DB::table('p14_shift_operations')->insert(['shift_id' => $shift, 'request_id' => Str::uuid(), 'request_hash' => str_repeat('a', 64), 'operation' => 'UNSUPPORTED_OWNER_SAVE', 'completed_at' => now()]);
        $this->getJson($this->url($shift, 'close-check', $w), $this->headers($token))->assertOk()->assertJsonPath('data.can_end', false)
            ->assertJsonPath('data.blockers.0', 'OWNER_SNAPSHOT_UNSUPPORTED');
    }

    public function test_snapshot_failure_rolls_back_end_receipt_and_preserves_active_shift(): void
    {
        [, $token, $w, $shift] = $this->actor();
        $check = $this->check($token, $w, $shift);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'p14_shift_closures')) {
                throw new RuntimeException('Isolated P14 snapshot write fault');
            }
        });
        $this->postJson(self::SHIFTS.'/'.$shift.'/end', $this->endBody($w, $check), $this->endHeaders($token, (string) Str::uuid(), $check))->assertStatus(500);
        $this->assertDatabaseCount('p14_shift_closures', 0);
        $this->assertNull(DB::table('mini_app_work_shifts')->where('shift_id', $shift)->value('ended_at'));
    }

    public function test_revoked_session_and_removed_permission_cannot_close_shift(): void
    {
        [$user, $token, $w, $shift, $cookie] = $this->actor();
        app(RefreshTokenService::class)->revoke($cookie);
        $this->getJson($this->url($shift, 'close-check', $w), $this->headers($token))->assertUnauthorized();
        [, $without, $warehouse, $otherShift] = $this->actor(false);
        $this->getJson($this->url($otherShift, 'close-check', $warehouse), $this->headers($without))->assertForbidden();
    }

    private function actor(bool $rights = true): array
    {
        $w = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated P14 warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p14_'.$suffix, 'display_name' => 'P14 isolated CI', 'email' => 'p14_'.$suffix.'@example.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P14_'.$suffix, 'role_name' => 'P14 scoped test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $w->id]);
        if ($rights) {
            foreach (['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.cancel'] as $key) {
                $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
                DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $p->permission_id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->headers('');
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        $cookie = $this->refreshTokenFromCookie($login);
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $w->id], $this->headers($token))->assertOk();
        $token = $selected->json('data.access_token');
        $startKey = (string) Str::uuid();
        $shift = $rights ? $this->postJson(self::SHIFTS.'/start', ['warehouse_id' => $w->id], $this->headers($token) + ['Idempotency-Key' => $startKey])->assertCreated()->json('data.shift_id') : (string) Str::uuid();

        return [$user, $token, $w, $shift, $cookie, $startKey];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function proof(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'X-Recovery-Proof' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
    }

    private function url(string $shift, string $suffix, object $w): string
    {
        return self::SHIFTS.'/'.$shift.'/'.$suffix.'?warehouse_id='.$w->id;
    }

    private function check(string $token, object $w, string $shift): array
    {
        return $this->getJson($this->url($shift, 'close-check', $w), $this->headers($token))->assertOk()->assertJsonPath('data.can_end', true)->json('data');
    }

    private function endBody(object $w, array $check): array
    {
        return ['warehouse_id' => $w->id, 'check_token' => $check['check_token'], 'local_state' => 'CLEAN', 'pending_request_ids' => []];
    }

    private function endHeaders(string $token, string $key, array $check): array
    {
        return $this->headers($token) + ['Idempotency-Key' => $key, 'If-Match' => '"'.$check['expected_version'].'"'];
    }

    private function draftBody(object $w): array
    {
        return ['warehouse_id' => $w->id, 'operation' => 'INBOUND', 'scan_session_id' => (string) Str::uuid(),
            'title' => 'P14 isolated draft', 'codes' => [['raw_code' => 'P14-ISOLATED', 'quantity' => 2]], 'form' => ['note' => 'Unvalidated snapshot']];
    }

    private function saveDraft(string $token, object $w, string $shift): string
    {
        $id = (string) Str::uuid();
        $this->putJson('/api/v1/mini-app/drafts/'.$id, $this->draftBody($w), $this->headers($token) + [
            'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"', 'X-Work-Shift-ID' => $shift, 'X-Request-ID' => (string) Str::uuid(),
        ])->assertCreated();

        return $id;
    }
}
