<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class MiniAppWorkShiftTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const CURRENT = '/api/v1/mini-app/work-shifts/current';

    private const START = '/api/v1/mini-app/work-shifts/start';

    public function test_routes_require_authentication(): void
    {
        $this->getJson(self::CURRENT)->assertUnauthorized();
        $this->postJson(self::START, ['warehouse_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_login_and_me_expose_stable_session_and_null_unselected_warehouse(): void
    {
        [$user, $warehouse] = $this->actor();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $id = $login->json('data.user.auth_session_id');
        $this->assertTrue(Str::isUuid($id));
        $login->assertJsonPath('data.user.active_warehouse_id', null)->assertJsonMissingPath('data.refresh_token');
        $headers = ['Authorization' => 'Bearer '.$login->json('data.access_token')];
        $this->getJson('/api/v1/auth/me', $headers)->assertOk()
            ->assertJsonPath('data.auth_session_id', $id)
            ->assertJsonPath('data.active_warehouse_id', null);
        $this->getJson(self::CURRENT, $headers)->assertOk()->assertJsonPath('data', null);
        $this->getJson(self::CURRENT, $headers)->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 0);
    }

    public function test_start_persists_and_reload_returns_exact_same_receipt(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $created = $this->start($token, $warehouse->id)->assertCreated();
        $data = $created->json('data');
        $this->assertTrue(Str::isUuid($data['shift_id']));
        $this->assertSame((string) $user->user_id, $data['actor_id']);
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertTrue($data['preview_ready']);
        $this->assertDatabaseHas('mini_app_work_shifts', ['shift_id' => $data['shift_id'], 'warehouse_id' => $warehouse->id]);
        $this->getJson(self::CURRENT, $this->headers($token))->assertOk()->assertJsonPath('data', $data);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk()
            ->assertJsonPath('data.active_warehouse_id', $warehouse->id);
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'WORK_SHIFT_STARTED')->count());
    }

    public function test_repeated_key_and_different_key_do_not_create_another_shift_or_audit(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $key = (string) Str::uuid();
        $data = $this->start($token, $warehouse->id, $key)->assertCreated()->json('data');
        $this->start($token, $warehouse->id, strtoupper($key))->assertOk()->assertJsonPath('data', $data);
        $this->start($token, $warehouse->id)->assertOk()->assertJsonPath('data', $data);
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 2);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'WORK_SHIFT_STARTED')->count());
    }

    public function test_lost_response_can_be_reconciled_by_current_and_same_key_after_refresh(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $key = (string) Str::uuid();
        $receipt = $this->start($token, $warehouse->id, $key)->assertCreated()->json('data');
        $oldRowId = RefreshToken::query()->active()->where('user_id', $user->user_id)->firstOrFail()->id;
        $refresh = $this->browserRefresh($raw)->assertOk();
        $newToken = (string) $refresh->json('data.access_token');
        $newRow = RefreshToken::query()->active()->where('user_id', $user->user_id)->firstOrFail();
        $this->assertNotSame($oldRowId, $newRow->id);
        $this->assertSame($receipt['auth_session_id'], $newRow->auth_session_id);
        $refresh->assertJsonPath('data.user.auth_session_id', $receipt['auth_session_id']);
        $this->getJson(self::CURRENT, $this->headers($newToken))->assertOk()->assertJsonPath('data', $receipt);
        $this->start($newToken, $warehouse->id, $key)->assertOk()->assertJsonPath('data', $receipt);
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
    }

    public function test_same_key_with_changed_body_is_conflict_and_does_not_write(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $key = (string) Str::uuid();
        $this->start($token, $warehouse->id, $key)->assertCreated();
        $this->start($token, (string) Str::uuid(), $key)->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
        $this->assertDatabaseCount('mini_app_work_shift_keys', 1);
    }

    public function test_different_key_cannot_silently_switch_warehouse(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->start($token, $warehouse->id)->assertCreated();
        $this->start($token, (string) Str::uuid())->assertConflict()
            ->assertJsonPath('error.code', 'SHIFT_WAREHOUSE_CONFLICT');
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
    }

    public function test_missing_or_invalid_key_and_forged_actor_are_rejected(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->postJson(self::START, ['warehouse_id' => $warehouse->id], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->start($token, $warehouse->id, 'not-a-uuid')->assertUnprocessable();
        $this->start($token, 'WH-01')->assertUnprocessable();
        $this->postJson(self::START, ['warehouse_id' => $warehouse->id, 'actor_id' => (string) Str::uuid()],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SHIFT_FIELD_NOT_ALLOWED');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_warehouse_scope_is_enforced_without_creating_a_receipt(): void
    {
        [$user] = $this->actor();
        [$token] = $this->login($user);
        $this->start($token, (string) Str::uuid())->assertForbidden()
            ->assertJsonPath('error.code', 'WAREHOUSE_ACCESS_DENIED');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_account_without_mini_app_permissions_cannot_read_or_start(): void
    {
        [$user, $warehouse] = $this->actor(false);
        [$token] = $this->login($user);
        $this->getJson(self::CURRENT, $this->headers($token))->assertForbidden();
        $this->start($token, $warehouse->id)->assertForbidden();
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_inactive_warehouse_blocks_existing_current_and_start(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->start($token, $warehouse->id)->assertCreated();
        $warehouse->update(['status' => 'INACTIVE']);
        $this->getJson(self::CURRENT, $this->headers($token))->assertConflict()
            ->assertJsonPath('error.code', 'WAREHOUSE_INACTIVE');
        $this->start($token, $warehouse->id)->assertConflict()
            ->assertJsonPath('error.code', 'WAREHOUSE_INACTIVE');
    }

    public function test_another_login_and_another_actor_cannot_restore_previous_shift(): void
    {
        [$user, $warehouse] = $this->actor();
        [$first] = $this->login($user);
        $receipt = $this->start($first, $warehouse->id)->assertCreated()->json('data');
        [$second] = $this->login($user);
        $this->getJson(self::CURRENT, $this->headers($second))->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/auth/me', $this->headers($second))->assertOk()
            ->assertJsonPath('data.active_warehouse_id', null);
        [$other] = $this->actor();
        [$otherToken] = $this->login($other);
        $this->getJson(self::CURRENT, $this->headers($otherToken))->assertOk()->assertJsonPath('data', null);
        $this->getJson(self::CURRENT, $this->headers($first))->assertOk()->assertJsonPath('data', $receipt);
    }

    public function test_revoked_refresh_session_is_not_recovered_from_a_still_valid_jwt(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $this->start($token, $warehouse->id)->assertCreated();
        app(RefreshTokenService::class)->revoke($raw);
        $this->getJson(self::CURRENT, $this->headers($token))->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED');
        $this->start($token, $warehouse->id)->assertUnauthorized();
        $this->assertDatabaseCount('mini_app_work_shifts', 1);
    }

    public function test_expired_refresh_session_does_not_look_like_no_shift(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $this->start($token, $warehouse->id)->assertCreated();
        RefreshToken::query()->where('user_id', $user->user_id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson(self::CURRENT, $this->headers($token))->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_SESSION_EXPIRED');
    }

    public function test_access_only_login_has_no_fake_session_and_cannot_start(): void
    {
        [$user, $warehouse] = $this->actor();
        $token = app(RefreshTokenService::class)->accessTokenFor($user, null);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk()
            ->assertJsonPath('data.auth_session_id', null);
        $this->start($token, $warehouse->id)->assertConflict()
            ->assertJsonPath('error.code', 'AUTH_SESSION_REQUIRED');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_legacy_token_row_keeps_its_identity_after_first_rotation(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token, $raw] = $this->login($user);
        $row = RefreshToken::query()->where('user_id', $user->user_id)->active()->firstOrFail();
        $row->update(['auth_session_id' => null]);
        $receipt = $this->start($token, $warehouse->id)->assertCreated()->json('data');
        $this->assertSame((string) $row->id, $receipt['auth_session_id']);
        $refresh = $this->browserRefresh($raw)->assertOk();
        $this->getJson(self::CURRENT, $this->headers($refresh->json('data.access_token')))
            ->assertOk()->assertJsonPath('data', $receipt);
    }

    public function test_database_unique_constraint_is_a_backstop_against_duplicate_session_shifts(): void
    {
        [$user, $warehouse] = $this->actor();
        [$token] = $this->login($user);
        $receipt = $this->start($token, $warehouse->id)->assertCreated()->json('data');
        $this->expectException(QueryException::class);
        DB::table('mini_app_work_shifts')->insert([
            'shift_id' => (string) Str::uuid(), 'actor_id' => $user->user_id,
            'warehouse_id' => $warehouse->id, 'auth_session_id' => $receipt['auth_session_id'],
            'started_at' => now()->utc()->format('Y-m-d H:i:sP'),
        ]);
    }

    private function actor(bool $hasPermission = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('SHIFT-TEST-WH');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create([
            'username' => 'shift_'.$suffix, 'display_name' => 'Work shift test',
            'email' => 'shift_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $role = Role::create([
            'role_code' => 'SHIFT_'.$suffix, 'role_name' => 'Work shift test',
            'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE',
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id,
        ]);
        if ($hasPermission) {
            $permission = Permission::firstOrCreate(['permission_key' => 'inbound.view'], [
                'permission_name' => 'inbound.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE',
            ]);
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$user, $warehouse];
    }

    private function login(User $user): array
    {
        $this->resetRequestAuthentication();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();

        return [(string) $login->json('data.access_token'), $this->refreshTokenFromCookie($login)];
    }

    private function headers(string $token): array
    {
        $this->resetRequestAuthentication();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetRequestAuthentication(): void
    {
        // HTTP tests share a container. Forgetting the guard alone does not
        // clear the JWT singleton's cached token. Model a fresh HTTP request
        // when switching between independent users/devices (including back).
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function start(string $token, string $warehouseId, ?string $key = null)
    {
        return $this->postJson(self::START, ['warehouse_id' => $warehouseId],
            $this->headers($token) + ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
