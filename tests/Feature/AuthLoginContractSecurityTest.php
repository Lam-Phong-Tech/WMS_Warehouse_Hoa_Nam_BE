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
            // WMS_SEED_ADMIN_PASSWORD/known_defaults làm UAT không đăng nhập được.
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

    public function test_golive_policy_accepts_the_canonical_admin_password(): void
    {
        $password = (string) config('golive.seed.admin_password');
        $minimumLength = (int) config('golive.seed.min_admin_password_length');

        $this->assertSame('Cntt@1234', $password);
        $this->assertNotContains($password, (array) config('golive.credential.known_defaults', []));
        $this->assertGreaterThanOrEqual($minimumLength, mb_strlen($password));
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
