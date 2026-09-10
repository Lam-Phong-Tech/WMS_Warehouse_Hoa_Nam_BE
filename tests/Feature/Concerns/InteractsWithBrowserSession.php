<?php

namespace Tests\Feature\Concerns;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * P1 bảo mật 10/09/2026 — luồng phiên của TRÌNH DUYỆT trong feature test.
 *
 * Refresh token không còn nằm trong JSON: trình duyệt chỉ nhận nó qua Set-Cookie HttpOnly,
 * và mọi request dùng cookie đó phải mang Origin của FE được CORS cho phép. Test cũ đọc
 * `data.refresh_token` rồi gửi lại trong body — đúng con đường XSS vừa bị đóng.
 */
trait InteractsWithBrowserSession
{
    protected function browserOrigin(): string
    {
        $origin = 'http://localhost:5173';
        $allowed = (array) config('cors.allowed_origins', []);

        if (! in_array($origin, $allowed, true)) {
            config()->set('cors.allowed_origins', [...$allowed, $origin]);
        }

        return $origin;
    }

    protected function browserLogin(string $email, string $password): TestResponse
    {
        return $this->withHeader('Origin', $this->browserOrigin())
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    protected function browserRefresh(string $refreshToken): TestResponse
    {
        return $this->withCredentials()
            ->withHeader('Origin', $this->browserOrigin())
            ->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $refreshToken)
            ->postJson('/api/v1/auth/refresh');
    }

    protected function refreshCookie(TestResponse $response): ?Cookie
    {
        $name = (string) config('refresh_token.cookie.name');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    protected function refreshTokenFromCookie(TestResponse $response): string
    {
        $cookie = $this->refreshCookie($response);

        $this->assertNotNull($cookie, 'Response phải đặt cookie refresh token.');
        $this->assertTrue($cookie->isHttpOnly(), 'Cookie refresh token phải HttpOnly.');
        $this->assertNotSame('', (string) $cookie->getValue());

        return (string) $cookie->getValue();
    }
}
