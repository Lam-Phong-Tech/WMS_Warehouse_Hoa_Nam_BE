<?php

namespace Tests\Feature;

use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Database\Seeders\IamCoverageSeeder;
use Modules\Auth\Database\Seeders\RoleAccountMatrixSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\UatScenarioSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * WWHN-300 / DEV300-P1-01 + P0 bảo mật 10/09/2026 — bằng chứng "production-safe seeding".
 *
 * Lịch sử: bản vá WWHN-300 đầu tiên buộc seeder đòi WMS_SEED_ADMIN_PASSWORD, rồi bị HỒI
 * QUY về một hằng số mật khẩu trong source — `adminPassword()` trả thẳng
 * DEFAULT_PASSWORD, và `admin` được updateOrCreate TRƯỚC nhánh chặn fixture. Mỗi lần
 * `db:seed` trên production vì thế reset mật khẩu, bật lại ACTIVE và gắn lại SUPER_ADMIN.
 * Bốn test của lớp này đỏ suốt thời gian đó mà không chặn được gì, vì full suite chạy
 * advisory trong CI.
 *
 * Hợp đồng MỚI chặt hơn bản WWHN-300: ngoài local/testing seeder KHÔNG tạo, không sửa,
 * không kích hoạt lại tài khoản nào — kể cả khi được "cấp" mật khẩu. Quản trị viên đầu
 * tiên đi qua `wms:bootstrap-super-admin` (WwhnSecSuperAdminBootstrapTest).
 */
class Wwhn300ProductionSafeSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_ADMIN_PASSWORD = 'R3al#Admin-Rotated-2026!';

    private const STRONG_DEMO_PASSWORD = 'Demo#Uat-Fixture-2026';

    private const BUSINESS_ROLES = ['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE_MANAGER', 'WAREHOUSE_STAFF', 'WARRANTY_STAFF', 'VIEWER', 'LEADERSHIP'];

    /** Vai trò test + hai vai ngừng dùng (BA taikhoantheorole v2, nguyên tắc 6). */
    private const FIXTURE_ONLY_ROLES = ['SKU_MANAGER', 'MASTER_MANAGER', 'NO_PERMISSION', 'INBOUND_ONLY_TEST', 'WAREHOUSE_KEEPER', 'WAREHOUSE_APPROVER'];

    private const FIXTURE_USERNAMES = [
        'admin', 'staff01', 'approver01', 'keeper01', 'viewer01', 'manager01',
        'test-sku-manage', 'test-master-manage', 'test-no-permission', 'test-super-admin', 'inbound-tester01',
    ];

    public function test_production_seed_creates_no_account_and_only_seeds_permissions_roles_and_mappings(): void
    {
        $this->removeFixtureAccounts();
        $this->removeFixtureRoles();
        $usersBefore = User::withTrashed()->count();
        $assignmentsBefore = DB::table('user_role')->count();
        $this->pretendEnvironment('production');

        $this->runSeeder(AuthDatabaseSeeder::class);

        $this->assertSame($usersBefore, User::withTrashed()->count(), 'db:seed ngoài local/testing không được tạo tài khoản nào.');
        $this->assertSame($assignmentsBefore, DB::table('user_role')->count(), 'db:seed ngoài local/testing không được gắn vai trò cho tài khoản nào.');
        $this->assertDatabaseMissing('app_user', ['username' => 'admin']);

        $this->assertGreaterThan(0, Permission::query()->count());
        foreach (self::BUSINESS_ROLES as $roleCode) {
            $this->assertDatabaseHas('role', ['role_code' => $roleCode]);
        }

        // Mapping vai trò → quyền vẫn phải có, nếu không production không vận hành được.
        $staffPermissions = Role::query()->where('role_code', 'WAREHOUSE_STAFF')->firstOrFail()
            ->permissions()->pluck('permission_key')->all();
        $this->assertContains('inbound.post', $staffPermissions);

        foreach (self::FIXTURE_ONLY_ROLES as $roleCode) {
            $this->assertDatabaseMissing('role', ['role_code' => $roleCode]);
        }
    }

    public function test_production_seed_never_resets_or_rewrites_an_existing_admin(): void
    {
        $this->removeFixtureAccounts();
        $admin = $this->createRealAdmin('ACTIVE', withSuperAdminRole: true);
        $before = DB::table('app_user')->where('user_id', $admin->user_id)->first();
        $this->pretendEnvironment('production');

        // Chạy hai lần: lần chạy lại cũng không được đổi gì.
        $this->runSeeder(AuthDatabaseSeeder::class);
        $this->runSeeder(AuthDatabaseSeeder::class);

        $after = DB::table('app_user')->where('user_id', $admin->user_id)->first();
        $this->assertSame($before->password_hash, $after->password_hash);
        $this->assertSame((string) $before->email, (string) $after->email);
        $this->assertSame((int) $before->version, (int) $after->version);
        $this->assertTrue(Hash::check(self::REAL_ADMIN_PASSWORD, (string) $after->password_hash));
        $this->assertFalse(Hash::check(TestCredentials::PASSWORD, (string) $after->password_hash));

        foreach ((array) config('golive.credential.compromised', []) as $compromised) {
            $this->assertFalse(Hash::check((string) $compromised, (string) $after->password_hash));
        }

        $this->assertSame(1, DB::table('user_role')->where('user_id', $admin->user_id)->count());
    }

    /** @return array<string, array{0: string}> */
    public static function disabledStatuses(): array
    {
        return ['LOCKED' => ['LOCKED'], 'INACTIVE' => ['INACTIVE']];
    }

    #[DataProvider('disabledStatuses')]
    public function test_production_seed_never_reactivates_or_regrants_a_disabled_admin(string $status): void
    {
        $this->removeFixtureAccounts();
        $admin = $this->createRealAdmin($status, withSuperAdminRole: false);
        $this->pretendEnvironment('production');

        $this->runSeeder(AuthDatabaseSeeder::class);

        $fresh = $admin->fresh();
        $this->assertSame($status, $fresh->status, 'Seeder không được kích hoạt lại tài khoản admin đang bị khoá/ngừng.');
        $this->assertSame(
            0,
            DB::table('user_role')->where('user_id', $admin->user_id)->count(),
            'Seeder không được gắn lại SUPER_ADMIN cho tài khoản đã bị gỡ quyền.'
        );
        $this->assertTrue(Hash::check(self::REAL_ADMIN_PASSWORD, (string) $fresh->password_hash));
    }

    public function test_production_seed_requires_no_credential_configuration_at_all(): void
    {
        $this->removeFixtureAccounts();
        config()->set('golive.seed.fixture_password', null);
        $this->pretendEnvironment('production');

        $this->runSeeder(AuthDatabaseSeeder::class);

        $this->assertDatabaseMissing('app_user', ['username' => 'admin']);
    }

    public function test_demo_flag_is_ignored_outside_declared_demo_environments(): void
    {
        $this->removeFixtureAccounts();

        // Kể cả tên gõ sai/khác hoa thường: không nằm trong golive.demo_environments thì là
        // production.
        foreach (['production', 'staging', 'Production', 'prod', 'UAT'] as $environment) {
            $this->pretendEnvironment($environment);
            config()->set('golive.seed.demo_accounts', true);
            config()->set('golive.seed.fixture_password', self::STRONG_DEMO_PASSWORD);

            $this->runSeeder(AuthDatabaseSeeder::class);

            foreach (['staff01', 'test-super-admin', 'warehouse-staff-01'] as $username) {
                $this->assertDatabaseMissing('app_user', ['username' => $username]);
            }
        }
    }

    public function test_declared_demo_environment_gets_business_fixtures_but_never_a_super_admin_account(): void
    {
        $this->removeFixtureAccounts();
        $this->pretendEnvironment('uat');
        config()->set('golive.seed.demo_accounts', true);
        config()->set('golive.seed.fixture_password', self::STRONG_DEMO_PASSWORD);

        $this->runSeeder(AuthDatabaseSeeder::class);

        $staff = User::query()->where('username', 'staff01')->firstOrFail();
        $this->assertTrue(Hash::check(self::STRONG_DEMO_PASSWORD, (string) $staff->password_hash));
        $this->assertDatabaseHas('app_user', ['username' => 'warehouse-staff-01']);

        foreach (['admin', 'test-super-admin', 'super-admin-01', 'super-admin-02', 'super-admin-03'] as $username) {
            $this->assertDatabaseMissing('app_user', ['username' => $username]);
        }

        $this->assertSame(0, $this->superAdminAssignments(), 'Môi trường demo/UAT không được có tài khoản SUPER_ADMIN fixture.');
    }

    public function test_demo_environment_rejects_a_compromised_or_weak_fixture_password(): void
    {
        $this->removeFixtureAccounts();

        foreach ([(string) config('golive.credential.compromised.0'), 'short1', 'alllowercaseletters'] as $password) {
            $this->pretendEnvironment('uat');
            config()->set('golive.seed.demo_accounts', true);
            config()->set('golive.seed.fixture_password', $password);

            try {
                $this->runSeeder(AuthDatabaseSeeder::class);
                $this->fail('Seeder phải từ chối mật khẩu fixture yếu hoặc đã lộ trên môi trường demo/UAT.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('WMS_FIXTURE_PASSWORD', $exception->getMessage());
                $this->assertStringNotContainsString($password, $exception->getMessage());
            }

            $this->assertDatabaseMissing('app_user', ['username' => 'staff01']);
        }
    }

    public function test_fixture_seeders_refuse_direct_invocation_outside_development_and_demo(): void
    {
        $this->pretendEnvironment('production');
        config()->set('golive.seed.fixture_password', self::STRONG_DEMO_PASSWORD);
        config()->set('warehouse.uat_seed_password', self::STRONG_DEMO_PASSWORD);
        $usersBefore = User::withTrashed()->count();

        foreach ([IamCoverageSeeder::class, RoleAccountMatrixSeeder::class, UatScenarioSeeder::class] as $seeder) {
            try {
                $this->runSeeder($seeder);
                $this->fail(class_basename($seeder).' phải từ chối chạy trên production.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(class_basename($seeder), $exception->getMessage());
            }
        }

        $this->assertSame($usersBefore, User::withTrashed()->count());
    }

    public function test_wms_seed_uat_command_refuses_to_run_on_production(): void
    {
        $this->pretendEnvironment('production');

        $this->artisan('wms:seed-uat', ['--password' => self::STRONG_DEMO_PASSWORD])->assertExitCode(1);

        $this->assertDatabaseMissing('app_user', ['username' => 'uat.inbound']);
    }

    public function test_development_seed_keeps_fixture_accounts_on_the_configured_password(): void
    {
        $this->removeFixtureAccounts();

        $this->runSeeder(AuthDatabaseSeeder::class);

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check(TestCredentials::PASSWORD, (string) $admin->password_hash));
        $this->assertTrue($admin->roles()->where('role.role_code', 'SUPER_ADMIN')->exists());
        $this->assertDatabaseHas('app_user', ['username' => 'test-super-admin']);
        $this->assertDatabaseHas('app_user', ['username' => 'super-admin-01']);
        $this->assertDatabaseHas('role', ['role_code' => 'NO_PERMISSION']);
    }

    public function test_development_seed_fails_closed_without_a_fixture_password(): void
    {
        $this->removeFixtureAccounts();
        config()->set('golive.seed.fixture_password', null);

        try {
            $this->runSeeder(AuthDatabaseSeeder::class);
            $this->fail('Thiếu WMS_FIXTURE_PASSWORD thì seeder phải dừng, không được rơi về mật khẩu mặc định.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('WMS_FIXTURE_PASSWORD', $exception->getMessage());
        }

        $this->assertDatabaseMissing('app_user', ['username' => 'admin']);
    }

    /**
     * @param  class-string<Seeder>  $class
     */
    private function runSeeder(string $class): void
    {
        $seeder = new $class;
        $seeder->setContainer($this->app);
        $seeder->run();
    }

    private function pretendEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
        config()->set('golive.development_environments', ['local', 'testing']);
        config()->set('golive.demo_environments', ['uat', 'demo']);
        config()->set('golive.seed.demo_accounts', false);
    }

    private function createRealAdmin(string $status, bool $withSuperAdminRole): User
    {
        $user = User::query()->create([
            'username' => 'admin',
            'display_name' => 'Quản trị thật',
            'email' => 'it-admin@hoanam.example',
            'password_hash' => Hash::make(self::REAL_ADMIN_PASSWORD),
            'status' => $status,
            'version' => 7,
        ]);

        if ($withSuperAdminRole) {
            $role = Role::query()->firstOrCreate(
                ['role_code' => 'SUPER_ADMIN'],
                ['role_name' => 'Quản trị hệ thống', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }

    private function superAdminAssignments(): int
    {
        $roleId = Role::query()->where('role_code', 'SUPER_ADMIN')->value('role_id');

        return $roleId === null ? 0 : DB::table('user_role')->where('role_id', $roleId)->count();
    }

    /**
     * Bộ test khác trong cùng tiến trình có thể đã seed sẵn, nên xoá fixture trước để
     * phép khẳng định "không được tạo ra" nói đúng điều nó định nói.
     */
    private function removeFixtureAccounts(): void
    {
        $userIds = User::withTrashed()->whereIn('username', self::FIXTURE_USERNAMES)->pluck('user_id')->all();

        if ($userIds === []) {
            return;
        }

        DB::table('user_role')->whereIn('user_id', $userIds)->delete();
        User::withTrashed()->whereIn('user_id', $userIds)->forceDelete();
    }

    private function removeFixtureRoles(): void
    {
        $roleIds = Role::query()->whereIn('role_code', self::FIXTURE_ONLY_ROLES)->pluck('role_id')->all();

        if ($roleIds === []) {
            return;
        }

        DB::table('user_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('role_permission')->whereIn('role_id', $roleIds)->delete();
        Role::query()->whereIn('role_id', $roleIds)->delete();
    }
}
