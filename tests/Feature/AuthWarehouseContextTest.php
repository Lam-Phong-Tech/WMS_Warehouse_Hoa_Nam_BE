<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class AuthWarehouseContextTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const SWITCH = '/api/v1/auth/switch-context';

    private const CURRENT = '/api/v1/mini-app/work-shifts/current';

    private const START = '/api/v1/mini-app/work-shifts/start';

    public function test_existing_context_route_requires_authentication(): void
    {
        $this->postJson(self::SWITCH, ['context_type' => 'WAREHOUSE', 'context_id' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
    }

    public function test_single_allowed_warehouse_is_an_option_not_an_automatic_selection(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.warehouse_context.status', 'REQUIRED')
            ->assertJsonPath('data.available_warehouses.0.warehouse_id', (string) $warehouse->id)
            ->assertJsonCount(1, 'data.available_warehouses');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_confirm_persists_before_start_and_repeated_confirmation_is_idempotent(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $sessionId = $this->me($token)->json('data.auth_session_id');
        $selected = $this->select($token, strtoupper((string) $warehouse->id))->assertOk()
            ->assertJsonPath('data.user.auth_session_id', $sessionId)
            ->assertJsonPath('data.user.active_warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.user.warehouse_context.status', 'READY');
        $newToken = $selected->json('data.access_token');
        $this->assertSame($this->sid($token), $this->sid($newToken));
        $this->me($newToken)->assertOk()->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
        $this->getJson(self::CURRENT, $this->headers($newToken))->assertOk()->assertJsonPath('data', null);
        $confirmedAt = DB::table(WarehouseContext::TABLE)->value('confirmed_at');
        $this->select($newToken, (string) $warehouse->id)->assertOk();
        $this->assertSame($confirmedAt, DB::table(WarehouseContext::TABLE)->value('confirmed_at'));
        $this->assertDatabaseCount(WarehouseContext::TABLE, 1);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 0);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SWITCH_CONTEXT')->count());
    }

    public function test_legacy_scope_ref_alias_selects_the_same_validated_warehouse(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->postJson(self::SWITCH, ['scope_ref' => (string) $warehouse->id], $this->headers($token))
            ->assertOk()->assertJsonPath('data.active_context.context_type', 'WAREHOUSE')
            ->assertJsonPath('data.active_context.context_id', (string) $warehouse->id);
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
    }

    public function test_invalid_uuid_missing_target_alias_mismatch_and_forged_session_are_rejected(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $valid = ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id];
        foreach ([
            ['context_type' => 'WAREHOUSE'],
            ['context_type' => 'WAREHOUSE', 'context_id' => 'WH-01'],
            $valid + ['scope_ref' => (string) Str::uuid()],
            $valid + ['actor_id' => (string) Str::uuid()],
            $valid + ['auth_session_id' => (string) Str::uuid()],
            ['context_type' => 'SYSTEM', 'context_id' => (string) $warehouse->id],
            ['context_type' => 'OTHER'],
        ] as $body) {
            $this->postJson(self::SWITCH, $body, $this->headers($token))->assertUnprocessable();
        }
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_context_id_without_scope_ref_cannot_bypass_warehouse_scope(): void
    {
        [$user] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) Str::uuid())->assertForbidden()->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
    }

    public function test_nonexistent_warehouse_is_not_echoed_as_a_successful_context(): void
    {
        [$user] = $this->actor(false);
        [$token] = $this->login($user);
        $this->select($token, (string) Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'WAREHOUSE_NOT_FOUND');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
    }

    public function test_inactive_warehouse_cannot_be_confirmed(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $warehouse->update(['status' => 'INACTIVE']);
        $this->select($token, (string) $warehouse->id)->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_INACTIVE');
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', null)->assertJsonCount(0, 'data.available_warehouses');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
    }

    public function test_me_revalidates_a_paused_selected_warehouse_without_selecting_another(): void
    {
        [$user, $warehouse] = $this->actor(false);
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        $warehouse->update(['status' => 'INACTIVE']);
        $other = $this->ensureActiveWarehouse('CONTEXT-OTHER');
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.warehouse_context.error_code', 'WAREHOUSE_INACTIVE')
            ->assertJsonPath('data.available_warehouses.0.warehouse_id', (string) $other->id);
        $this->assertDatabaseHas(WarehouseContext::TABLE, ['warehouse_id' => $warehouse->id]);
    }

    public function test_removed_scope_invalidates_selected_context_and_hides_options(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        $user->roles()->detach();
        $user->forceFill(['version' => $user->version + 1])->save();
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.warehouse_context.error_code', 'WAREHOUSE_ACCESS_DENIED')
            ->assertJsonCount(0, 'data.available_warehouses');
        $this->select($token, (string) $warehouse->id)->assertForbidden();
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_refresh_and_reload_preserve_selected_context_before_any_shift(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $sessionId = $this->select($token, (string) $warehouse->id)->assertOk()->json('data.user.auth_session_id');
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($raw)->assertOk()
            ->assertJsonPath('data.user.auth_session_id', $sessionId)
            ->assertJsonPath('data.user.active_warehouse_id', (string) $warehouse->id)
            ->assertJsonMissingPath('data.refresh_token');
        $newToken = $refresh->json('data.access_token');
        $this->assertNotSame($this->sid($token), $this->sid($newToken));
        foreach ([$newToken, $token] as $accessToken) {
            $this->me($accessToken)->assertOk()->assertJsonPath('data.auth_session_id', $sessionId)
                ->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
        }
        $this->getJson(self::CURRENT, $this->headers($newToken))->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_new_login_and_other_actor_do_not_inherit_selected_context(): void
    {
        [$user, $warehouse] = $this->actor();
        [$first] = $this->login($user);
        $firstSession = $this->select($first, (string) $warehouse->id)->assertOk()->json('data.user.auth_session_id');
        [$second] = $this->login($user);
        $newMe = $this->me($second)->assertOk()->assertJsonPath('data.active_warehouse_id', null);
        $this->assertNotSame($firstSession, $newMe->json('data.auth_session_id'));
        [$other] = $this->actor();
        [$third] = $this->login($other);
        $this->me($third)->assertOk()->assertJsonPath('data.active_warehouse_id', null);
        $this->me($first)->assertOk()->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
    }

    public function test_old_shift_receipt_remains_a_valid_explicit_confirmation(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $receipt = $this->start($token, (string) $warehouse->id)->assertCreated()->json('data');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.warehouse_context.status', 'READY');
        $this->getJson(self::CURRENT, $this->headers($token))->assertOk()->assertJsonPath('data', $receipt);
        $warehouse->update(['status' => 'INACTIVE']);
        $this->me($token)->assertOk()->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.warehouse_context.error_code', 'WAREHOUSE_INACTIVE');
    }

    public function test_legacy_session_without_context_can_confirm_then_rotate_without_new_login(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $row = RefreshToken::query()->where('user_id', $user->user_id)->active()->firstOrFail();
        $row->update(['auth_session_id' => null]);
        $this->select($token, (string) $warehouse->id)->assertOk()->assertJsonPath('data.user.auth_session_id', (string) $row->id);
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($raw)->assertOk()
            ->assertJsonPath('data.user.auth_session_id', (string) $row->id)
            ->assertJsonPath('data.user.active_warehouse_id', (string) $warehouse->id);
        $this->me($refresh->json('data.access_token'))->assertOk()->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
    }

    public function test_revoked_session_cannot_restore_or_change_warehouse_context(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        app(RefreshTokenService::class)->revoke($raw);
        // A revoked login must be rejected, not returned as a 200 profile with
        // empty context. Keep the runtime auth gate; strengthen the stale test.
        $this->me($token)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED')
            ->assertJsonMissingPath('data.active_warehouse_id')->assertJsonMissingPath('data.available_warehouses');
        $this->select($token, (string) $warehouse->id)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED');
        $this->getJson(self::CURRENT, $this->headers($token))->assertUnauthorized();
    }

    public function test_expired_session_does_not_expose_a_ready_context(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        RefreshToken::query()->where('user_id', $user->user_id)->update(['expires_at' => now()->subMinute()]);
        $this->me($token)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED')
            ->assertJsonMissingPath('data.active_warehouse_id')->assertJsonMissingPath('data.available_warehouses');
        $this->select($token, (string) $warehouse->id)->assertUnauthorized();
    }

    public function test_access_only_client_cannot_create_a_fake_persistent_context(): void
    {
        [$user, $warehouse] = $this->actor();
        $token = app(RefreshTokenService::class)->accessTokenFor($user, null);
        $this->select($token, (string) $warehouse->id)->assertConflict()->assertJsonPath('error.code', 'AUTH_SESSION_REQUIRED');
        $this->postJson(self::SWITCH, ['context_type' => 'SYSTEM'], $this->headers($token))->assertOk();
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
    }

    public function test_system_can_clear_selection_before_start_without_creating_a_shift(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        $this->postJson(self::SWITCH, ['context_type' => 'SYSTEM'], $this->headers($token))->assertOk()
            ->assertJsonPath('data.user.active_warehouse_id', null);
        $this->me($token)->assertOk()->assertJsonPath('data.warehouse_context.status', 'REQUIRED');
        $this->assertDatabaseCount(WarehouseContext::TABLE, 0);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_shift_prevents_context_change_or_clear(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        $this->start($token, (string) $warehouse->id)->assertCreated();
        $this->select($token, (string) Str::uuid())->assertConflict()->assertJsonPath('error.code', 'SHIFT_WAREHOUSE_CONFLICT');
        $this->postJson(self::SWITCH, ['context_type' => 'SYSTEM'], $this->headers($token))
            ->assertConflict()->assertJsonPath('error.code', 'SHIFT_WAREHOUSE_CONFLICT');
        $this->select($token, (string) $warehouse->id)->assertOk();
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
    }

    public function test_start_cannot_silently_override_confirmed_warehouse(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->select($token, (string) $warehouse->id)->assertOk();
        $this->start($token, (string) Str::uuid())->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_MISMATCH');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 0);
    }

    public function test_confirm_start_refresh_current_and_same_key_retry_keep_one_exact_receipt(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $sessionId = $this->select($token, (string) $warehouse->id)->assertOk()->json('data.user.auth_session_id');
        $key = (string) Str::uuid();
        $receipt = $this->start($token, (string) $warehouse->id, $key)->assertCreated()->json('data');
        $this->assertSame($sessionId, $receipt['auth_session_id']);
        $this->assertSame((string) $user->user_id, $receipt['actor_id']);
        $this->assertSame((string) $warehouse->id, $receipt['warehouse_id']);
        $this->assertSame('ACTIVE', $receipt['status']);
        $this->assertTrue($receipt['preview_ready']);
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($raw)->assertOk();
        $newToken = $refresh->json('data.access_token');
        $this->start($newToken, (string) $warehouse->id, $key)->assertOk()->assertJsonPath('data', $receipt);
        $this->getJson(self::CURRENT, $this->headers($newToken))->assertOk()->assertJsonPath('data', $receipt);
        $this->me($newToken)->assertOk()->assertJsonPath('data.auth_session_id', $sessionId)
            ->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 1);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'WORK_SHIFT_STARTED')->count());
    }

    private function actor(bool $scoped = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('CONTEXT-TEST-WH');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create([
            'username' => 'ctx_'.$suffix, 'display_name' => 'Context test',
            'email' => 'ctx_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $role = Role::create(['role_code' => 'CTX_'.$suffix, 'role_name' => 'Context test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $scoped ? $warehouse->id : null]);
        $permission = Permission::firstOrCreate(['permission_key' => 'inbound.view'], ['permission_name' => 'inbound.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        DB::table('role_permission')->insert([
            'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
            'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $warehouse];
    }

    private function login(User $user): array
    {
        $this->resetAuthentication();
        $response = $this->browserLogin($user->email, 'secret123')->assertOk();

        return [$response->json('data.access_token'), $this->refreshTokenFromCookie($response)];
    }

    private function me(string $token): TestResponse
    {
        return $this->getJson('/api/v1/auth/me', $this->headers($token));
    }

    private function select(string $token, string $id): TestResponse
    {
        return $this->postJson(self::SWITCH, ['context_type' => 'WAREHOUSE', 'context_id' => $id], $this->headers($token));
    }

    private function start(string $token, string $id, ?string $key = null): TestResponse
    {
        return $this->postJson(self::START, ['warehouse_id' => $id], $this->headers($token) + ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function headers(string $token): array
    {
        $this->resetAuthentication();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function sid(string $token): string
    {
        return (string) $this->app['tymon.jwt']->setToken($token)->getPayload()->get('sid');
    }
}
