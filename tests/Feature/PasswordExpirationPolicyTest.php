<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\PasswordResetOtp;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;
use Tests\TestCredentials;

class PasswordExpirationPolicyTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
    }

    public function test_password_under_90_days_is_not_expired(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::create([
            'username' => 'nv.89ngay',
            'display_name' => 'Nhan Vien 89 Ngay',
            'email' => 'nv.89ngay@wms-hoanam.test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
        ]);
        $user->markPasswordChangedNow();

        $this->travel(89)->days();

        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $login->assertJsonPath('data.user.must_change_password', false);
        $login->assertJsonPath('data.user.password_expired', false);
        $this->assertNotNull($login->json('data.user.password_expires_at'));

        $token = $login->json('data.access_token');
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.password_expired', false);
    }

    public function test_password_at_90_days_requires_password_change_and_rejects_same_password(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::create([
            'username' => 'nv.90ngay',
            'display_name' => 'Nhan Vien 90 Ngay',
            'email' => 'nv.90ngay@wms-hoanam.test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
        ]);
        $user->markPasswordChangedNow();

        $this->travel(90)->days();

        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $login->assertJsonPath('data.user.must_change_password', true);
        $login->assertJsonPath('data.user.password_expired', true);

        $token = $login->json('data.access_token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/auth/me', $headers)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED')
            ->assertJsonFragment([
                'message' => 'Mật khẩu của bạn đã quá hạn 90 ngày. Vui lòng đặt lại mật khẩu mới để tiếp tục sử dụng hệ thống.',
            ]);

        // Reusing the same password must be rejected
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => TestCredentials::PASSWORD,
            'new_password' => TestCredentials::PASSWORD,
            'new_password_confirmation' => TestCredentials::PASSWORD,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PASSWORD_POLICY_VIOLATION');

        // Changing to a new password resets the 90-day clock and restores access
        $newPassword = bin2hex(random_bytes(16)).'aA1!';
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => TestCredentials::PASSWORD,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ], $headers)->assertOk();

        $this->app['auth']->forgetGuards();
        $relogin = $this->browserLogin($user->email, $newPassword)->assertOk();
        $relogin->assertJsonPath('data.user.must_change_password', false);
        $relogin->assertJsonPath('data.user.password_expired', false);

        $newToken = $relogin->json('data.access_token');
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$newToken}"])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.password_expired', false);
    }

    public function test_account_without_password_changed_at_falls_back_to_created_at_after_90_days(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::create([
            'username' => 'nv.created90',
            'display_name' => 'Nhan Vien Created 90',
            'email' => 'nv.created90@wms-hoanam.test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
            'last_login_at' => now(),
        ]);

        $this->travel(91)->days();

        // First-login auto-lock command must NOT lock an account that has already logged in
        $this->artisan('auth:lock-expired-first-login')->assertSuccessful();
        $this->assertSame('ACTIVE', $user->fresh()->status);

        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $login->assertJsonPath('data.user.must_change_password', true);
        $login->assertJsonPath('data.user.password_expired', true);

        $token = $login->json('data.access_token');
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        // Logout remains allowed even when password is expired
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_forgot_password_reset_clears_90_day_expiration(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::create([
            'username' => 'nv.reset90',
            'display_name' => 'Nhan Vien Reset 90',
            'email' => 'nv.reset90@wms-hoanam.test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
        ]);
        $user->markPasswordChangedNow();

        $this->travel(95)->days();
        $this->assertTrue($user->fresh()->isPasswordExpired());

        $resetToken = Str::random(64);
        PasswordResetOtp::create([
            'user_id' => $user->user_id,
            'email' => $user->email,
            'otp_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
            'reset_token_hash' => Hash::make($resetToken),
            'reset_token_expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $newPassword = bin2hex(random_bytes(16)).'aA1!';
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])->assertOk();

        $this->assertFalse($user->fresh()->isPasswordExpired());
        $this->assertFalse($user->fresh()->requiresPasswordChange());
    }
}
