<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * `wms:migrate-deprecated-roles` — chuyển tài khoản khỏi WAREHOUSE_KEEPER /
 * WAREHOUSE_APPROVER (tài liệu BA taikhoantheorole v2, nguyên tắc 6; BA duyệt 07/09/2026).
 *
 * Lệnh này đổi vai của người thật trên môi trường đã triển khai, nên hai mệnh đề phải
 * có cổng canh: (a) không có --apply thì TUYỆT ĐỐI không ghi gì — người vận hành sẽ
 * chạy thử trước và phải tin được kết quả xem trước; (b) chạy lại không nhân bản dòng
 * user_role, vì một đợt cutover hay bị chạy hai lần (lần đầu đứt mạng, lần sau chạy lại).
 */
class MigrateDeprecatedWarehouseRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
    }

    public function test_dry_run_lists_candidates_without_touching_the_database(): void
    {
        $user = $this->userWithRole('nv-kho-cu', 'WAREHOUSE_KEEPER');

        $this->artisan('wms:migrate-deprecated-roles')
            ->expectsOutputToContain('nv-kho-cu')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertTrue(
            $user->fresh()->roles()->where('role_code', 'WAREHOUSE_KEEPER')->exists(),
            'Dry-run đã đổi vai — người vận hành sẽ không còn tin được bảng xem trước.'
        );
    }

    public function test_apply_moves_the_account_and_is_safe_to_run_twice(): void
    {
        $user = $this->userWithRole('nv-kho-cu', 'WAREHOUSE_APPROVER');

        $this->artisan('wms:migrate-deprecated-roles', ['--apply' => true])->assertExitCode(0);

        $roles = $user->fresh()->roles()->pluck('role_code')->all();
        $this->assertContains('WAREHOUSE_STAFF', $roles);
        $this->assertNotContains('WAREHOUSE_APPROVER', $roles);

        // Lần hai: không còn ứng viên, và không sinh thêm dòng user_role nào.
        $before = DB::table('user_role')->where('user_id', $user->user_id)->count();
        $this->artisan('wms:migrate-deprecated-roles', ['--apply' => true])->assertExitCode(0);
        $this->assertSame($before, DB::table('user_role')->where('user_id', $user->user_id)->count());
    }

    public function test_target_role_can_be_the_supervision_role_for_named_accounts_only(): void
    {
        $supervisor = $this->userWithRole('ky-duyet-tren-giay', 'WAREHOUSE_APPROVER');
        $operator = $this->userWithRole('dung-o-bai-hang', 'WAREHOUSE_KEEPER');

        $this->artisan('wms:migrate-deprecated-roles', [
            '--apply' => true,
            '--to' => 'WAREHOUSE_MANAGER',
            '--only' => 'ky-duyet-tren-giay',
        ])->assertExitCode(0);

        $this->assertContains('WAREHOUSE_MANAGER', $supervisor->fresh()->roles()->pluck('role_code')->all());
        $this->assertContains(
            'WAREHOUSE_KEEPER',
            $operator->fresh()->roles()->pluck('role_code')->all(),
            '--only phải giới hạn đúng tài khoản được nêu tên, không quét cả bảng.'
        );
    }

    public function test_an_unknown_target_role_is_rejected_before_anything_is_written(): void
    {
        $user = $this->userWithRole('nv-kho-cu', 'WAREHOUSE_KEEPER');

        $this->artisan('wms:migrate-deprecated-roles', ['--apply' => true, '--to' => 'ADMIN'])
            ->assertExitCode(1);

        $this->assertContains('WAREHOUSE_KEEPER', $user->fresh()->roles()->pluck('role_code')->all());
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $role = Role::query()->where('role_code', $roleCode)->firstOrFail();
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user;
    }
}
