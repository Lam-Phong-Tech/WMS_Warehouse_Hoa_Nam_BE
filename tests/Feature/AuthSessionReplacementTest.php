<?php

namespace Tests\Feature;

use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Events\AuthSessionReplaced;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\RefreshTokenService;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/** Generated accounts and credentials in a disposable test database only. */
class AuthSessionReplacementTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('refresh_token.single_active_session.enabled', true);
        Route::post('/api/v1/uat05/protected-command', fn () => response()->json(['accepted' => true]))
            ->middleware('auth:api');
    }

    public function test_login_b_rejects_access_and_refresh_a_without_revoking_b(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);

        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))
            ->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED')
            ->assertJsonStructure(['data' => ['replacement' => ['device_label', 'signed_in_at']]]);
        $this->postJson('/api/v1/uat05/protected-command', [], $this->headers($a['access']))
            ->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk()
            ->assertJsonPath('data.account_session_policy.version', 'auth-session-replacement-v1')
            ->assertJsonPath('data.account_session_policy.scope', 'ACCOUNT_ALL_CLIENTS');
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_replaced_refresh_describes_new_login_without_exposing_raw_agent_or_ip(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $this->flushHeaders();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0')
            ->browserLogin($user->email, $password)->assertOk();
        $this->resetAuth();
        $response = $this->browserRefresh($a['refresh'])->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED')
            ->assertJsonPath('replacement.device_label', 'Chrome trên Android')
            ->assertJsonPath('replacement.ip_hint', '127.0.0.xxx');

        $this->assertNotEmpty($response->json('replacement.signed_in_at'));
        $this->assertArrayNotHasKey('ip_address', $response->json('replacement'));
        $this->assertArrayNotHasKey('user_agent', $response->json('replacement'));
    }

    public function test_completed_login_reports_whether_it_replaced_an_active_session(): void
    {
        [$user, $password] = $this->fixture();
        $this->resetAuth();
        $first = $this->browserLogin($user->email, $password)->assertOk()
            ->assertJsonPath('data.replaced_existing_session', false);
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertOk()
            ->assertJsonPath('data.replaced_existing_session', true);
        $this->assertNotEmpty($this->refreshTokenFromCookie($first));
    }

    public function test_completed_replacement_broadcasts_to_the_account_after_login(): void
    {
        Event::fake([AuthSessionReplaced::class]);
        [$user, $password] = $this->fixture();

        $this->browserLogin($user->email, $password)->assertOk()
            ->assertJsonPath('data.replaced_existing_session', false);
        Event::assertNotDispatched(AuthSessionReplaced::class);

        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertOk()
            ->assertJsonPath('data.replaced_existing_session', true);

        Event::assertDispatchedTimes(AuthSessionReplaced::class, 1);
        Event::assertDispatched(AuthSessionReplaced::class, fn ($event) =>
            $event->userId === (string) $user->user_id
            && $event->broadcastAs() === 'auth.session-replaced'
            && $event->broadcastOn()[0]->name === 'private-user.'.$user->user_id,
        );
    }

    public function test_wrong_password_or_denied_transport_never_replaces_an_active_login(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $this->browserLogin($user->email, bin2hex(random_bytes(24)))->assertUnauthorized();
        $this->resetAuth();
        $this->withHeader('Origin', 'https://untrusted.example.test')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertForbidden();
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->assertNull(RefreshToken::query()->where('user_id', $user->user_id)->value('replaced_by_auth_session_id'));
    }

    public function test_historical_revoked_refresh_cannot_revoke_a_later_completed_login(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        app(RefreshTokenService::class)->revokeAllForUser((string) $user->user_id);
        $b = $this->login($user, $password);
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_account_scope_does_not_replace_another_user(): void
    {
        [$user, $password] = $this->fixture();
        [$other, $otherPassword] = $this->fixture();
        $a = $this->login($user, $password);
        $otherSession = $this->login($other, $otherPassword);
        $this->login($user, $password);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($otherSession['access']))->assertOk();
    }

    public function test_rotated_ancestors_are_replaced_and_late_replay_cannot_destroy_successor(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $rotated = $this->browserRefresh($a['refresh'])->assertOk();
        $nextRefresh = $this->refreshTokenFromCookie($rotated);
        $nextAccess = (string) $rotated->json('data.access_token');
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $b = $this->login($user, $password);
        foreach ([$a['access'], $nextAccess] as $token) {
            $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized()
                ->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        }
        foreach ([$a['refresh'], $nextRefresh] as $raw) {
            $this->resetAuth();
            $this->browserRefresh($raw)->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        }
        $this->resetAuth();
        $this->browserRefresh($b['refresh'])->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
    }

    public function test_failed_token_issuance_rolls_back_replacement_metadata_and_revocation(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $tokens = Mockery::mock(RefreshTokenService::class)->makePartial();
        $tokens->shouldReceive('accessTokenFor')->once()->andThrow(new RuntimeException('isolated issuance failure'));
        try {
            $tokens->completeLogin($user->fresh(), Request::create('/api/v1/auth/login'), true);
            $this->fail('Token issuance was expected to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('isolated issuance failure', $error->getMessage());
        }
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->count());
        $this->assertNull(RefreshToken::query()->where('user_id', $user->user_id)->value('replaced_by_auth_session_id'));
    }

    public function test_access_only_and_native_login_obey_same_policy_without_exposing_browser_refresh(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $this->resetAuth();
        $this->flushHeaders();
        $accessOnly = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->assertJsonMissingPath('data.refresh_token');
        $this->assertNull($this->refreshCookie($accessOnly));
        $token = (string) $accessOnly->json('data.access_token');
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized();
        $this->resetAuth();
        $this->flushHeaders();
        config()->set('refresh_token.body_transport.enabled', true);
        $native = $this->withHeader('X-WMS-Token-Transport', 'body')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();
        $this->assertIsString($native->json('data.refresh_token'));
        $this->assertNull($this->refreshCookie($native));
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers((string) $native->json('data.access_token')))->assertOk();
    }

    public function test_legacy_access_tokens_require_login_and_public_routes_remain_public(): void
    {
        [$user] = $this->fixture();
        $token = JWTAuth::claims([])->fromUser($user);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REQUIRED');
        $this->getJson('/api/v1/health', $this->headers($token))->assertStatus(503)
            ->assertJsonPath('error_code', 'HEALTH_CHECK_DEGRADED');
    }

    public function test_disabled_rollout_preserves_multi_session_but_cannot_resurrect_replaced_family(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        $b = $this->login($user, $password);
        config()->set('refresh_token.single_active_session.enabled', false);
        $c = $this->login($user, $password);
        $this->getJson('/api/v1/auth/me', $this->headers($b['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($c['access']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers($c['access']))->assertOk();
    }

    public function test_missing_schema_fails_closed_before_session_replacement(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        Schema::table('refresh_tokens', fn ($table) => $table->dropColumn('replaced_by_auth_session_id'));
        $this->resetAuth();
        $this->browserLogin($user->email, $password)->assertStatus(503)
            ->assertJsonPath('error_code', 'AUTH_SESSION_POLICY_NOT_READY');
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertStatus(503)
            ->assertJsonPath('error_code', 'AUTH_SESSION_POLICY_NOT_READY');
        $this->resetAuth();
        $this->browserRefresh($a['refresh'])->assertStatus(503)
            ->assertJsonPath('error_code', 'AUTH_SESSION_POLICY_NOT_READY');
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_completed_mfa_only_replaces_the_old_session(): void
    {
        [$user, $password] = $this->fixture();
        $a = $this->login($user, $password);
        Event::fake([AuthSessionReplaced::class]);
        config()->set('pii.encryption.keys', json_encode(['v1' => 'base64:'.base64_encode(random_bytes(32))]));
        config()->set('pii.blind_index.keys', json_encode(['v1' => 'base64:'.base64_encode(random_bytes(32))]));
        PiiKeyring::flush();
        PiiCrypto::flush();
        $mfa = app(MfaService::class);
        $enrollment = $mfa->beginEnrollment($user);
        $mfa->confirmEnrollment($user, Totp::code($enrollment['secret'], time() - 30));
        $this->resetAuth();
        $challenge = $this->browserLogin($user->email, $password)->assertOk()->assertJsonPath('data.mfa_required', true);
        Event::assertNotDispatched(AuthSessionReplaced::class);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertOk();
        $this->resetAuth();
        $completed = $this->withHeader('Origin', $this->browserOrigin())->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $challenge->json('data.mfa_token'), 'code' => Totp::code($enrollment['secret'], time()),
        ])->assertOk()->assertJsonPath('data.replaced_existing_session', true);
        Event::assertDispatchedTimes(AuthSessionReplaced::class, 1);
        $this->getJson('/api/v1/auth/me', $this->headers($a['access']))->assertUnauthorized()
            ->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/me', $this->headers((string) $completed->json('data.access_token')))->assertOk();
    }

    public function test_replacement_never_edits_business_or_shift_state(): void
    {
        [$user, $password] = $this->fixture();
        $this->login($user, $password);
        $family = (string) RefreshToken::query()->where('user_id', $user->user_id)->value('auth_session_id');
        $warehouse = $this->ensureActiveWarehouse('UAT05-ISOLATED');
        $shift = (string) Str::uuid();
        DB::table('mini_app_work_shifts')->insert(['shift_id' => $shift, 'actor_id' => $user->user_id,
            'auth_session_id' => $family, 'warehouse_id' => $warehouse->id,
            'started_at' => now(), 'tracking_contract' => 'p14-shift-v1']);
        DB::table('mini_app_drafts')->insert(['document_id' => (string) Str::uuid(), 'actor_id' => $user->user_id,
            'auth_session_id' => $family, 'warehouse_id' => $warehouse->id, 'scan_session_id' => (string) Str::uuid(),
            'operation' => 'INBOUND', 'status' => 'DRAFT', 'codes' => '[]', 'form' => '{}',
            'last_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('p14_shift_operations')->insert(['shift_id' => $shift, 'request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'isolated-pending-request'), 'operation' => 'PENDING_OWNER_RESULT',
            'completed_at' => now()]);
        $tables = ['mini_app_work_shifts', 'mini_app_drafts', 'mini_app_draft_receipts',
            'p14_shift_operations', 'p14_shift_closures', 'stock_documents', 'stock_movements'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }
        $this->login($user, $password);
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray(), $table);
        }
    }

    public function test_schema_rollback_cannot_discard_committed_replacement_metadata(): void
    {
        [$user, $password] = $this->fixture();
        $this->login($user, $password);
        $this->login($user, $password);
        $migration = require database_path('migrations/2026_10_18_000001_add_refresh_session_replacement_metadata.php');
        try {
            $migration->down();
            $this->fail('Used replacement metadata must survive application rollback.');
        } catch (RuntimeException $error) {
            $this->assertSame('Session replacement metadata is in use; retain it during application rollback.', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('refresh_tokens', 'replaced_by_auth_session_id'));
    }

    private function fixture(): array
    {
        $password = bin2hex(random_bytes(24));
        $user = User::create(['username' => 'uat05-'.Str::uuid(), 'display_name' => 'Isolated UAT05',
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make($password),
            'status' => 'ACTIVE', 'version' => 1]);

        return [$user, $password];
    }

    private function login(User $user, string $password): array
    {
        $this->resetAuth();
        $this->flushHeaders();
        $response = $this->browserLogin($user->email, $password)->assertOk();

        return ['access' => (string) $response->json('data.access_token'), 'refresh' => $this->refreshTokenFromCookie($response)];
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
