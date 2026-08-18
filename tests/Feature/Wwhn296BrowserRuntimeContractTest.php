<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WWHN-296 — build/runtime configuration check for the browser auth contract.
 *
 * The FE keeps the access JWT in memory only, so a reloaded tab can only stay
 * logged in if the browser replays the HttpOnly refresh cookie. Every failure
 * mode below still answers 200 on /api/v1/health and still passes a curl smoke
 * test — it only breaks in a real browser, at the deployed environment, after
 * cutover. That is why the assertions live on the go-live command (which runs
 * against the actual injected environment) instead of on a static config file.
 */
class Wwhn296BrowserRuntimeContractTest extends TestCase
{
    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('app/testing/wwhn-296-browser-runtime-contract.json');
        File::delete($this->evidencePath);
    }

    protected function tearDown(): void
    {
        File::delete($this->evidencePath);

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function browserContract(array $overrides, string $environment = 'production'): array
    {
        config($overrides);
        $this->app->detectEnvironment(static fn (): string => $environment);

        $this->artisan('wms:golive-check', ['--json' => $this->evidencePath])->run();

        $payload = json_decode((string) File::get($this->evidencePath), true);

        return $payload['checks']['security.browser_runtime_contract'];
    }

    /** @return array<string, mixed> */
    private function healthyProductionConfig(): array
    {
        return [
            'app.url' => 'https://api.hoanam.com.vn',
            'cors.allowed_origins' => ['https://wms.hoanam.com.vn'],
            'cors.supports_credentials' => true,
            'refresh_token.cookie.path' => '/api/v1/auth',
            'refresh_token.cookie.secure' => true,
            'refresh_token.cookie.same_site' => 'lax',
            'session.secure' => true,
        ];
    }

    public function test_same_site_https_deployment_passes_and_publishes_the_sanitized_matrix(): void
    {
        $check = $this->browserContract($this->healthyProductionConfig());

        $this->assertSame('ok', $check['status'], json_encode($check['detail']));
        $this->assertSame('https://api.hoanam.com.vn/api/v1/health', $check['detail']['health_url']);
        $this->assertSame(['https://wms.hoanam.com.vn'], $check['detail']['cors']['allowed_origins']);
        $this->assertTrue($check['detail']['cors']['supports_credentials']);
        $this->assertTrue($check['detail']['refresh_cookie']['secure']);
        $this->assertTrue($check['detail']['refresh_cookie']['http_only']);
        $this->assertSame('/api/v1/auth', $check['detail']['refresh_cookie']['path']);
        $this->assertSame([], $check['detail']['cross_site_frontend_origins']);
    }

    /**
     * app.hoanam.com.vn and api.hoanam.com.vn are different origins but the same
     * site, so Lax keeps working. `.com.vn` is a two-label public suffix — a
     * naive "last two labels" comparison would read both as `com.vn` too, but it
     * would equally read wms-hoanam.com.vn as same-site, so the suffix table has
     * to be exercised on a real deployment-shaped host.
     */
    public function test_subdomain_frontend_under_a_two_label_public_suffix_is_same_site(): void
    {
        $check = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => ['https://app.wms.hoanam.com.vn'],
        ]);

        $this->assertSame('ok', $check['status'], json_encode($check['detail']));
        $this->assertSame([], $check['detail']['cross_site_frontend_origins']);
    }

    public function test_cross_site_frontend_with_lax_refresh_cookie_fails_closed(): void
    {
        $check = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => ['https://wms-hoanam.vercel.app'],
        ]);

        $this->assertSame('fail', $check['status']);
        $this->assertArrayHasKey('refresh_cookie_cross_site', $check['detail']['findings']);
        $this->assertSame(['https://wms-hoanam.vercel.app'], $check['detail']['cross_site_frontend_origins']);
    }

    public function test_cross_site_frontend_passes_once_same_site_none_is_configured(): void
    {
        $check = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => ['https://wms-hoanam.vercel.app'],
            'refresh_token.cookie.same_site' => 'none',
        ]);

        $this->assertSame('ok', $check['status'], json_encode($check['detail']));
    }

    public function test_wildcard_or_plaintext_origin_is_rejected_in_production(): void
    {
        $wildcard = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => ['*'],
        ]);
        $this->assertSame('fail', $wildcard['status']);
        $this->assertArrayHasKey('cors_wildcard', $wildcard['detail']['findings']);

        $plaintext = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => ['http://wms.hoanam.com.vn'],
        ]);
        $this->assertSame('fail', $plaintext['status']);
        $this->assertArrayHasKey('cors_origin_insecure', $plaintext['detail']['findings']);
    }

    public function test_empty_allow_list_and_disabled_credentials_are_reported_as_deployment_blockers(): void
    {
        $check = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'cors.allowed_origins' => [],
            'cors.supports_credentials' => false,
        ]);

        $this->assertSame('fail', $check['status']);
        $this->assertArrayHasKey('cors_origins', $check['detail']['findings']);
        $this->assertArrayHasKey('cors_credentials', $check['detail']['findings']);
    }

    public function test_insecure_cookie_and_localhost_app_url_are_rejected_outside_local(): void
    {
        $check = $this->browserContract([
            ...$this->healthyProductionConfig(),
            'app.url' => 'http://localhost:8080',
            'refresh_token.cookie.secure' => false,
            'refresh_token.cookie.path' => '/',
        ]);

        $this->assertSame('fail', $check['status']);
        $this->assertArrayHasKey('app_url_insecure', $check['detail']['findings']);
        $this->assertArrayHasKey('app_url_localhost', $check['detail']['findings']);
        $this->assertArrayHasKey('refresh_cookie_insecure', $check['detail']['findings']);
        $this->assertArrayHasKey('refresh_cookie_path', $check['detail']['findings']);
    }

    /**
     * A developer machine mixing http://localhost with http://127.0.0.1 is
     * genuinely cross-site, but it must surface as a warning — turning every
     * local run into FAIL would train the team to ignore the gate.
     */
    public function test_local_environment_downgrades_cross_site_to_a_warning(): void
    {
        $check = $this->browserContract([
            'app.url' => 'http://localhost:8080',
            'cors.allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173'],
            'cors.supports_credentials' => true,
            'refresh_token.cookie.path' => '/api/v1/auth',
            'refresh_token.cookie.secure' => false,
            'refresh_token.cookie.same_site' => 'lax',
        ], 'local');

        $this->assertSame('warn', $check['status'], json_encode($check['detail']));
        $this->assertSame([], $check['detail']['findings']);
        $this->assertArrayHasKey('refresh_cookie_cross_site', $check['detail']['warnings']);
    }
}
