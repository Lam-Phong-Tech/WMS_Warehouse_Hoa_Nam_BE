<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Tests\TestCase;

class AuthLoginContractSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_admin_login_works_on_uat_seed_path(): void
    {
        $originalEnvironment = $this->app['env'];

        try {
            // Đi đúng nhánh logic ngoài local/testing để chặn regression kiểu
            // environment-specific password làm UAT không đăng nhập được.
            $this->app['env'] = 'uat';
            $this->seed(AuthDatabaseSeeder::class);

            $admin = User::query()->where('username', 'admin')->firstOrFail();
            $this->assertSame('admin@gmail.com', $admin->email);
            $this->assertSame('ACTIVE', $admin->status);
            $this->assertTrue(Hash::check('Cntt@1234', $admin->password_hash));

            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@gmail.com',
                'password' => 'Cntt@1234',
            ])->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.user.username', 'admin')
                ->assertJsonPath('data.user.email', 'admin@gmail.com');
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_golive_policy_does_not_reject_the_canonical_admin_password(): void
    {
        $this->assertNotContains('Cntt@1234', (array) config('golive.credential.known_defaults', []));
        $this->assertNull(config('golive.seed.admin_password'));
        $this->assertNull(config('golive.seed.min_admin_password_length'));
    }

    public function test_admin_seeder_has_one_environment_independent_password_source(): void
    {
        $source = file_get_contents(base_path('Modules/Auth/database/seeders/AuthDatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("private const DEFAULT_PASSWORD = 'Cntt@1234';", $source);
        $this->assertStringContainsString('return self::DEFAULT_PASSWORD;', $source);
        $this->assertStringNotContainsString("config('golive.seed.admin_password')", $source);
        $this->assertStringNotContainsString('throw new RuntimeException', $source);
    }

    public function test_login_openapi_exposes_the_canonical_admin_credential(): void
    {
        $source = file_get_contents(base_path('Modules/Auth/app/Http/Controllers/AuthController.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("example: 'admin@gmail.com'", $source);
        $this->assertStringContainsString("example: 'Cntt@1234'", $source);
        $this->assertStringNotContainsString('UAT/production không có mật khẩu mặc định', $source);
    }
}
