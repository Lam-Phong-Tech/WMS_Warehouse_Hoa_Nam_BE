<?php

namespace Tests\Feature;

use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Modules\Auth\Events\AuthSessionReplaced;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AccountSessionPolicy;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\RefreshTokenService;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class ControlledMultiSessionTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('refresh_token.single_active_session.enabled', true);
        config()->set('refresh_token.controlled_multi_session.enabled', true);
        config()->set('app.url', 'http://localhost');
    }

    public function test_two_families_survive_and_third_login_is_denied_without_replacement(): void
    {
        Event::fake([AuthSessionReplaced::class]);
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk()
            ->assertJsonPath('data.account_session_policy.version', 'auth-session-policy-v2')
            ->assertJsonPath('data.account_session_policy.mode', 'CONTROLLED_MULTI_SESSION')
            ->assertJsonPath('data.account_session_policy.max_active_families', 2);
        $before = RefreshToken::query()->where('user_id', $user->user_id)->get()->toArray();
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertStatus(409)
            ->assertJsonPath('error_code', 'AUTH_SESSION_LIMIT_REACHED');
        $this->assertEquals($before, RefreshToken::query()->where('user_id', $user->user_id)->get()->toArray());
        Event::assertNotDispatched(AuthSessionReplaced::class);
    }

    public function test_rotation_keeps_one_slot_and_old_access_while_family_is_live(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $family = $a['family'];
        $this->resetAuth();
        $r = $this->browserRefresh($a['refresh'])->assertOk();
        $this->assertSame($family, $r->json('data.user.auth_session_id'));
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->login($user, $password);
        $this->assertSame(2, RefreshToken::query()->where('user_id', $user->user_id)->active()->distinct()->count('auth_session_id'));
    }

    public function test_logout_without_refresh_only_revokes_presented_family(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        $this->postJson('/api/v1/auth/logout', [], $this->headers($a['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_cookie_logout_with_old_rotated_own_token_revokes_entire_own_family_only(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $rotated = $this->browserRefresh($a['refresh'])->assertOk();
        $a2 = (string) $rotated->json('data.access_token');
        $b = $this->login($user, $password);
        $this->withCredentials()->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $a['refresh'])
            ->postJson('/api/v1/auth/logout', [], $this->headers($a2) + ['Origin' => $this->browserOrigin()])->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($a2))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_logout_with_foreign_refresh_cannot_revoke_either_owner(): void
    {
        [$user, $password] = $this->fixture();
        [$other, $otherPassword] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($other, $otherPassword);
        $this->withCredentials()->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $b['refresh'])
            ->postJson('/api/v1/auth/logout', [], $this->headers($a['access']) + ['Origin' => $this->browserOrigin()])
            ->assertStatus(409)->assertJsonPath('error_code', 'AUTH_SESSION_TOKEN_MISMATCH');
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_rotated_token_replay_quarantines_exact_family_not_other_session(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $rotated = $this->browserRefresh($a['refresh'])->assertOk();
        $a2 = (string) $rotated->json('data.access_token');
        $b = $this->login($user, $password);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($a2))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'AUTH_REFRESH_REPLAY_CONTAINED', 'object_id' => $a['family']]);
    }

    public function test_logged_out_and_unknown_legacy_tokens_do_not_destroy_other_family(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        DB::table('refresh_tokens')->where('auth_session_id', $a['family'])->update(['revoked_at' => now()]);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
        $this->resetAuth();
        $this->browserRefresh(bin2hex(random_bytes(32)))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_access_only_login_still_has_family_when_single_flag_is_false(): void
    {
        config()->set('refresh_token.single_active_session.enabled', false);
        [$user] = $this->fixture();
        $this->resetAuth();
        $a = app(RefreshTokenService::class)->completeLogin($user, request(), false);
        $this->assertNotEmpty(RefreshToken::query()->where('user_id', $user->user_id)->value('auth_session_id'));
        $this->getJson('/api/v1/auth/me', $this->headers($a['access_token']))->assertOk();
    }

    public function test_production_or_foreign_origin_activation_fails_before_any_admission(): void
    {
        [$user, $password] = $this->fixture();
        config()->set('app.url', 'https://production.example.test');
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertStatus(503)->assertJsonPath('error_code', 'AUTH_SESSION_POLICY_NOT_READY');
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
    }

    public function test_explicit_global_revoke_remains_account_wide(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        app(RefreshTokenService::class)->revokeAllForUser((string) $user->user_id);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertUnauthorized();
    }

    public function test_wrong_password_and_denied_transport_never_consume_or_revoke_slots(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $this->browserLogin($user->email, bin2hex(random_bytes(24)))->assertUnauthorized();
        $this->resetAuth();
        $this->flushHeaders();
        $this->withHeader('Origin', 'https://untrusted.example.test')->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => $password,
        ])->assertForbidden();
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
    }

    public function test_sidless_access_is_denied_even_if_legacy_single_switch_is_off(): void
    {
        config()->set('refresh_token.single_active_session.enabled', false);
        [$user] = $this->fixture();
        $token = app(RefreshTokenService::class)->accessTokenFor($user, null);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REQUIRED');
    }

    public function test_expired_credential_cannot_quarantine_its_live_successor(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $r = $this->browserRefresh($a['refresh'])->assertOk();
        DB::table('refresh_tokens')->where('token_hash', hash('sha256', $a['refresh']))->update(['expires_at' => now()->subMinute()]);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers((string) $r->json('data.access_token')))->assertOk();
    }

    public function test_no_caller_device_label_or_role_name_can_bypass_family_quota(): void
    {
        [$user, $password] = $this->fixture();
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Isolated role', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => null]);
        $device = (string) Str::uuid();
        foreach ([200, 200, 409] as $status) {
            $this->resetAuth();
            $this->flushHeaders();
            $this->withHeader('X-Device-Id', $device)->withHeader('X-Client-Type', 'web')
                ->browserLogin($user->email, $password)->assertStatus($status);
        }
        $this->assertSame(2, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_token_issuance_failure_rolls_back_admission_without_touching_other_session(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $before = RefreshToken::query()->where('user_id', $user->user_id)->get()->toArray();
        $service = Mockery::mock(RefreshTokenService::class)->makePartial();
        $service->shouldReceive('accessTokenFor')->once()->andThrow(new RuntimeException('Isolated credential issuance failure'));
        try {
            $service->completeLogin($user, request(), true);
            $this->fail('Expected isolated credential issuance failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Isolated credential issuance failure', $error->getMessage());
        }
        $this->assertEquals($before, RefreshToken::query()->where('user_id', $user->user_id)->get()->toArray());
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
    }

    public function test_draining_preserves_existing_sessions_and_refresh_but_denies_new_login(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        config()->set('refresh_token.controlled_multi_session.draining', true);
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertStatus(409)->assertJsonPath('error_code', 'AUTH_SESSION_ADMISSION_DRAINING');
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertOk();
        $this->assertSame('CONTROLLED_MULTI_SESSION_DRAINING', AccountSessionPolicy::contract()['mode']);
    }

    public function test_disabling_controlled_mode_does_not_revive_replaced_history(): void
    {
        config()->set('refresh_token.controlled_multi_session.enabled', false);
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        config()->set('refresh_token.controlled_multi_session.enabled', true);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_existing_mfa_challenge_does_not_allocate_until_verified(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        config()->set('pii.encryption.keys', json_encode(['v1' => 'base64:'.base64_encode(random_bytes(32))]));
        config()->set('pii.blind_index.keys', json_encode(['v1' => 'base64:'.base64_encode(random_bytes(32))]));
        PiiKeyring::flush();
        PiiCrypto::flush();
        $mfa = app(MfaService::class);
        $enrollment = $mfa->beginEnrollment($user);
        $mfa->confirmEnrollment($user, Totp::code($enrollment['secret'], time() - 30));
        $this->resetAuth();
        $challenge = $this->browserLogin($user->email, $password)->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->resetAuth();
        $this->withHeader('Origin', $this->browserOrigin())->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $challenge->json('data.mfa_token'), 'code' => Totp::code($enrollment['secret'], time()),
        ])->assertOk()->assertJsonPath('data.replaced_existing_session', false);
        $this->assertSame(2, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        PiiKeyring::flush();
        PiiCrypto::flush();
    }

    public function test_family_b_cannot_inherit_family_a_warehouse_or_work_shift(): void
    {
        [$user, $password] = $this->fixture();
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Isolated context role', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => null]);
        $warehouse = $this->ensureActiveWarehouse('MULTI-ISOLATED');
        $a = $this->login($user, $password);
        $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($a['access']))->assertOk();
        $shift = (string) Str::uuid();
        DB::table('mini_app_work_shifts')->insert(['shift_id' => $shift, 'actor_id' => $user->user_id,
            'auth_session_id' => $a['family'], 'warehouse_id' => $warehouse->id, 'started_at' => now(), 'tracking_contract' => 'p14-shift-v1']);
        $b = $this->login($user, $password);
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk()
            ->assertJsonPath('data.warehouse_context.status', 'REQUIRED')->assertJsonPath('data.active_warehouse_id', null);
        $this->getJson('/api/v1/mini-app/work-shifts/current', $this->headers($b['access']))->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk()
            ->assertJsonPath('data.auth_session_id', $a['family'])->assertJsonPath('data.active_warehouse_id', (string) $warehouse->id);
        $this->assertDatabaseHas('mini_app_work_shifts', ['shift_id' => $shift, 'auth_session_id' => $a['family'], 'ended_at' => null]);
    }

    public function test_password_change_revokes_both_families_and_does_not_keep_other_sessions(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        $new = 'Aa1!'.bin2hex(random_bytes(20));
        $this->postJson('/api/v1/auth/change-password', ['current_password' => $password,
            'new_password' => $new, 'new_password_confirmation' => $new], $this->headers($a['access']))->assertOk();
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertUnauthorized();
    }

    public function test_audit_failure_cannot_roll_back_replay_quarantine_or_harm_other_family(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertOk();
        $b = $this->login($user, $password);
        AuditLog::creating(fn () => throw new RuntimeException('Isolated audit failure'));
        AuditOutbox::creating(fn () => throw new RuntimeException('Isolated audit outbox failure'));
        $this->resetAuth();
        try {
            app(RefreshTokenService::class)->rotate($a['refresh'], request());
            $this->fail('Expected isolated audit failure.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('audit', $error->getMessage());
        }
        $this->assertSame(0, RefreshToken::query()->where('auth_session_id', $a['family'])->active()->count(), 'Quarantine must remain committed even when audit/outbox fails.');
        $this->assertSame(1, RefreshToken::query()->where('auth_session_id', $b['family'])->active()->count());
    }

    public function test_access_only_quota_slots_expire_with_their_usable_access_credentials(): void
    {
        [$user, $password] = $this->fixture();
        auth('api')->factory()->setTTL(1);
        $tokens = app(RefreshTokenService::class);
        $a = $tokens->completeLogin($user, request(), false);
        $b = $tokens->completeLogin($user, request(), false);
        $this->assertNull($a['session']);
        $this->assertNull($b['session']);
        $this->travel(2)->minutes();
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count(), 'Expired access-only clients must not strand both quota slots.');
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertOk();
    }

    private function fixture(): array
    {
        $password = bin2hex(random_bytes(24));
        $user = User::create(['username' => 'multi-'.Str::uuid(), 'display_name' => 'Isolated multi-session',
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1]);

        return [$user, $password];
    }

    private function login(User $user, string $password): array
    {
        $this->resetAuth();
        $this->flushHeaders();
        $r = $this->browserLogin($user->email, $password)->assertOk();

        return ['access' => (string) $r->json('data.access_token'), 'refresh' => $this->refreshTokenFromCookie($r),
            'family' => (string) $r->json('data.user.auth_session_id')];
    }

    private function headers(string $token): array
    {
        $this->resetAuth();
        $this->flushHeaders();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
