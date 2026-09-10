<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\PasswordResetOtpMail;
use Modules\Auth\Models\PasswordResetOtp;
use Modules\Auth\Models\User;
use Tests\TestCase;

class PasswordResetOtpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rate limiter dùng cache, không reset theo RefreshDatabase — test khác
        // gọi forgot-password/verify-otp trước đó sẽ làm sai lệch bộ đếm nếu
        // không dọn (mẫu theo AuthorizationTest::setUp()).
        Cache::flush();
    }

    private function makeActiveUser(string $email = 'nhanvien@gmail.com'): User
    {
        return User::create([
            'username' => 'nhanvien.test',
            'display_name' => 'Nhan Vien Test',
            'email' => $email,
            'password_hash' => Hash::make('OldPassword#Test-2026'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * PasswordResetOtpMail implements ShouldQueue nên Mail::to()->send() đẩy
     * vào hàng đợi chứ không gửi thẳng — phải assertQueued, assertSent luôn sai.
     */
    private function captureOtp(): string
    {
        $otp = null;
        Mail::assertQueued(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->assertIsString($otp);

        return $otp;
    }

    public function test_full_forgot_password_flow_resets_the_password(): void
    {
        Mail::fake();
        $user = $this->makeActiveUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        $otp = $this->captureOtp();

        $verify = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp' => $otp,
        ])->assertOk();

        $resetToken = $verify->json('data.reset_token');
        $this->assertIsString($resetToken);
        $this->assertNotSame('', $resetToken);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'new_password' => 'NewCntt@2026',
            'new_password_confirmation' => 'NewCntt@2026',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewCntt@2026', $user->fresh()->password_hash));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewCntt@2026',
        ])->assertOk();
    }

    public function test_forgot_password_does_not_leak_whether_email_exists(): void
    {
        Mail::fake();

        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'khong-ton-tai@gmail.com'])
            ->assertOk();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->makeActiveUser()->email])
            ->assertOk();

        $this->assertSame($unknown->json('message'), $known->json('message'));
        Mail::assertQueued(PasswordResetOtpMail::class, 1);
    }

    public function test_wrong_otp_is_rejected_and_counts_as_an_attempt(): void
    {
        Mail::fake();
        $user = $this->makeActiveUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $this->captureOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp' => '000000',
        ])->assertStatus(422);

        $this->assertSame(1, PasswordResetOtp::query()->where('email', $user->email)->first()->attempts);
    }

    public function test_expired_otp_is_rejected(): void
    {
        Mail::fake();
        $user = $this->makeActiveUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $otp = $this->captureOtp();

        PasswordResetOtp::query()->where('email', $user->email)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp' => $otp,
        ])->assertStatus(422);
    }

    public function test_otp_locks_after_max_attempts(): void
    {
        Mail::fake();
        $user = $this->makeActiveUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $this->captureOtp();

        $maxAttempts = (int) config('password_reset.max_attempts', 5);
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/api/v1/auth/verify-otp', [
                'email' => $user->email,
                'otp' => '000000',
            ])->assertStatus(422);
        }

        // OTP đúng nhưng đã vượt max_attempts -> vẫn phải từ chối, bắt gửi lại mã mới.
        $otp = $this->captureOtp();
        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp' => $otp,
        ])->assertStatus(422);
    }

    public function test_reset_password_rejects_invalid_or_expired_token(): void
    {
        $user = $this->makeActiveUser();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => 'token-khong-hop-le',
            'new_password' => 'NewCntt@2026',
            'new_password_confirmation' => 'NewCntt@2026',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('OldPassword#Test-2026', $user->fresh()->password_hash));
    }
}
