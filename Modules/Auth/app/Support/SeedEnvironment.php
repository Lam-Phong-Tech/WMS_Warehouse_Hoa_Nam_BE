<?php

namespace Modules\Auth\Support;

use RuntimeException;

/**
 * P0 bảo mật 10/09/2026 — MỘT nguồn duy nhất quyết định seeder được đụng tới tài
 * khoản đăng nhập nào.
 *
 * VÌ SAO: AuthDatabaseSeeder từng `updateOrCreate` tài khoản SUPER_ADMIN `admin` với
 * mật khẩu hằng số nằm trong source, và đặt đoạn đó TRƯỚC nhánh chặn fixture. Mỗi lần
 * `php artisan db:seed` trên production vì thế là một lần reset mật khẩu `admin` về
 * giá trị ai đọc repo cũng biết, bật lại ACTIVE nếu đang bị khoá và gắn lại
 * SUPER_ADMIN. Ba seeder fixture khác (IamCoverageSeeder, RoleAccountMatrixSeeder,
 * UatScenarioSeeder) gọi thẳng được bằng `db:seed --class=...` mà không kiểm môi
 * trường nào — RoleAccountMatrixSeeder còn sinh cả `super-admin-01..03`.
 *
 * BA CHẾ ĐỘ:
 *  - development — APP_ENV nằm trong golive.development_environments (local, testing):
 *    đủ bộ fixture, kể cả tài khoản SUPER_ADMIN fixture, để bộ test RBAC chạy được.
 *  - demo — APP_ENV nằm trong golive.demo_environments (uat, demo) VÀ bật tường minh
 *    WMS_SEED_DEMO_ACCOUNTS=true: fixture nghiệp vụ cho người kiểm thử, nhưng KHÔNG có
 *    tài khoản SUPER_ADMIN nào — quản trị viên đầu tiên chỉ sinh ra qua lệnh
 *    `wms:bootstrap-super-admin`.
 *  - production — MỌI trường hợp còn lại, kể cả APP_ENV gõ sai và cờ demo bật trên
 *    môi trường không thuộc demo_environments: seeder không tạo, không sửa tài khoản
 *    nào; chỉ seed danh mục quyền, vai trò và mapping.
 *
 * Mật khẩu fixture KHÔNG có giá trị mặc định trong source: đọc từ
 * `golive.seed.fixture_password` (WMS_FIXTURE_PASSWORD, hoặc CI_DEMO_PASSWORD cũ).
 * Thiếu thì seeder dừng — thà không có tài khoản demo còn hơn có tài khoản demo mang
 * mật khẩu công khai.
 */
final class SeedEnvironment
{
    public const DEVELOPMENT = 'development';

    public const DEMO = 'demo';

    public const PRODUCTION = 'production';

    public static function mode(): string
    {
        $environment = (string) app()->environment();

        if (in_array($environment, self::environments('golive.development_environments', ['local', 'testing']), true)) {
            return self::DEVELOPMENT;
        }

        if ((bool) config('golive.seed.demo_accounts', false)
            && in_array($environment, self::environments('golive.demo_environments', ['uat', 'demo']), true)) {
            return self::DEMO;
        }

        return self::PRODUCTION;
    }

    /** Có được tạo/cập nhật tài khoản fixture (demo/test) hay không. */
    public static function allowsFixtureAccounts(): bool
    {
        return self::mode() !== self::PRODUCTION;
    }

    /** Tài khoản fixture mang vai trò SUPER_ADMIN: CHỈ ở development. */
    public static function allowsSuperAdminFixtures(): bool
    {
        return self::mode() === self::DEVELOPMENT;
    }

    /**
     * Chặn cứng cho seeder fixture khi bị gọi trực tiếp (`db:seed --class=...`).
     *
     * @throws RuntimeException
     */
    public static function assertFixtureAccountsAllowed(string $seeder): void
    {
        if (self::allowsFixtureAccounts()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s từ chối chạy: môi trường "%s" không phải development (golive.development_environments) '
            .'và cũng không phải demo được bật tường minh (golive.demo_environments + WMS_SEED_DEMO_ACCOUNTS=true). '
            .'Tài khoản thật tạo qua IAM; quản trị viên đầu tiên tạo bằng `php artisan wms:bootstrap-super-admin`.',
            class_basename($seeder),
            (string) app()->environment(),
        ));
    }

    /**
     * Mật khẩu dùng chung cho tài khoản fixture. Không có giá trị mặc định.
     *
     * @throws RuntimeException
     */
    public static function fixturePassword(): string
    {
        $password = (string) (config('golive.seed.fixture_password') ?? '');

        if ($password === '') {
            throw new RuntimeException(
                'Thiếu mật khẩu tài khoản fixture: đặt WMS_FIXTURE_PASSWORD trong .env (local) hoặc secret store (UAT/demo). '
                .'Seeder không còn mật khẩu mặc định trong source.'
            );
        }

        if (self::mode() === self::DEMO) {
            $problems = CredentialPolicy::problems($password, (int) config('golive.seed.min_demo_password_length', 12));

            if ($problems !== []) {
                throw new RuntimeException(
                    'WMS_FIXTURE_PASSWORD không đạt chính sách cho môi trường demo/UAT (truy cập được từ Internet): '
                    .implode('; ', $problems).'.'
                );
            }
        }

        return $password;
    }

    /**
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    private static function environments(string $key, array $default): array
    {
        return array_map('strval', (array) config($key, $default));
    }
}
