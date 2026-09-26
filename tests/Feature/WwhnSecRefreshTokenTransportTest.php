<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Http\Middleware\PersistRefreshTokenCookie;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenTransport;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * P1 bảo mật 10/09/2026 — refresh token không bao giờ tới được JavaScript của trình duyệt.
 *
 * Trước đây login/refresh/MFA verify trả refresh token 14 ngày trong JSON và FE giữ nó
 * trong bộ nhớ JS: một lỗi XSS là đủ để mang credential dài hạn đi dùng từ máy khác.
 * Mỗi test dưới đây khẳng định trên RESPONSE THẬT của API (không mock) rằng token chỉ nằm
 * trong Set-Cookie HttpOnly, và các lớp chặn quanh cookie đó (Origin, replay, thu hồi)
 * vẫn đứng vững.
 */
class WwhnSecRefreshTokenTransportTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const PASSWORD = TestCredentials::PASSWORD;

    public function test_browser_login_delivers_the_refresh_token_only_in_an_httponly_cookie(): void
    {
        $user = $this->makeUser('p1-browser');

        $response = $this->browserLogin($user->email, self::PASSWORD)->assertOk();

        $token = $this->refreshTokenFromCookie($response);
        $response->assertJsonMissingPath('data.refresh_token');
        $this->assertBodyNeverContains($response, $token);
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_login_without_any_origin_gets_an_access_token_but_no_refresh_capability(): void
    {
        $user = $this->makeUser('p1-cli');

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonMissingPath('data.refresh_token');

        $this->assertNotSame('', (string) $response->json('data.access_token'));
        $this->assertNull($this->refreshCookie($response));
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
    }

    public function test_login_from_an_untrusted_origin_is_rejected_before_credentials_are_checked(): void
    {
        $user = $this->makeUser('p1-login-csrf');

        foreach ([['Origin' => 'https://attacker.example'], ['Referer' => 'https://attacker.example/login-csrf']] as $headers) {
            $this->flushHeaders();

            $response = $this->withHeaders($headers)
                ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED')
                ->assertJsonMissingPath('data.access_token');

            $this->assertNull($this->refreshCookie($response));
        }

        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'LOGIN', 'object_id' => $user->user_id]);
    }

    public function test_cookie_login_accepts_a_cors_pattern_origin(): void
    {
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', ['#^http://localhost:\\d+$#']);

        $user = $this->makeUser('p1-pattern-origin');

        $response = $this->withHeader('Origin', 'http://localhost:5174')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertNotNull($this->refreshCookie($response));
        $response->assertJsonMissingPath('data.refresh_token');
    }

    public function test_cookie_login_accepts_the_effective_same_origin_behind_a_proxy(): void
    {
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('app.url', 'http://backend-internal:8080');

        $user = $this->makeUser('p1-same-origin-proxy');

        // A relative test URI is expanded using the application's boot URL.
        // Symfony derives Host/port from that URI, so a Host header alone does
        // not simulate the public request origin when CI boots on port 8000.
        // Use the actual external URI; do not relax the production origin gate.
        $response = $this->withHeader('Origin', 'http://warehouse.local.test')
            ->postJson('http://warehouse.local.test/api/v1/auth/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertOk();

        $this->assertNotNull($this->refreshCookie($response));
        $response->assertJsonMissingPath('data.refresh_token');
    }

    public function test_effective_origin_still_rejects_a_different_port_or_host(): void
    {
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('app.url', 'http://backend-internal:8080');
        $user = $this->makeUser('p1-origin-boundary');

        foreach (['http://warehouse.local.test:8081', 'https://attacker.example'] as $origin) {
            $this->flushHeaders();
            $response = $this->withHeader('Origin', $origin)
                ->postJson('http://warehouse.local.test/api/v1/auth/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])->assertForbidden()->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');
            $this->assertNull($this->refreshCookie($response));
            $response->assertJsonMissingPath('data.access_token');
        }

        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
    }

    public function test_cookie_login_and_refresh_accept_browser_same_origin_when_internal_proxy_host_differs(): void
    {
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('app.url', 'http://backend-internal:8080');

        $user = $this->makeUser('p1-sec-fetch-same-origin');

        $login = $this->withHeaders([
            // Browser/public origin. BE may see an internal Host after the FE
            // reverse-proxy hop; Sec-Fetch-Site remains browser-authored.
            'Host' => 'backend-internal',
            'Origin' => 'https://khohoanamfe.lptech.info.vn',
            'Sec-Fetch-Site' => 'same-origin',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $refreshToken = $this->refreshTokenFromCookie($login);
        $this->assertNotSame('', $refreshToken);

        $refresh = $this->withHeaders([
            'Host' => 'backend-internal',
            'Origin' => 'https://khohoanamfe.lptech.info.vn',
            'Sec-Fetch-Site' => 'same-origin',
        ])->withCredentials()
            ->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $refreshToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonMissingPath('data.refresh_token');

        $this->assertNotNull($this->refreshCookie($refresh));
    }

    public function test_sec_fetch_cross_site_never_bypasses_origin_allowlist(): void
    {
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('app.url', 'http://backend-internal:8080');

        $user = $this->makeUser('p1-sec-fetch-cross-site');

        $this->withHeaders([
            'Host' => 'backend-internal',
            'Origin' => 'https://attacker.example',
            'Sec-Fetch-Site' => 'cross-site',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');
    }

    public function test_cookie_refresh_rotates_the_cookie_and_never_returns_a_token_in_json(): void
    {
        $user = $this->makeUser('p1-rotate');
        $first = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());

        $response = $this->browserRefresh($first)->assertOk()->assertJsonMissingPath('data.refresh_token');

        $second = $this->refreshTokenFromCookie($response);
        $this->assertNotSame($first, $second);
        $this->assertBodyNeverContains($response, $first);
        $this->assertBodyNeverContains($response, $second);
        $this->assertNotSame('', (string) $response->json('data.access_token'));
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_cookie_refresh_requires_a_trusted_origin_and_a_blocked_attempt_does_not_end_the_session(): void
    {
        $user = $this->makeUser('p1-refresh-csrf');
        $token = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());
        $cookieName = (string) config('refresh_token.cookie.name');

        foreach ([[], ['Origin' => 'https://attacker.example'], ['Referer' => 'https://attacker.example/csrf']] as $headers) {
            $this->flushHeaders();

            $blocked = $this->withHeaders($headers)
                ->withCredentials()
                ->withUnencryptedCookie($cookieName, $token)
                ->postJson('/api/v1/auth/refresh')
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');

            // Request CSRF bị chặn không được xoá cookie hay tiêu hao phiên của nạn nhân.
            $this->assertNull($this->refreshCookie($blocked));
        }

        $this->assertTrue(RefreshToken::query()->where('user_id', $user->user_id)->active()->exists());
        $this->browserRefresh($token)->assertOk();
    }

    public function test_browser_refresh_rejects_a_token_sent_from_javascript_in_the_body(): void
    {
        $user = $this->makeUser('p1-body-from-js');
        $token = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());

        $this->withHeader('Origin', $this->browserOrigin())
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REFRESH_TOKEN_TRANSPORT_MISMATCH');

        $this->assertTrue(RefreshToken::query()->where('user_id', $user->user_id)->active()->exists());
    }

    public function test_replaying_a_rotated_token_revokes_the_whole_session_family(): void
    {
        $user = $this->makeUser('p1-replay');
        $first = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());
        $second = $this->refreshTokenFromCookie($this->browserRefresh($first)->assertOk());

        $this->browserRefresh($first)->assertStatus(401);

        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->browserRefresh($second)->assertStatus(401);
    }

    public function test_two_refreshes_with_the_same_token_never_leave_two_live_sessions(): void
    {
        // Hai tab cùng F5 mang cùng một cookie. Hàng refresh token bị khoá FOR UPDATE nên
        // request thứ hai thấy token đã bị thu hồi và coi là replay. Bản chạy song song thật
        // trên PostgreSQL + php-fpm nằm trong báo cáo hồi quy bảo mật.
        $user = $this->makeUser('p1-double-refresh');
        $token = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());

        $statuses = [
            $this->browserRefresh($token)->getStatusCode(),
            $this->browserRefresh($token)->getStatusCode(),
        ];
        sort($statuses);

        $this->assertSame([200, 401], $statuses);
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_password_change_revokes_the_cookie_session_and_expires_the_cookie(): void
    {
        $user = $this->makeUser('p1-password-change');
        $login = $this->browserLogin($user->email, self::PASSWORD)->assertOk();
        $token = $this->refreshTokenFromCookie($login);

        $response = $this->withToken((string) $login->json('data.access_token'))
            ->withCredentials()
            ->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => self::PASSWORD,
                'new_password' => 'Changed#Password-2026',
                'new_password_confirmation' => 'Changed#Password-2026',
            ])
            ->assertOk();

        $expired = $this->refreshCookie($response);
        $this->assertNotNull($expired);
        $this->assertTrue($expired->getExpiresTime() < time());

        $this->forgetAuthentication();
        $this->flushHeaders();
        $this->browserRefresh($token)->assertStatus(401);
    }

    public function test_a_locked_account_cannot_refresh_and_loses_every_session(): void
    {
        $user = $this->makeUser('p1-locked');
        $token = $this->refreshTokenFromCookie($this->browserLogin($user->email, self::PASSWORD)->assertOk());

        $user->forceFill(['status' => 'LOCKED'])->save();

        $this->browserRefresh($token)->assertStatus(401);
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_body_transport_is_refused_unless_the_server_enables_it(): void
    {
        $user = $this->makeUser('p1-body-disabled');

        $this->withHeader(RefreshTokenTransport::HEADER, 'body')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'TOKEN_TRANSPORT_NOT_ALLOWED');

        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
    }

    public function test_an_unknown_transport_value_is_rejected(): void
    {
        $user = $this->makeUser('p1-transport-invalid');

        $this->withHeader(RefreshTokenTransport::HEADER, 'both')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TOKEN_TRANSPORT_INVALID');
    }

    public function test_enabled_body_transport_serves_non_browser_clients_without_ever_touching_cookies(): void
    {
        config()->set('refresh_token.body_transport.enabled', true);
        $user = $this->makeUser('p1-body-enabled');

        $login = $this->withHeader(RefreshTokenTransport::HEADER, 'body')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();
        $first = (string) $login->json('data.refresh_token');
        $this->assertNotSame('', $first);
        $this->assertNull($this->refreshCookie($login));

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();
        $second = (string) $refreshed->json('data.refresh_token');
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second);
        $this->assertNull($this->refreshCookie($refreshed));

        // Kênh body KHÔNG đọc cookie: cookie hợp lệ mà thiếu token trong body vẫn là 422.
        $this->withCredentials()
            ->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $second)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(422);
        $this->assertTrue(RefreshToken::query()->where('user_id', $user->user_id)->active()->exists());
    }

    public function test_browser_origins_cannot_use_the_body_transport_even_when_it_is_enabled(): void
    {
        config()->set('refresh_token.body_transport.enabled', true);
        config()->set('refresh_token.body_transport.allowed_origins', []);
        $user = $this->makeUser('p1-body-browser');

        $this->withHeaders([RefreshTokenTransport::HEADER => 'body', 'Origin' => $this->browserOrigin()])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'TOKEN_TRANSPORT_NOT_ALLOWED');

        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->count());
    }

    public function test_every_session_route_carries_the_transport_gate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach (['api/v1/auth/login', 'api/v1/auth/refresh', 'api/v1/auth/mfa/verify', 'api/v1/auth/logout', 'api/v1/auth/change-password'] as $uri) {
            $route = $routes->first(fn (RoutingRoute $route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true));

            $this->assertNotNull($route, "Thiếu route POST {$uri}.");
            $this->assertContains(PersistRefreshTokenCookie::class, $route->gatherMiddleware(), "POST {$uri} phải đi qua PersistRefreshTokenCookie.");
        }
    }

    public function test_no_controller_places_a_refresh_token_into_a_response_by_hand(): void
    {
        $offenders = [];

        foreach ([base_path('app'), ...(glob(base_path('Modules/*/app')) ?: [])] as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // `'refresh_token' => $...` — gán một giá trị token vào mảng trả về. Kênh body
                // chỉ được gắn token qua RefreshTokenTransport::deliver().
                if (preg_match('/[\'"]refresh_token[\'"]\s*=>\s*\$/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Chỉ RefreshTokenTransport::deliver() được đặt refresh token vào response.');
    }

    private function makeUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'display_name' => 'P1 '.$username,
            'email' => $username.'@wms-hoanam.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    private function assertBodyNeverContains(TestResponse $response, string $token): void
    {
        $this->assertStringNotContainsString($token, (string) $response->getContent(), 'Refresh token không được xuất hiện trong body response.');
    }

    /** JWTGuard nhớ user và tymon nhớ token giữa các request trong cùng một test. */
    private function forgetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        app('tymon.jwt')->unsetToken();
    }
}
