<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Support\CredentialPolicy;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * P0 bảo mật 10/09/2026 — hợp đồng đăng nhập KHÔNG có credential canonical.
 *
 * Trước ngày này lớp test cùng tên khẳng định điều NGƯỢC LẠI: `admin@gmail.com` + một
 * mật khẩu hằng số phải đăng nhập được trên nhánh seed UAT, hằng số đó phải nằm nguyên
 * văn trong AuthDatabaseSeeder, phải KHÔNG nằm trong deny-list của go-live gate và phải
 * được công bố làm `example` trong OpenAPI. Tức bộ test đã được dùng để GHIM chính lỗ
 * hổng P0 lại. Các test dưới đây ghim điều ngược lại.
 */
class AuthLoginContractSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Nguồn annotation của nhóm API xác thực và quản lý tài khoản. */
    private const OPENAPI_SOURCES = [
        'Modules/Auth/app/Http/Controllers/AuthController.php',
        'Modules/Auth/app/Http/Controllers/UserController.php',
        'Modules/Auth/app/Http/Controllers/MfaController.php',
        'Modules/Auth/app/Http/Controllers/PasswordResetController.php',
    ];

    private const GENERATED_OPENAPI = [
        'storage/api-docs/api-docs.json',
        'storage/api-docs/api-docs.yaml',
    ];

    public function test_uat_seed_path_no_longer_creates_a_canonical_admin_login(): void
    {
        $this->app->detectEnvironment(fn (): string => 'uat');
        config()->set('golive.seed.demo_accounts', false);

        $this->seed(AuthDatabaseSeeder::class);

        $this->assertDatabaseMissing('app_user', ['username' => 'admin']);
        $this->assertDatabaseMissing('app_user', ['email' => 'admin@gmail.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@gmail.com',
            'password' => (string) config('golive.credential.compromised.0'),
        ])->assertStatus(401);
    }

    public function test_compromised_project_credential_is_on_the_golive_deny_list(): void
    {
        $compromised = (array) config('golive.credential.compromised', []);

        $this->assertNotEmpty($compromised, 'Mật khẩu đã lộ của dự án phải nằm trong deny-list.');

        foreach ($compromised as $value) {
            $this->assertContains((string) $value, CredentialPolicy::knownDefaults());
            $this->assertTrue(CredentialPolicy::isKnownDefault((string) $value));
            $this->assertTrue(CredentialPolicy::isKnownDefault(strtolower((string) $value)));
        }

        // Cơ chế "cấp mật khẩu admin cho seeder" đã bị bỏ hẳn, không phải chỉ đổi giá trị.
        $this->assertNull(config('golive.seed.admin_password'));
    }

    public function test_seeders_carry_no_password_default_in_source(): void
    {
        $compromised = array_map('strval', (array) config('golive.credential.compromised', []));
        $files = array_merge(
            glob(base_path('Modules/*/database/seeders/*.php')) ?: [],
            glob(base_path('database/seeders/*.php')) ?: [],
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($compromised as $value) {
                $this->assertStringNotContainsString($value, $source, $file);
            }

            $this->assertStringNotContainsString('DEFAULT_PASSWORD', $source, $file);
            // Mật khẩu literal truyền thẳng vào hàm băm ngay trong seeder.
            $this->assertDoesNotMatchRegularExpression('/(?:Hash::make|bcrypt|password_hash)\(\s*[\'"][^\'"]+[\'"]/', $source, $file);
        }
    }

    public function test_openapi_sources_and_generated_docs_do_not_publish_credentials(): void
    {
        $compromised = array_map('strval', (array) config('golive.credential.compromised', []));

        foreach (self::OPENAPI_SOURCES as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            foreach ($compromised as $value) {
                $this->assertStringNotContainsString($value, $source, $relative);
            }

            $this->assertStringNotContainsString("example: 'admin@gmail.com'", $source, $relative);
            // Thuộc tính mật khẩu không được mang giá trị mẫu.
            $this->assertDoesNotMatchRegularExpression("/format:\\s*'password'[^)]*example:/", $source, $relative);
        }

        foreach (self::GENERATED_OPENAPI as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path);
            $generated = (string) file_get_contents($path);

            foreach ($compromised as $value) {
                $this->assertStringNotContainsString($value, $generated, $relative);
            }
        }
    }

    public function test_fixture_password_of_the_test_environment_is_not_a_known_credential(): void
    {
        $this->assertSame(TestCredentials::PASSWORD, config('golive.seed.fixture_password'));
        $this->assertFalse(CredentialPolicy::isKnownDefault(TestCredentials::PASSWORD));
    }

    public function test_login_survives_a_user_agent_longer_than_the_refresh_token_column(): void
    {
        // refresh_tokens.user_agent là varchar(255). Trên Postgres (UAT/prod) một
        // UA dài hơn 255 ký tự làm INSERT ném 22001 và /auth/login trả 500 dù mật
        // khẩu đúng — mà không để lại audit log nào vì exception xảy ra trước khi
        // AuthController ghi LOGIN. SQLite không ép độ dài nên test khẳng định giá
        // trị ĐÃ ĐƯỢC cắt trước khi ghi, đúng bất biến mà Postgres yêu cầu.
        $this->seed(AuthDatabaseSeeder::class);

        $longUserAgent = str_repeat('A', 400);

        // Origin của FE được CORS cho phép: đây là luồng trình duyệt thật, luồng duy nhất
        // nhận refresh token (cookie HttpOnly).
        $this->withHeader('User-Agent', $longUserAgent)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@gmail.com',
                'password' => TestCredentials::PASSWORD,
            ])->assertOk();

        $this->assertSame(1, RefreshToken::query()->count());
        $storedUserAgent = RefreshToken::query()->value('user_agent');

        $this->assertIsString($storedUserAgent);
        $this->assertLessThanOrEqual(255, strlen($storedUserAgent));
        $this->assertStringStartsWith('AAA', $storedUserAgent);
    }
}
