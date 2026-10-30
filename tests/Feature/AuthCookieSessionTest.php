<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthCookieSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-password-123';

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'username' => 'warehouse01',
            'display_name' => 'Warehouse user',
            'email' => 'warehouse01@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ], $attributes));
    }

    private function login(array $identity = ['username' => 'warehouse01']): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [...$identity, 'password' => self::PASSWORD]);
    }

    private function refreshCookie(TestResponse $response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'wms_refresh_token') {
                return $cookie;
            }
        }

        $this->fail('Response did not set wms_refresh_token');
    }

    public function test_login_sets_httponly_refresh_cookie_without_returning_raw_token_in_json(): void
    {
        $user = $this->user();

        $response = $this->login();

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertSame($user->user_id, $response->json('data.user.user_id'));
        $this->assertArrayNotHasKey('refresh_token', $response->json('data'));

        $cookie = $this->refreshCookie($response);
        $this->assertNotEmpty($cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->assertDatabaseHas('refresh_tokens', [
            'user_id' => $user->user_id,
            'token_hash' => hash('sha256', $cookie->getValue()),
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $cookie->getValue()]);
    }

    public function test_email_login_uses_the_same_cookie_contract(): void
    {
        $this->user();

        $response = $this->login(['email' => 'warehouse01@example.test']);

        $response->assertOk();
        $this->assertArrayNotHasKey('refresh_token', $response->json('data'));
        $this->assertNotEmpty($this->refreshCookie($response)->getValue());
    }

    public function test_login_rejects_missing_or_ambiguous_identity_without_creating_a_session(): void
    {
        $this->user();

        $this->postJson('/api/v1/auth/login', ['password' => self::PASSWORD])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', [
            'username' => 'warehouse01',
            'email' => 'warehouse01@example.test',
            'password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertSame(0, RefreshToken::count());
    }

    public function test_configured_frontend_origin_can_login_with_credentialed_cors(): void
    {
        config()->set('cors.allowed_origins', ['http://localhost:5173']);
        $this->user();

        $preflight = $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        $preflight->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'warehouse01',
            'password' => self::PASSWORD,
        ], ['Origin' => 'http://localhost:5173']);
        $login->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $this->assertNotEmpty($this->refreshCookie($login)->getValue());
    }

    public function test_auth_responses_are_not_stored_by_http_caches(): void
    {
        $this->user();
        $login = $this->login();
        $login->assertOk();
        $this->assertStringContainsString('no-store', (string) $login->headers->get('Cache-Control'));
        $raw = $this->refreshCookie($login)->getValue();
        $access = $login->json('data.access_token');

        $me = $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$access}"]);
        $me->assertOk();
        $this->assertStringContainsString('no-store', (string) $me->headers->get('Cache-Control'));

        $refresh = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/refresh', []);
        $refresh->assertOk();
        $this->assertStringContainsString('no-store', (string) $refresh->headers->get('Cache-Control'));

        $logout = $this->withUnencryptedCookie('wms_refresh_token', $this->refreshCookie($refresh)->getValue())
            ->postJson('/api/v1/auth/logout', [], [
                'Authorization' => 'Bearer '.$refresh->json('data.access_token'),
            ]);
        $logout->assertOk();
        $this->assertStringContainsString('no-store', (string) $logout->headers->get('Cache-Control'));
    }

    public function test_login_and_refresh_include_role_and_scope_for_frontend_profile(): void
    {
        $user = $this->user();
        $role = Role::create([
            'role_code' => 'WAREHOUSE_STAFF',
            'role_name' => 'Warehouse staff',
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => 'WH-01',
        ]);
        $this->assertSame(1, $user->roles()->count());

        $login = $this->login();
        $login->assertJsonPath('data.user.roles.0.role_code', 'WAREHOUSE_STAFF')
            ->assertJsonPath('data.user.roles.0.scope_ref', 'WH-01')
            ->assertJsonPath('data.user.warehouse_scope_ids.0', 'WH-01');

        $refresh = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $this->refreshCookie($login)->getValue())
            ->postJson('/api/v1/auth/refresh', []);
        $refresh->assertJsonPath('data.user.roles.0.role_code', 'WAREHOUSE_STAFF')
            ->assertJsonPath('data.user.warehouse_scope_ids.0', 'WH-01');
    }

    public function test_refresh_cookie_is_secure_for_https_requests(): void
    {
        $this->user();

        $response = $this->postJson('https://localhost/api/v1/auth/login', [
            'username' => 'warehouse01',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertTrue($this->refreshCookie($response)->isSecure());
    }

    public function test_invalid_password_and_locked_account_do_not_issue_refresh_cookie(): void
    {
        $this->user();

        $invalid = $this->postJson('/api/v1/auth/login', [
            'username' => 'warehouse01',
            'password' => 'wrong',
        ]);
        $invalid->assertStatus(401);
        $this->assertSame([], $invalid->headers->getCookies());
        $this->assertSame(0, RefreshToken::count());

        User::where('username', 'warehouse01')->update(['status' => 'LOCKED']);
        $locked = $this->login();
        $locked->assertStatus(403);
        $this->assertSame([], $locked->headers->getCookies());
        $this->assertSame(0, RefreshToken::count());
    }

    public function test_refresh_requires_cookie_and_rejects_legacy_body_token(): void
    {
        $this->user();
        $login = $this->login();
        $raw = $this->refreshCookie($login)->getValue();

        $missing = $this->postJson('/api/v1/auth/refresh', []);
        $missing->assertStatus(401);
        $this->assertStringContainsString('no-store', (string) $missing->headers->get('Cache-Control'));
        $this->assertSame('', $this->refreshCookie($missing)->getValue());
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $raw])->assertStatus(422);
        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $raw),
            'revoked_at' => null,
        ]);
    }

    public function test_invalid_and_expired_refresh_cookies_are_cleared_without_issuing_access_tokens(): void
    {
        $this->user();
        $invalid = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', 'invalid')
            ->postJson('/api/v1/auth/refresh', []);
        $invalid->assertStatus(401);
        $this->assertSame('', $this->refreshCookie($invalid)->getValue());

        $raw = $this->refreshCookie($this->login())->getValue();
        RefreshToken::where('token_hash', hash('sha256', $raw))
            ->update(['expires_at' => now()->subMinute()]);
        $expired = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/refresh', []);
        $expired->assertStatus(401);
        $this->assertSame('', $this->refreshCookie($expired)->getValue());
        $this->assertSame(1, RefreshToken::count());
    }

    public function test_locked_account_cannot_refresh_and_its_refresh_tokens_are_revoked(): void
    {
        $user = $this->user();
        $raw = $this->refreshCookie($this->login())->getValue();
        $user->update(['status' => 'LOCKED']);

        $response = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/refresh', []);

        $response->assertStatus(401);
        $this->assertSame('', $this->refreshCookie($response)->getValue());
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => hash('sha256', $raw),
            'revoked_at' => null,
        ]);
    }

    public function test_untrusted_browser_origin_cannot_rotate_the_cookie(): void
    {
        $this->user();
        $raw = $this->refreshCookie($this->login())->getValue();

        $response = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/refresh', [], ['Origin' => 'https://untrusted.example']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $raw),
            'revoked_at' => null,
        ]);
    }

    public function test_refresh_rotates_cookie_and_returns_access_token_with_server_profile(): void
    {
        $user = $this->user();
        $login = $this->login();
        $oldRaw = $this->refreshCookie($login)->getValue();

        $refresh = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $oldRaw)
            ->postJson('/api/v1/auth/refresh', []);

        $refresh->assertOk()->assertJsonPath('data.user.user_id', $user->user_id);
        $this->assertNotEmpty($refresh->json('data.access_token'));
        $this->assertIsArray($refresh->json('data.user.permissions'));
        $this->assertArrayNotHasKey('refresh_token', $refresh->json('data'));
        $newRaw = $this->refreshCookie($refresh)->getValue();
        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => hash('sha256', $oldRaw),
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $newRaw),
            'revoked_at' => null,
        ]);
    }

    public function test_replaying_an_old_cookie_revokes_the_users_new_refresh_session(): void
    {
        $this->user();
        $oldRaw = $this->refreshCookie($this->login())->getValue();
        $newRaw = $this->refreshCookie($this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $oldRaw)
            ->postJson('/api/v1/auth/refresh', []))->getValue();

        $replay = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $oldRaw)
            ->postJson('/api/v1/auth/refresh', []);

        $replay->assertStatus(401);
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => hash('sha256', $newRaw),
            'revoked_at' => null,
        ]);
    }

    public function test_logout_revokes_cookie_clears_browser_cookie_and_invalidates_access_token(): void
    {
        $this->user();
        $login = $this->login();
        $raw = $this->refreshCookie($login)->getValue();
        $access = $login->json('data.access_token');

        $logout = $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$access}"]);

        $logout->assertOk();
        $this->assertSame('', $this->refreshCookie($logout)->getValue());
        $this->assertSame('/api/v1/auth', $this->refreshCookie($logout)->getPath());
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => hash('sha256', $raw),
            'revoked_at' => null,
        ]);
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$access}"])
            ->assertStatus(401);
    }

    public function test_logout_rejects_legacy_body_token(): void
    {
        $this->user();
        $login = $this->login();
        $raw = $this->refreshCookie($login)->getValue();
        $access = $login->json('data.access_token');

        $this->withCredentials()->withUnencryptedCookie('wms_refresh_token', $raw)
            ->postJson('/api/v1/auth/logout', ['refresh_token' => $raw], [
                'Authorization' => "Bearer {$access}",
            ])->assertStatus(422);

        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $raw),
            'revoked_at' => null,
        ]);
    }
}
