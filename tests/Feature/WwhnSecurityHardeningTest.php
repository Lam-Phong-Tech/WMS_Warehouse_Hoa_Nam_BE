<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureApiDocsEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * Hồi quy cho đợt hardening WWHN-SEC-01..04.
 *
 * Mỗi test khoá đúng một finding đã đo được TRƯỚC khi sửa, kèm số liệu gốc:
 *
 *  SEC-01  Không một route `auth:api` nào có trần gọi. Bằng chứng trước khi sửa:
 *          `route:list` = 0/321 route có `throttle:`, trong đó 170 route GHI;
 *          bắn 60 request liên tiếp vào GET /api/v1/products trả 60x HTTP 200.
 *          (OWASP API4:2023 Unrestricted Resource Consumption.)
 *
 *  SEC-02  Đổi mật khẩu chỉ thu hồi refresh token; access token JWT phát hành
 *          trước đó vẫn dùng được tới khi hết hạn tự nhiên (JWT_TTL của môi
 *          trường máy quét là 30 ngày). (OWASP API2:2023.)
 *
 *  SEC-03  Response không có header bảo mật nào và lộ `X-Powered-By: PHP/8.3.x`.
 *
 *  SEC-04  GET /api/documentation trả 200 khi chưa đăng nhập, ở mọi môi trường —
 *          công bố bản đồ 345 endpoint. (OWASP API9:2023.)
 */
class WwhnSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bộ đếm rate limit dùng chung cache store; không xoá thì test chạy sau
        // thừa hưởng bộ đếm của test chạy trước và fail theo thứ tự chạy.
        foreach (['read', 'write', 'export', 'unauthenticated'] as $tier) {
            RateLimiter::clear('api-rl:'.$tier.':'.sha1('ip:127.0.0.1'));
        }
    }

    private function makeUser(string $password = 'Cntt@1234'): User
    {
        $suffix = uniqid();

        return User::create([
            'username' => 'sec-'.$suffix,
            'display_name' => 'Security hardening fixture',
            'email' => 'sec-'.$suffix.'@example.test',
            'password_hash' => Hash::make($password),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    // ---------------------------------------------------------------- SEC-01

    public function test_authenticated_read_endpoint_is_rate_limited(): void
    {
        config(['rate_limit.api_read_per_minute' => 3]);
        $headers = $this->bearer($this->makeUser());

        for ($i = 1; $i <= 3; $i++) {
            $this->getJson('/api/v1/auth/me', $headers)->assertStatus(200);
        }

        $blocked = $this->getJson('/api/v1/auth/me', $headers);

        $blocked->assertStatus(429);
        // Envelope 429 phải giữ đúng contract §8.2 để FE parse được, không phải
        // body mặc định {"message":"Too Many Attempts."} của Laravel.
        $blocked->assertJsonPath('success', false);
        $blocked->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $blocked->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
        $this->assertNotNull($blocked->headers->get('Retry-After'));
    }

    public function test_rate_limit_headers_are_published_for_client_backoff(): void
    {
        config(['rate_limit.api_read_per_minute' => 50]);

        $response = $this->getJson('/api/v1/auth/me', $this->bearer($this->makeUser()));

        $response->assertStatus(200);
        $this->assertSame('50', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('49', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Tính chất UX quan trọng nhất của thiết kế: nhóm GHI và nhóm ĐỌC là hai bộ
     * đếm ĐỘC LẬP. Một người bấm liên tục nút ghi không được phép làm nhân viên
     * đó mất luôn khả năng đọc dữ liệu để làm việc tiếp.
     */
    public function test_write_burst_does_not_lock_the_user_out_of_reads(): void
    {
        config([
            'rate_limit.api_write_per_minute' => 1,
            'rate_limit.api_read_per_minute' => 100,
        ]);
        $headers = $this->bearer($this->makeUser());

        $this->postJson('/api/v1/auth/switch-context', [], $headers)->assertStatus(200);
        $this->postJson('/api/v1/auth/switch-context', [], $headers)->assertStatus(429);

        $this->getJson('/api/v1/auth/me', $headers)->assertStatus(200);
    }

    /**
     * Hồi quy cho một lỗi đã mắc trong chính đợt này: bản đầu đăng ký ApiRateLimit
     * làm middleware GLOBAL. Middleware global chạy TRƯỚC bước khớp route nên
     * `$request->route()` luôn null, `hasExplicitThrottle()` không bao giờ thấy
     * `throttle:pv-public`, và nhóm PV công khai bị đếm hai lần — hạ trần thực tế
     * từ 120/phút xuống 60/phút. Test này khoá việc PV giữ limiter riêng của nó.
     */
    public function test_public_pv_endpoints_are_not_double_counted(): void
    {
        config(['rate_limit.api_unauthenticated_per_minute' => 1]);

        $this->getJson('/api/v1/pv/tags')->assertStatus(200);
        $this->getJson('/api/v1/pv/tags')->assertStatus(200);
    }

    public function test_login_endpoint_keeps_its_own_failure_only_throttle(): void
    {
        // /auth/login nằm trong PUBLIC_PATH_PATTERNS nên ApiRateLimit bỏ qua:
        // bộ đếm brute-force của AuthController chỉ tính lần THẤT BẠI, còn trần
        // theo IP ở đây sẽ khoá nhầm cả kho dùng chung một đường NAT.
        config(['rate_limit.api_unauthenticated_per_minute' => 1]);
        $user = $this->makeUser('Cntt@1234');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Cntt@1234',
            ])->assertStatus(200);
        }
    }

    // ---------------------------------------------------------------- SEC-02

    public function test_access_token_issued_before_password_change_is_rejected(): void
    {
        $user = $this->makeUser('Cntt@1234');
        $stolen = $this->bearer($user);

        $this->getJson('/api/v1/auth/me', $stolen)->assertStatus(200);

        // Nhảy thời gian trước khi đổi mật khẩu: claim `iat` của JWT chỉ có độ
        // phân giải GIÂY, nên token cấp trong cùng giây với lúc đổi mật khẩu
        // không thể phân biệt được với token cấp sau. Xem
        // test_token_within_the_same_second_is_the_documented_granularity_limit.
        $this->travel(5)->seconds();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Cntt@1234',
            'new_password' => 'NewCntt@2026',
            'new_password_confirmation' => 'NewCntt@2026',
        ], $stolen)->assertStatus(200);

        $replayed = $this->getJson('/api/v1/auth/me', $stolen);
        $replayed->assertStatus(401);
        $replayed->assertJsonPath('error_code', 'CREDENTIALS_CHANGED');

        $this->travelBack();
    }

    /**
     * Ghi rõ giới hạn đã biết thay vì giấu nó: token cấp trong CÙNG GIÂY với
     * thời điểm đổi mật khẩu vẫn được chấp nhận.
     *
     * Đây là ràng buộc của chính định dạng JWT (`iat` tính bằng giây), không
     * phải thiếu sót cài đặt — và là đánh đổi CÓ CHỦ Ý: so sánh chặt hơn sẽ từ
     * chối luôn token vừa cấp lại cho chính người vừa đổi mật khẩu. Các lớp thu
     * hồi còn lại (refresh token, blacklist khi logout, EnforceActiveAccountStatus)
     * không phụ thuộc cửa sổ này.
     */
    public function test_token_within_the_same_second_is_the_documented_granularity_limit(): void
    {
        $user = $this->makeUser('Cntt@1234');
        $sameSecondToken = $this->bearer($user);

        $user->markPasswordChangedNow();

        $this->getJson('/api/v1/auth/me', $sameSecondToken)->assertStatus(200);
    }

    /**
     * Vế còn lại, và là vế dễ hỏng nhất: token cấp SAU khi đổi mật khẩu phải chạy
     * bình thường. Bản cài đầu tiên fail đúng ở đây — `now()` (+07) bị bind xuống
     * PostgreSQL dưới dạng chuỗi KHÔNG kèm offset nên mốc bị lưu lệch +7 giờ về
     * tương lai, khiến người vừa đổi mật khẩu không đăng nhập lại được suốt 7 giờ.
     */
    public function test_token_issued_after_password_change_still_works(): void
    {
        $user = $this->makeUser('Cntt@1234');

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Cntt@1234',
            'new_password' => 'NewCntt@2026',
            'new_password_confirmation' => 'NewCntt@2026',
        ], $this->bearer($user))->assertStatus(200);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewCntt@2026',
        ]);
        $login->assertStatus(200);

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$login->json('data.access_token'),
        ])->assertStatus(200);
    }

    public function test_password_change_marker_is_not_in_the_future(): void
    {
        $user = $this->makeUser('Cntt@1234');
        $user->markPasswordChangedNow();

        $skew = $user->fresh()->passwordChangedAtEpoch() - now()->getTimestamp();

        $this->assertLessThanOrEqual(
            5,
            abs($skew),
            'Mốc đổi mật khẩu lệch '.$skew.'s so với hiện tại — sai quy ước timezone sẽ khoá nhầm token vừa cấp.'
        );
    }

    // ---------------------------------------------------------------- SEC-03

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('X-Powered-By'));
        $this->assertStringContainsString(
            "default-src 'none'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_security_headers_also_cover_error_responses(): void
    {
        // Đăng ký ở vị trí ngoài cùng nên phải phủ cả response do middleware và
        // exception handler sinh ra, không chỉ response của controller.
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(401);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ---------------------------------------------------------------- SEC-04

    public function test_api_docs_are_disabled_on_production_by_default(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(
            EnsureApiDocsEnabled::enabled(),
            'Swagger UI phải tắt mặc định trên production (OWASP API9 Improper Inventory Management).'
        );
    }

    public function test_api_docs_stay_available_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertTrue(
            EnsureApiDocsEnabled::enabled(),
            'DEV/UAT/QA phải giữ được Swagger như hiện tại — hardening không được lấy mất công cụ của đội.'
        );
    }

    // ------------------------------------------------- neo hồi quy authn/authz

    public function test_protected_endpoints_still_reject_anonymous_callers(): void
    {
        foreach (['/api/v1/users', '/api/v1/warehouses', '/api/v1/roles', '/api/v1/audit-logs'] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
    }

    public function test_authenticated_user_without_permission_is_still_denied(): void
    {
        $headers = $this->bearer($this->makeUser());

        foreach (['/api/v1/users', '/api/v1/warehouses', '/api/v1/roles'] as $path) {
            $this->getJson($path, $headers)->assertStatus(403);
        }
    }
}
