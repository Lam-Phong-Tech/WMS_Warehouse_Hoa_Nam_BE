<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapSuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P0 bảo mật 10/09/2026 — `wms:bootstrap-super-admin` là đường DUY NHẤT sinh SUPER_ADMIN
 * trên môi trường mới.
 *
 * Mỗi test dựng đúng trạng thái "production vừa `migrate --force && db:seed --force`":
 * có danh mục quyền/vai trò, KHÔNG có tài khoản nào — vì seeder ngoài local/testing đã
 * thôi tạo `admin` bằng mật khẩu hằng số (Wwhn300ProductionSafeSeedingTest).
 */
class WwhnSecSuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_ENV = 'WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD';

    private const STRONG_PASSWORD = 'Bootstrap#Strong-Secret-2026';

    private const EMAIL = 'first-admin@hoanam.example';

    private TestHandler $logRecords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pretendEnvironment('production');
        $this->removeAllAccounts();

        $seeder = new AuthDatabaseSeeder;
        $seeder->setContainer($this->app);
        $seeder->run();

        $this->assertSame(0, User::withTrashed()->count(), 'Tiền điều kiện: production vừa seed không có tài khoản nào.');

        $this->logRecords = new TestHandler;
        Log::getLogger()->pushHandler($this->logRecords);
    }

    protected function tearDown(): void
    {
        $this->clearSecret();

        parent::tearDown();
    }

    public function test_it_refuses_to_run_without_an_email(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);

        $this->assertSame(1, $this->bootstrap(['--email' => '']));
        $this->assertSame(0, User::withTrashed()->count());
    }

    public function test_it_fails_closed_when_the_secret_environment_variable_is_missing(): void
    {
        $this->clearSecret();

        $this->assertSame(1, $this->bootstrap());
        $this->assertStringContainsString(self::SECRET_ENV, Artisan::output());
        $this->assertSame(0, User::withTrashed()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Sh0rt#Pw'],
            'single character class' => ['aaaaaaaaaaaaaaaaaaaaaaaaa'],
            'contains the email local part' => ['First-Admin#2026-Secret'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_it_rejects_a_weak_initial_password(string $password): void
    {
        $this->provideSecret($password);

        $this->assertSame(1, $this->bootstrap());
        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSecretNeverLeaked($password);
    }

    public function test_it_rejects_a_credential_on_the_compromised_list(): void
    {
        // Mật khẩu đủ dài và đủ nhóm ký tự để cô lập đúng luật "đã lộ".
        $leaked = 'Leaked#Project-Secret-2026';
        config()->set('golive.credential.compromised', [...(array) config('golive.credential.compromised', []), $leaked]);
        $this->provideSecret($leaked);

        $this->assertSame(1, $this->bootstrap());
        $this->assertStringContainsString('đã bị lộ', Artisan::output());
        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSecretNeverLeaked($leaked);
    }

    public function test_it_creates_exactly_one_super_admin_that_must_change_password_on_first_login(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);

        $this->assertSame(0, $this->bootstrap());

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('first-admin', $user->username);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertNotNull($user->first_login_deadline_at);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, (string) $user->password_hash));
        $this->assertTrue($user->roles()->where('role.role_code', 'SUPER_ADMIN')->exists());
        $this->assertSame(1, $this->superAdminAssignments());
        $this->assertSame(1, User::withTrashed()->count());

        $audit = AuditLog::query()->where('action', BootstrapSuperAdmin::AUDIT_ACTION)->get();
        $this->assertCount(1, $audit);
        $this->assertSame((string) $user->user_id, (string) $audit->first()->object_id);

        $this->assertSecretNeverLeaked(self::STRONG_PASSWORD);
    }

    public function test_it_refuses_to_run_a_second_time_and_changes_nothing(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);
        $this->assertSame(0, $this->bootstrap());
        $first = User::query()->where('email', self::EMAIL)->firstOrFail();

        $this->provideSecret('Another#Strong-Secret-2026');
        $this->assertSame(1, $this->bootstrap(['--email' => 'second-admin@hoanam.example']));

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(1, $this->superAdminAssignments());
        $this->assertSame($first->password_hash, $first->fresh()->password_hash);
        $this->assertSame(1, AuditLog::query()->where('action', BootstrapSuperAdmin::AUDIT_REFUSED_ACTION)->count());
    }

    public function test_it_still_refuses_when_the_only_super_admin_is_locked(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);
        $this->assertSame(0, $this->bootstrap());
        User::query()->where('email', self::EMAIL)->update(['status' => 'LOCKED']);

        $this->assertSame(1, $this->bootstrap(['--email' => 'second-admin@hoanam.example']));

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(1, $this->superAdminAssignments());
    }

    public function test_it_never_takes_over_an_existing_account(): void
    {
        $existing = User::query()->create([
            'username' => 'nguoi-dung-co-san',
            'display_name' => 'Người dùng có sẵn',
            'email' => self::EMAIL,
            'password_hash' => Hash::make('Existing#Account-Secret-2026'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $this->provideSecret(self::STRONG_PASSWORD);

        $this->assertSame(1, $this->bootstrap());

        $this->assertSame(0, $this->superAdminAssignments());
        $this->assertTrue(Hash::check('Existing#Account-Secret-2026', (string) $existing->fresh()->password_hash));
    }

    public function test_generated_password_goes_only_to_a_new_private_file(): void
    {
        $this->clearSecret();
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-bootstrap-'.Str::random(12).'.txt';

        try {
            $this->assertSame(0, $this->bootstrap(['--generate-password-file' => $path]));

            $this->assertFileExists($path);
            $secret = trim((string) file_get_contents($path));
            $this->assertGreaterThanOrEqual(24, strlen($secret));

            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame(0600, fileperms($path) & 0777, 'Tệp mật khẩu phải chỉ chủ sở hữu đọc được.');
            }

            $user = User::query()->where('email', self::EMAIL)->firstOrFail();
            $this->assertTrue(Hash::check($secret, (string) $user->password_hash));
            $this->assertSecretNeverLeaked($secret);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_never_overwrites_an_existing_password_file(): void
    {
        $this->clearSecret();
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-bootstrap-'.Str::random(12).'.txt';
        file_put_contents($path, 'khong-duoc-ghi-de');

        try {
            $this->assertSame(1, $this->bootstrap(['--generate-password-file' => $path]));

            $this->assertSame('khong-duoc-ghi-de', file_get_contents($path));
            $this->assertSame(0, User::withTrashed()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_outside_development_it_requires_explicit_confirmation(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);

        $this->artisan('wms:bootstrap-super-admin', ['--email' => self::EMAIL])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertExitCode(1);

        $this->assertSame(0, User::withTrashed()->count());
    }

    public function test_first_login_forces_password_change_then_mfa_enrollment(): void
    {
        $this->provideSecret(self::STRONG_PASSWORD);
        $this->assertSame(0, $this->bootstrap());

        // Phần HTTP chạy như môi trường thường: middleware chính sách (đổi mật khẩu lần đầu,
        // cưỡng chế MFA) không phụ thuộc APP_ENV.
        $this->pretendEnvironment('testing');
        config()->set('mfa.enforce', true);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::STRONG_PASSWORD,
        ])->assertOk();
        $firstToken = (string) $login->json('data.access_token');

        $this->withToken($firstToken)->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        $newPassword = 'Changed#After-First-Login-2026';
        $this->forgetAuthentication();
        $this->withToken($firstToken)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::STRONG_PASSWORD,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])->assertOk();

        // Đăng nhập lại như một client thật: không mang theo bearer cũ (withToken() dính vào
        // mọi request sau của test cho tới khi xoá header).
        $this->forgetAuthentication();
        $this->flushHeaders();
        $relogin = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => $newPassword,
        ])->assertOk();
        $secondToken = (string) $relogin->json('data.access_token');

        // Đổi mật khẩu xong, SUPER_ADMIN vẫn chưa dùng được API nghiệp vụ cho tới khi đăng ký MFA...
        $this->forgetAuthentication();
        $this->withToken($secondToken)->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MFA_ENROLLMENT_REQUIRED');

        // ...nhưng không tự khoá mình: nhóm endpoint dùng ĐỂ đăng ký MFA vẫn mở cho chính token đó.
        $this->forgetAuthentication();
        $this->withToken($secondToken)->getJson('/api/v1/auth/mfa/status')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function bootstrap(array $options = []): int
    {
        return Artisan::call('wms:bootstrap-super-admin', $options + ['--email' => self::EMAIL, '--force' => true]);
    }

    private function provideSecret(string $value): void
    {
        putenv(self::SECRET_ENV.'='.$value);
        $_SERVER[self::SECRET_ENV] = $value;
        $_ENV[self::SECRET_ENV] = $value;
    }

    private function clearSecret(): void
    {
        putenv(self::SECRET_ENV);
        unset($_SERVER[self::SECRET_ENV], $_ENV[self::SECRET_ENV]);
    }

    private function assertSecretNeverLeaked(string $secret): void
    {
        $this->assertStringNotContainsString($secret, Artisan::output(), 'Mật khẩu không được in ra output của lệnh.');

        foreach ($this->logRecords->getRecords() as $record) {
            $this->assertStringNotContainsString(
                $secret,
                (string) json_encode([$record->message, $record->context, $record->extra]),
                'Mật khẩu không được ghi vào log ứng dụng.'
            );
        }

        $this->assertStringNotContainsString($secret, AuditLog::query()->get()->toJson(), 'Mật khẩu không được ghi vào audit log.');
    }

    private function superAdminAssignments(): int
    {
        $roleId = Role::query()->where('role_code', 'SUPER_ADMIN')->value('role_id');

        return $roleId === null ? 0 : DB::table('user_role')->where('role_id', $roleId)->count();
    }

    private function pretendEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
        config()->set('golive.development_environments', ['local', 'testing']);
        config()->set('golive.demo_environments', ['uat', 'demo']);
        config()->set('golive.seed.demo_accounts', false);
    }

    private function removeAllAccounts(): void
    {
        if (! User::withTrashed()->exists()) {
            return;
        }

        DB::table('user_role')->delete();
        User::withTrashed()->get()->each(fn (User $user) => $user->forceDelete());
    }

    /** JWTGuard nhớ user và tymon nhớ token giữa các request trong cùng một test. */
    private function forgetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        app('tymon.jwt')->unsetToken();
    }
}
