<?php

namespace Modules\Warehouse\Console;

use Illuminate\Console\Command;
use Modules\Auth\Support\CredentialPolicy;
use Modules\Auth\Support\SeedEnvironment;
use Modules\Warehouse\Database\Seeders\UatScenarioSeeder;

class SeedUatData extends Command
{
    protected $signature = 'wms:seed-uat {--password= : Password assigned to UAT accounts}';

    protected $description = 'Seed idempotent UAT accounts, role assignments, and an import traceability scenario.';

    public function handle(): int
    {
        // P0 bảo mật 10/09/2026: lệnh này tạo tài khoản ACTIVE dùng chung một mật khẩu, nên
        // chỉ chạy ở local/testing hoặc demo/UAT được bật tường minh — không bao giờ ở
        // production (kể cả APP_ENV gõ sai).
        if (! SeedEnvironment::allowsFixtureAccounts()) {
            $this->error('wms:seed-uat từ chối chạy trên môi trường "'.app()->environment().'": chỉ dành cho local/testing hoặc demo/UAT đã bật WMS_SEED_DEMO_ACCOUNTS=true (golive.demo_environments).');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: config('warehouse.uat_seed_password'));
        if ($password === '') {
            $this->error('Missing UAT password. Use --password or set UAT_SEED_PASSWORD.');

            return self::FAILURE;
        }

        if (SeedEnvironment::mode() === SeedEnvironment::DEMO) {
            $problems = CredentialPolicy::problems($password, (int) config('golive.seed.min_demo_password_length', 12));

            if ($problems !== []) {
                $this->error('Mật khẩu UAT không đạt chính sách cho môi trường demo/UAT: '.implode('; ', $problems).'.');

                return self::FAILURE;
            }
        }

        config(['warehouse.uat_seed_password' => $password]);
        $this->call('db:seed');
        $this->call('db:seed', ['--class' => UatScenarioSeeder::class]);
        $this->info('UAT seed complete: 4 accounts and 1 import traceability scenario are ready.');

        return self::SUCCESS;
    }
}
