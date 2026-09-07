<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * WWHN-300 / DEV300-P1-01 — bằng chứng "production-safe seeding".
 *
 * `AuthDatabaseSeeder` từng tạo tài khoản SUPER_ADMIN `admin` bằng một hằng số
 * nằm trong source code (`Cntt@1234`) và kèm theo 9 tài khoản mẫu — trong đó có
 * `test-super-admin` — trên MỌI môi trường, không cảnh báo gì. Chạy `db:seed`
 * một lần trên production là đủ để mở backdoor quyền cao nhất.
 *
 * Lớp test này chứng minh hai nửa của bản sửa: seeder TỪ CHỐI chạy khi thiếu
 * credential được cấp, và khi được cấp thì nó chỉ tạo admin + role nghiệp vụ,
 * không tạo bất kỳ fixture nào.
 */
class Wwhn300ProductionSafeSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'H0aNam#GoLive!2026';

    public function test_seeder_refuses_to_run_on_a_production_environment_without_a_supplied_admin_password(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/WMS_SEED_ADMIN_PASSWORD/');

        $this->runAuthSeeder();
    }

    public function test_seeder_rejects_an_admin_password_that_is_a_known_default(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', 'Cntt@1234');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mặc định đã biết/u');

        $this->runAuthSeeder();
    }

    public function test_seeder_rejects_an_admin_password_below_the_minimum_length(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', 'Sh0rt!1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tối thiểu/u');

        $this->runAuthSeeder();
    }

    /**
     * Trạng thái đúng của production: admin dùng credential được cấp, và KHÔNG có
     * tài khoản/role fixture nào được tạo ra.
     */
    public function test_production_seeding_creates_only_the_supplied_admin_and_no_fixtures(): void
    {
        $this->clearFixtures();
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', self::STRONG_PASSWORD);

        $this->runAuthSeeder();

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, (string) $admin->password_hash));
        $this->assertFalse(Hash::check('Cntt@1234', (string) $admin->password_hash));

        foreach (['staff01', 'manager01', 'test-super-admin', 'test-no-permission'] as $username) {
            $this->assertDatabaseMissing('app_user', ['username' => $username]);
        }

        foreach (['SKU_MANAGER', 'MASTER_MANAGER', 'NO_PERMISSION'] as $roleCode) {
            $this->assertDatabaseMissing('role', ['role_code' => $roleCode]);
        }

        // Hai vai của mô hình phân quyền CŨ. Tài liệu BA taikhoantheorole v2 (nguyên
        // tắc 6 + retest §7): "WAREHOUSE_KEEPER và WAREHOUSE_APPROVER không còn sử
        // dụng trong mô hình phân quyền production; DEV cần loại khỏi seed/tài khoản
        // production". Chúng sinh ra để tách "người quét" khỏi "người Post" —
        // separation-of-duties bằng role trung gian mà nguyên tắc 5 đã bãi bỏ, nên để
        // lại ở production là mời người ta gán nhầm rồi đứt luồng nhập/xuất giữa chừng.
        // Hai vai vẫn còn trong AuthDatabaseSeeder::ROLES cho local/testing.
        foreach (['WAREHOUSE_KEEPER', 'WAREHOUSE_APPROVER'] as $roleCode) {
            $this->assertDatabaseMissing('role', ['role_code' => $roleCode]);
        }

        // Role nghiệp vụ thật vẫn phải có, nếu không production không vận hành được.
        foreach (['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE_MANAGER', 'WAREHOUSE_STAFF', 'WARRANTY_STAFF', 'VIEWER', 'LEADERSHIP'] as $roleCode) {
            $this->assertDatabaseHas('role', ['role_code' => $roleCode]);
        }
    }

    /**
     * Hai vai của mô hình phân quyền CŨ không được seed ở production.
     *
     * Tách khỏi test_production_seeding_creates_only_the_supplied_admin_and_no_fixtures
     * có chủ ý: test đó khẳng định thêm mệnh đề "admin dùng đúng credential được cấp",
     * và mệnh đề ấy hiện ĐỎ SẴN ở HEAD (AuthDatabaseSeeder::adminPassword() trả thẳng
     * hằng DEFAULT_PASSWORD, không còn cổng chặn nào của WWHN-300). Một assertion đỏ
     * nằm TRƯỚC sẽ ném ngoại lệ và phần kiểm role phía sau không bao giờ chạy — tức
     * mệnh đề của BA (retest §7) sẽ không thực sự được canh. Test riêng này chỉ phụ
     * thuộc vào nhánh môi trường nên xanh/đỏ độc lập với lỗ hổng password kia.
     *
     * Nguồn: tài liệu BA taikhoantheorole v2, nguyên tắc 6 + mục retest §7.
     */
    public function test_deprecated_warehouse_roles_are_not_seeded_on_production(): void
    {
        $this->clearFixtures();
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', self::STRONG_PASSWORD);

        Role::query()->whereIn('role_code', ['WAREHOUSE_KEEPER', 'WAREHOUSE_APPROVER'])->delete();

        $this->runAuthSeeder();

        // Nếu đỏ: kiểm tra AuthDatabaseSeeder::DEPRECATED_ROLES và nhánh $fixtureOnly
        // trong run(). Để hai vai này lại ở production là mời người ta gán nhầm rồi
        // đứt luồng nhập-xuất giữa chừng.
        $seeded = Role::query()->pluck('role_code')->all();

        $this->assertEqualsCanonicalizing(
            [],
            array_values(array_intersect($seeded, ['WAREHOUSE_KEEPER', 'WAREHOUSE_APPROVER'])),
            'Vai ngừng dùng vẫn được seed ở production.'
        );

        // Bảy vai của mô hình phân quyền mà BA nghiệm thu — thiếu vai nào thì
        // production không vận hành được đúng nghiệp vụ đó.
        foreach (['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE_MANAGER', 'WAREHOUSE_STAFF', 'WARRANTY_STAFF', 'VIEWER', 'LEADERSHIP'] as $roleCode) {
            $this->assertContains($roleCode, $seeded, "Thiếu vai {$roleCode} ở production.");
        }
    }

    /**
     * Lối thoát tường minh cho UAT/demo vẫn tạo fixture — nhưng đó là lựa chọn
     * được khai báo, và `security.account_hygiene` sẽ báo đỏ chúng.
     */
    public function test_demo_accounts_are_created_outside_development_only_when_explicitly_enabled(): void
    {
        $this->clearFixtures();
        $this->pretendProductionEnvironment();
        config()->set('golive.seed.admin_password', self::STRONG_PASSWORD);
        config()->set('golive.seed.demo_accounts', true);

        $this->runAuthSeeder();

        $this->assertDatabaseHas('app_user', ['username' => 'test-super-admin']);
    }

    /** Trên local/testing hành vi cũ giữ nguyên để fixture của bộ test không đổi. */
    public function test_development_seeding_keeps_the_existing_fixture_behaviour(): void
    {
        $this->clearFixtures();

        $this->runAuthSeeder();

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check('Cntt@1234', (string) $admin->password_hash));
        $this->assertDatabaseHas('app_user', ['username' => 'test-super-admin']);
        $this->assertDatabaseHas('role', ['role_code' => 'NO_PERMISSION']);
    }

    private function runAuthSeeder(): void
    {
        $seeder = new AuthDatabaseSeeder;
        $seeder->setContainer($this->app);
        $seeder->run();
    }

    private function pretendProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('golive.development_environments', ['local', 'testing']);
        config()->set('golive.seed.demo_accounts', false);
    }

    /**
     * Bộ test khác trong cùng tiến trình có thể đã seed sẵn, nên xóa fixture trước
     * để phép khẳng định "không được tạo ra" nói đúng điều nó định nói.
     */
    private function clearFixtures(): void
    {
        $usernames = ['staff01', 'approver01', 'keeper01', 'viewer01', 'manager01',
            'test-sku-manage', 'test-master-manage', 'test-no-permission', 'test-super-admin'];
        $roleCodes = ['SKU_MANAGER', 'MASTER_MANAGER', 'NO_PERMISSION'];

        $userIds = User::query()->whereIn('username', $usernames)->pluck('user_id')->all();
        $roleIds = Role::query()->whereIn('role_code', $roleCodes)->pluck('role_id')->all();

        DB::table('user_role')->whereIn('user_id', $userIds)->delete();
        DB::table('user_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('role_permission')->whereIn('role_id', $roleIds)->delete();

        User::query()->whereIn('user_id', $userIds)->forceDelete();
        Role::query()->whereIn('role_id', $roleIds)->delete();
    }
}
