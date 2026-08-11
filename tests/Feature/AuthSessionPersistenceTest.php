<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Tests\TestCase;

class AuthSessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_sets_httponly_refresh_cookie_without_changing_json_contract(): void
    {
        $user = $this->createUser('cookie_login');
        $cookieName = (string) config('refresh_token.cookie.name');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $refreshToken = (string) $response->json('data.refresh_token');
        $this->assertNotSame('', $refreshToken);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);

        $this->assertNotNull($cookie, 'Login must set the refresh-token cookie.');
        $this->assertSame($refreshToken, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        // Backward compatibility for Swagger/Postman/non-browser clients.
        $response->assertJsonStructure(['data' => [
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'user',
        ]]);
    }

    public function test_refresh_works_with_cookie_only_and_rotates_cookie(): void
    {
        $user = $this->createUser('cookie_refresh');
        $cookieName = (string) config('refresh_token.cookie.name');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();
        $oldRefreshToken = (string) $login->json('data.refresh_token');

        // API routes intentionally use a plain opaque HttpOnly cookie rather than a
        // Laravel-encrypted cookie. Exercise the same request-cookie bag a browser
        // produces instead of injecting a raw Cookie header into the test server.
        $response = $this->withUnencryptedCookie($cookieName, $oldRefreshToken)
            ->postJson('/api/v1/auth/refresh', [])
            ->assertOk();

        $newRefreshToken = (string) $response->json('data.refresh_token');
        $this->assertNotSame('', $newRefreshToken);
        $this->assertNotSame($oldRefreshToken, $newRefreshToken);

        $rotatedCookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);
        $this->assertNotNull($rotatedCookie);
        $this->assertSame($newRefreshToken, $rotatedCookie->getValue());
        $this->assertTrue($rotatedCookie->isHttpOnly());
    }

    public function test_json_body_refresh_remains_supported(): void
    {
        $user = $this->createUser('body_refresh');
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login->json('data.refresh_token'),
        ])->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    public function test_invalid_cookie_refresh_expires_cookie_to_break_reload_loop(): void
    {
        $cookieName = (string) config('refresh_token.cookie.name');

        $response = $this->withUnencryptedCookie($cookieName, 'invalid-refresh-token')
            ->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(401);

        $expiredCookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);

        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->getExpiresTime() < time());
    }

    public function test_logout_accepts_cookie_fallback_revokes_session_and_expires_cookie(): void
    {
        $user = $this->createUser('cookie_logout');
        $cookieName = (string) config('refresh_token.cookie.name');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();
        $accessToken = (string) $login->json('data.access_token');
        $refreshToken = (string) $login->json('data.refresh_token');

        $response = $this->withUnencryptedCookie($cookieName, $refreshToken)
            ->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/api/v1/auth/logout', [])
            ->assertOk();

        $this->assertFalse(
            RefreshToken::where('user_id', $user->user_id)->active()->exists()
        );

        $expiredCookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);
        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->getExpiresTime() < time());
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
