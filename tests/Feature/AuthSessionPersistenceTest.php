<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/**
 * Phiên trình duyệt sống qua F5 bằng cookie HttpOnly — và CHỈ bằng cookie đó.
 *
 * Trước P1 (10/09/2026) lớp này khẳng định "login đặt cookie mà KHÔNG đổi JSON contract",
 * tức refresh token vẫn nằm song song trong JSON, và "JSON body refresh vẫn được hỗ trợ".
 * Hai mệnh đề đó chính là đường XSS lấy credential dài hạn; nay chúng bị đảo ngược.
 */
class AuthSessionPersistenceTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_browser_login_sets_httponly_refresh_cookie_and_keeps_the_token_out_of_json(): void
    {
        $user = $this->createUser('cookie_login');

        $response = $this->browserLogin($user->email, 'secret123')->assertOk();

        $cookie = $this->refreshCookie($response);
        $this->assertNotNull($cookie, 'Login must set the refresh-token cookie.');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $response->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'user']])
            ->assertJsonMissingPath('data.refresh_token');
        $this->assertStringNotContainsString((string) $cookie->getValue(), (string) $response->getContent());
    }

    public function test_refresh_works_with_cookie_only_and_rotates_cookie(): void
    {
        $user = $this->createUser('cookie_refresh');
        $oldRefreshToken = $this->refreshTokenFromCookie($this->browserLogin($user->email, 'secret123')->assertOk());

        $response = $this->browserRefresh($oldRefreshToken)
            ->assertOk()
            ->assertJsonMissingPath('data.refresh_token');

        $newRefreshToken = $this->refreshTokenFromCookie($response);
        $this->assertNotSame($oldRefreshToken, $newRefreshToken);
        $this->assertStringNotContainsString($newRefreshToken, (string) $response->getContent());
        $this->assertSame(1, RefreshToken::where('user_id', $user->user_id)->active()->count());
    }

    public function test_browser_refresh_rejects_a_refresh_token_sent_in_the_json_body(): void
    {
        $user = $this->createUser('body_refresh');
        $refreshToken = $this->refreshTokenFromCookie($this->browserLogin($user->email, 'secret123')->assertOk());

        $this->withHeader('Origin', $this->browserOrigin())
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REFRESH_TOKEN_TRANSPORT_MISMATCH');

        // Request bị từ chối không được tiêu hao phiên.
        $this->assertTrue(RefreshToken::where('user_id', $user->user_id)->active()->exists());
    }

    public function test_invalid_cookie_refresh_expires_cookie_to_break_reload_loop(): void
    {
        $response = $this->browserRefresh('invalid-refresh-token')->assertStatus(401);

        $expiredCookie = $this->refreshCookie($response);
        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->getExpiresTime() < time());
    }

    public function test_logout_revokes_the_cookie_session_and_expires_cookie(): void
    {
        $user = $this->createUser('cookie_logout');
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $accessToken = (string) $login->json('data.access_token');
        $refreshToken = $this->refreshTokenFromCookie($login);

        $response = $this->withCredentials()
            ->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $refreshToken)
            ->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertFalse(RefreshToken::where('user_id', $user->user_id)->active()->exists());

        $expiredCookie = $this->refreshCookie($response);
        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->getExpiresTime() < time());

        $this->browserRefresh($refreshToken)->assertStatus(401);
    }

    private function createUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'display_name' => str_replace('_', ' ', ucfirst($username)),
            'email' => $username.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }
}
