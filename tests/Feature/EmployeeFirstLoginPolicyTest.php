<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cấp tài khoản nhân viên: admin đặt mật khẩu ban đầu -> bắt buộc đổi mật
 * khẩu lần đầu (must_change_password) hoặc tự khoá nếu quá 7 ngày chưa đăng
 * nhập lần nào (first_login_deadline_at + LockExpiredFirstLoginAccounts).
 */
class EmployeeFirstLoginPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        Cache::flush();
    }

    private function createSuperAdmin(string $username = 'test-super-admin'): User
    {
        $role = Role::where('role_code', 'SUPER_ADMIN')->firstOrFail();

        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'display_name' => 'Test Super Admin',
                'email' => "{$username}@gmail.com",
                'password_hash' => Hash::make(TestCredentials::PASSWORD),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );

        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }

    public function test_creating_a_user_sets_must_change_password_and_seven_day_deadline(): void
    {
        $admin = $this->createSuperAdmin();
        $token = JWTAuth::fromUser($admin);

        $response = $this->postJson('/api/v1/users', [
            'username' => 'nhanvien.moi',
            'display_name' => 'Nhan Vien Moi',
            'email' => 'nhanvien.moi@gmail.com',
            'password' => TestCredentials::PASSWORD,
        ], ['Authorization' => "Bearer {$token}"])->assertCreated();

        $created = User::query()->where('username', 'nhanvien.moi')->firstOrFail();

        $this->assertTrue((bool) $created->must_change_password);
        $this->assertNotNull($created->first_login_deadline_at);
        $this->assertTrue($created->first_login_deadline_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertNull($created->last_login_at);
        $response->assertJsonPath('data.username', 'nhanvien.moi');
    }

    public function test_login_records_last_login_at(): void
    {
        $user = User::create([
            'username' => 'nhanvien.login',
            'display_name' => 'Nhan Vien Login',
            'email' => 'nhanvien.login@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->addDays(7),
        ]);

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => TestCredentials::PASSWORD,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_must_change_password_blocks_other_endpoints_but_allows_change_password_and_logout(): void
    {
        $user = User::create([
            'username' => 'nhanvien.buoc',
            'display_name' => 'Nhan Vien Buoc Doi MK',
            'email' => 'nhanvien.buoc@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->addDays(7),
        ]);
        $token = JWTAuth::fromUser($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/auth/me', $headers)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => TestCredentials::PASSWORD,
            'new_password' => 'NewCntt@2026',
            'new_password_confirmation' => 'NewCntt@2026',
        ], $headers)->assertOk();

        $this->assertFalse((bool) $user->fresh()->must_change_password);

        // Sau khi đổi mật khẩu, cờ đã gỡ nên /auth/me phải truy cập được bình thường.
        $newToken = JWTAuth::fromUser($user->fresh());
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$newToken}"])->assertOk();
    }

    public function test_logout_is_allowed_even_while_password_change_is_required(): void
    {
        $user = User::create([
            'username' => 'nhanvien.logout',
            'display_name' => 'Nhan Vien Logout',
            'email' => 'nhanvien.logout@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->addDays(7),
        ]);
        $token = JWTAuth::fromUser($user);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_login_denied_message_distinguishes_auto_lock_from_generic_lock(): void
    {
        $autoLocked = User::create([
            'username' => 'nhanvien.qua-han',
            'display_name' => 'Nhan Vien Qua Han',
            'email' => 'nhanvien.quahan@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'LOCKED',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $autoLocked->email,
            'password' => TestCredentials::PASSWORD,
        ])->assertStatus(403)
            ->assertJsonFragment(['message' => 'Tài khoản đã bị khoá do không đăng nhập lần đầu trong vòng 7 ngày kể từ khi được cấp. Vui lòng liên hệ quản trị viên để được cấp lại mật khẩu.']);

        $adminLocked = User::create([
            'username' => 'nhanvien.bi-khoa',
            'display_name' => 'Nhan Vien Bi Khoa',
            'email' => 'nhanvien.bikhoa@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'LOCKED',
            'version' => 1,
            'must_change_password' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $adminLocked->email,
            'password' => TestCredentials::PASSWORD,
        ])->assertStatus(403)
            ->assertJsonFragment(['message' => 'Tài khoản đang bị khoá hoặc ngừng hoạt động.']);
    }

    public function test_console_command_locks_only_users_past_the_seven_day_deadline(): void
    {
        $expired = User::create([
            'username' => 'nhanvien.se-bi-khoa',
            'display_name' => 'Se Bi Khoa',
            'email' => 'sebikhoa@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->subDay(),
        ]);
        $stillWithinWindow = User::create([
            'username' => 'nhanvien.con-han',
            'display_name' => 'Con Han',
            'email' => 'conhan@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->addDays(2),
        ]);
        $alreadyLoggedIn = User::create([
            'username' => 'nhanvien.da-dang-nhap',
            'display_name' => 'Da Dang Nhap',
            'email' => 'dadangnhap@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => true,
            'first_login_deadline_at' => now()->subDay(),
            'last_login_at' => now()->subHours(2),
        ]);

        $this->artisan('auth:lock-expired-first-login')->assertSuccessful();

        $this->assertSame('LOCKED', $expired->fresh()->status);
        $this->assertSame('ACTIVE', $stillWithinWindow->fresh()->status);
        $this->assertSame('ACTIVE', $alreadyLoggedIn->fresh()->status);
    }
}
