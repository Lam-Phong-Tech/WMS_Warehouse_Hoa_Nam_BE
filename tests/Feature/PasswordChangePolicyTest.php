<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/**
 * Chính sách mật khẩu đổi tự phục vụ: thông điệp field-level phải khớp đúng
 * copy đã chốt, và mật khẩu hệ thống cấp (A) không được tái sử dụng sau A→B.
 */
class PasswordChangePolicyTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const BASE = '/api/v1/auth/';

    private const STRONG = 'Strong#Pass2026';

    public function test_blank_fields_return_exact_vietnamese_messages(): void
    {
        $user = $this->createUser('blank', self::STRONG);
        $token = $this->login($user->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => '',
            'new_password' => '',
            'new_password_confirmation' => '',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'Vui lòng nhập mật khẩu hiện tại')
            ->assertJsonPath('errors.new_password.0', 'Vui lòng nhập mật khẩu mới')
            ->assertJsonPath('errors.new_password_confirmation.0', 'Vui lòng xác nhận mật khẩu');
    }

    public function test_wrong_current_password_has_exact_message(): void
    {
        $user = $this->createUser('wrong-current', self::STRONG);
        $token = $this->login($user->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => 'Wrong#Pass2026',
            'new_password' => 'Another#Pass2026',
            'new_password_confirmation' => 'Another#Pass2026',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT')
            ->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không chính xác');
    }

    #[DataProvider('weakPasswordProvider')]
    public function test_weak_passwords_return_exact_messages(string $password, string $message): void
    {
        $user = $this->createUser('weak-'.Str::lower(Str::random(8)), self::STRONG);
        $token = $this->login($user->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => self::STRONG,
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', $message);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function weakPasswordProvider(): array
    {
        return [
            'short' => ['Short1!', 'Mật khẩu mới tối thiểu 8 ký tự'],
            'over 64 characters' => ['Aa1!'.str_repeat('x', 61), 'Mật khẩu mới không được vượt quá 64 ký tự'],
            'missing uppercase' => ['lowercase1!', 'Mật khẩu phải bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt'],
            'missing lowercase' => ['UPPERCASE1!', 'Mật khẩu phải bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt'],
            'missing digit' => ['NoDigits!!', 'Mật khẩu phải bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt'],
            'missing special' => ['NoSpecial123', 'Mật khẩu phải bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt'],
            'space' => ['Has Space1!A', 'Mật khẩu không được chứa khoảng trắng'],
            'tab' => ["Tabbed\t1!A", 'Mật khẩu không được chứa khoảng trắng'],
        ];
    }

    public function test_password_containing_username_or_email_local_part_is_rejected(): void
    {
        $user = $this->createUser('danghong2003', self::STRONG, email: 'danghong2003@example.test');
        $token = $this->login($user->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => self::STRONG,
            'new_password' => 'Danghong2003@123',
            'new_password_confirmation' => 'Danghong2003@123',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Mật khẩu mới không được chứa tên đăng nhập');

        $generatedUsername = $this->createUser(
            'u-11a24255-3967-4dd5-b3ca-2178b714ec5c',
            self::STRONG,
            email: 'danghong2003@example.org',
        );
        $generatedToken = $this->login($generatedUsername->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => self::STRONG,
            'new_password' => 'Danghong2003@123',
            'new_password_confirmation' => 'Danghong2003@123',
        ], $this->headers($generatedToken))->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Mật khẩu mới không được chứa tên đăng nhập');
    }

    public function test_confirmation_mismatch_is_reported_on_confirmation_field(): void
    {
        $user = $this->createUser('confirmation', self::STRONG);
        $token = $this->login($user->email, self::STRONG);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => self::STRONG,
            'new_password' => 'Another#Pass2026',
            'new_password_confirmation' => 'Different#Pass2026',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_MISMATCH')
            ->assertJsonPath('errors.new_password_confirmation.0', 'Mật khẩu xác nhận không khớp với mật khẩu mới');
    }

    public function test_system_issued_initial_password_cannot_be_reused_after_first_change(): void
    {
        $initial = 'Initial#Pass2026';
        $changed = 'User#Pass2026';
        $user = $this->createUser('system-issued', $initial, mustChangePassword: true);
        $token = $this->login($user->email, $initial);

        $this->postJson(self::BASE.'change-password', [
            'current_password' => $initial,
            'new_password' => $changed,
            'new_password_confirmation' => $changed,
        ], $this->headers($token))->assertOk();

        $token = $this->login($user->email, $changed);
        $this->postJson(self::BASE.'change-password', [
            'current_password' => $changed,
            'new_password' => $initial,
            'new_password_confirmation' => $initial,
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_POLICY_VIOLATION')
            ->assertJsonPath('errors.new_password.0', 'Mật khẩu mới không được trùng với các mật khẩu đã sử dụng trước đó');
        $this->assertTrue(Hash::check($changed, $user->fresh()->password_hash));
    }

    public function test_system_issued_password_stays_blocked_beyond_user_history_limit(): void
    {
        config()->set('login_security.password.history_limit', 1);
        $initial = 'Initial#Pass2026';
        $user = $this->createUser('system-permanent', $initial, mustChangePassword: true);

        $currentPassword = $initial;
        $token = $this->login($user->email, $currentPassword);
        foreach (['User#Pass2026', 'User#Pass2027', 'User#Pass2028'] as $nextPassword) {
            $this->postJson(self::BASE.'change-password', [
                'current_password' => $currentPassword,
                'new_password' => $nextPassword,
                'new_password_confirmation' => $nextPassword,
            ], $this->headers($token))->assertOk();
            $currentPassword = $nextPassword;
            $token = $this->login($user->email, $currentPassword);
        }

        $this->postJson(self::BASE.'change-password', [
            'current_password' => $currentPassword,
            'new_password' => $initial,
            'new_password_confirmation' => $initial,
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Mật khẩu mới không được trùng với các mật khẩu đã sử dụng trước đó');
    }

    public function test_user_set_history_respects_configured_limit(): void
    {
        config()->set('login_security.password.history_limit', 2);
        $initial = 'Initial#Pass2026';
        $user = $this->createUser('history-limit', $initial, mustChangePassword: true);

        $currentPassword = $initial;
        $token = $this->login($user->email, $currentPassword);
        foreach (['User#Pass2026', 'User#Pass2027'] as $nextPassword) {
            $this->postJson(self::BASE.'change-password', [
                'current_password' => $currentPassword,
                'new_password' => $nextPassword,
                'new_password_confirmation' => $nextPassword,
            ], $this->headers($token))->assertOk();
            $currentPassword = $nextPassword;
            $token = $this->login($user->email, $currentPassword);
        }

        // B vẫn nằm trong 2 mật khẩu người dùng gần nhất nên bị chặn.
        $this->postJson(self::BASE.'change-password', [
            'current_password' => $currentPassword,
            'new_password' => 'User#Pass2026',
            'new_password_confirmation' => 'User#Pass2026',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Mật khẩu mới không được trùng với các mật khẩu đã sử dụng trước đó');

        foreach (['User#Pass2028', 'User#Pass2029'] as $nextPassword) {
            $this->postJson(self::BASE.'change-password', [
                'current_password' => $currentPassword,
                'new_password' => $nextPassword,
                'new_password_confirmation' => $nextPassword,
            ], $this->headers($token))->assertOk();
            $currentPassword = $nextPassword;
            $token = $this->login($user->email, $currentPassword);
        }

        // Sau hai lần đổi tiếp, B đã ra khỏi giới hạn 2 và được phép dùng lại.
        $this->postJson(self::BASE.'change-password', [
            'current_password' => $currentPassword,
            'new_password' => 'User#Pass2026',
            'new_password_confirmation' => 'User#Pass2026',
        ], $this->headers($token))->assertOk();
    }

    private function createUser(
        string $username,
        string $password,
        bool $mustChangePassword = false,
        ?string $email = null,
    ): User {
        return User::create([
            'username' => $username,
            'email' => $email ?? $username.'@example.test',
            'display_name' => 'Password policy fixture',
            'password_hash' => Hash::make($password),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function login(string $email, string $password): string
    {
        $this->resetGuards();

        return $this->browserLogin($email, $password)->assertOk()->json('data.access_token');
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        $this->resetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
