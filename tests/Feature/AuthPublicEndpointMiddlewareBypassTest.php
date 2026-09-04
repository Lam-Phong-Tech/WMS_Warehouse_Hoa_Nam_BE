<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ApiAuthContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * EnforceActiveAccountStatus và EnforcePasswordChangeRequired đăng ký global nên
 * chạy cả trên endpoint không cần đăng nhập. FE gắn `Authorization: Bearer <token
 * cũ>` cho mọi request, kể cả POST /api/v1/auth/login — token đó có thể thuộc tài
 * khoản đã khoá / đang must_change_password, và trước đây làm chính request đăng
 * nhập trả 403 (hoặc 500 nếu header dị dạng). Người dùng kẹt cứng cho tới khi tự
 * xoá localStorage.
 *
 * Test này khoá cả hai chiều: endpoint public phải chạy được bất kể token đính
 * kèm, còn endpoint đã đăng nhập vẫn phải bị chặn như cũ.
 */
class AuthPublicEndpointMiddlewareBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
    }

    private function makeUser(string $username, string $status, bool $mustChangePassword): User
    {
        return User::create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@hoanam.com",
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => $status,
            'version' => 1,
            'must_change_password' => $mustChangePassword,
            'first_login_deadline_at' => $mustChangePassword ? now()->addDays(7) : null,
        ]);
    }

    public function test_login_succeeds_while_a_stale_token_of_a_locked_account_is_attached(): void
    {
        $locked = $this->makeUser('nguoi.bi-khoa', 'LOCKED', false);
        $active = $this->makeUser('nguoi.dang-hoat-dong', 'ACTIVE', false);
        $staleToken = JWTAuth::fromUser($locked);

        $this->postJson('/api/v1/auth/login', [
            'email' => $active->email,
            'password' => 'Cntt@1234',
        ], ['Authorization' => "Bearer {$staleToken}"])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'nguoi.dang-hoat-dong');
    }

    public function test_login_succeeds_while_a_stale_token_requiring_password_change_is_attached(): void
    {
        $mustChange = $this->makeUser('nguoi.phai-doi-mk', 'ACTIVE', true);
        $staleToken = JWTAuth::fromUser($mustChange);

        // Chính chủ đăng nhập lại: phải vào được để tới màn đổi mật khẩu.
        $this->postJson('/api/v1/auth/login', [
            'email' => $mustChange->email,
            'password' => 'Cntt@1234',
        ], ['Authorization' => "Bearer {$staleToken}"])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'nguoi.phai-doi-mk');
    }

    public function test_login_is_not_broken_by_a_malformed_authorization_header(): void
    {
        $active = $this->makeUser('nguoi.header-rac', 'ACTIVE', false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $active->email,
            'password' => 'Cntt@1234',
        ], ['Authorization' => 'Bearer khong-phai-jwt.chi-la-rac'])
            ->assertOk();
    }

    public function test_forgot_password_flow_entrypoint_ignores_an_attached_stale_token(): void
    {
        $locked = $this->makeUser('nguoi.quen-mk', 'LOCKED', false);
        $staleToken = JWTAuth::fromUser($locked);

        // Chỉ cần KHÔNG phải 403 ACCOUNT_DISABLED do middleware global — nội dung
        // nghiệp vụ (gửi OTP hay không) do PasswordResetController quyết định.
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'khong-ton-tai@hoanam.com',
        ], ['Authorization' => "Bearer {$staleToken}"]);

        $this->assertNotSame('ACCOUNT_DISABLED', $response->json('error_code'));
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_locked_account_is_still_blocked_on_authenticated_endpoints(): void
    {
        $locked = $this->makeUser('nguoi.van-bi-chan', 'LOCKED', false);
        $token = JWTAuth::fromUser($locked);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');
    }

    public function test_locked_account_still_cannot_log_in_through_the_controller_check(): void
    {
        $locked = $this->makeUser('nguoi.khoa-login', 'LOCKED', false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $locked->email,
            'password' => 'Cntt@1234',
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');
    }

    public function test_password_change_requirement_is_still_enforced_on_authenticated_endpoints(): void
    {
        $mustChange = $this->makeUser('nguoi.chan-doi-mk', 'ACTIVE', true);
        $token = JWTAuth::fromUser($mustChange);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');
    }

    /**
     * Chống trôi: mọi route `api/v1/auth/*` KHÔNG có `auth:api` đều là entrypoint
     * chạy khi chưa đăng nhập, nên phải nằm trong danh sách miễn trừ. Thêm route
     * public mới mà quên cập nhật ApiAuthContext sẽ làm test này đỏ ngay.
     */
    public function test_every_unauthenticated_auth_route_is_covered_by_the_public_path_patterns(): void
    {
        $uncovered = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/auth/')) {
                continue;
            }

            if (in_array('auth:api', $route->gatherMiddleware(), true)) {
                continue;
            }

            if (! ApiAuthContext::isPublicEndpoint(Request::create('/'.$uri))) {
                $uncovered[] = $uri;
            }
        }

        $this->assertSame([], array_values(array_unique($uncovered)));
    }
}
