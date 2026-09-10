<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\SeedEnvironment;

/**
 * BA tài khoản ĐĂNG NHẬP ĐƯỢC cho MỖI vai trò — yêu cầu người dùng 05/09/2026.
 *
 * Vì sao cần, dù đã có AuthDatabaseSeeder::USERS + IamCoverageSeeder:
 *  - Số lượng lệch hẳn nhau: WAREHOUSE_MANAGER có 3 tài khoản ACTIVE, còn
 *    NO_PERMISSION / INBOUND_ONLY_TEST chỉ có 1, MASTER_MANAGER có 2.
 *  - Một phần không đăng nhập được: IamCoverageSeeder cố ý đặt INACTIVE/LOCKED
 *    để ba bộ lọc của màn IAM-01 có dữ liệu (thukho03 LOCKED, sku02 INACTIVE…).
 *    Người kiểm thử phân quyền lại cần tài khoản MỞ để đăng nhập thật.
 *  - Tên đăng nhập không theo quy luật nào (staff01, nhanvienkho02,
 *    kinhdoanh01, test-sku-manage…) nên muốn thử một vai phải tra ngược seeder.
 *
 * Seeder này KHÔNG đụng tới tài khoản cũ (E2E và tài liệu bàn giao đang tham
 * chiếu chúng) mà bổ sung một bộ MỚI, đặt tên theo đúng mã vai trò:
 *   <role_code viết thường, gạch nối>-01 / -02 / -03
 * ví dụ `warehouse-staff-01`, `leadership-03`. Tất cả đều ACTIVE, cùng mật
 * khẩu fixture với các tài khoản demo còn lại.
 *
 * Idempotent: updateOrCreate theo username; chạy lại không nhân bản (mật khẩu
 * fixture được đặt lại theo WMS_FIXTURE_PASSWORD).
 *
 * AN TOÀN MÔI TRƯỜNG (P0 bảo mật 10/09/2026): seeder TỰ chặn khi bị gọi thẳng bằng
 * `db:seed --class=...` ngoài development/demo (SeedEnvironment), và bộ
 * `super-admin-01..03` CHỈ sinh ở local/testing — môi trường demo/UAT không có tài
 * khoản SUPER_ADMIN fixture nào. Mật khẩu không có giá trị mặc định trong source và
 * không được in ra log.
 */
class RoleAccountMatrixSeeder extends Seeder
{
    /** Số tài khoản cho mỗi vai trò. */
    private const PER_ROLE = 3;

    /**
     * Phòng ban mặc định + nhãn nghề nghiệp của từng vai trò, để cột "Phòng ban"
     * và "Họ tên" trên IAM-01 đọc ra nghĩa thay vì "Tài khoản vai trò X".
     *
     * @var array<string, array{0:string, 1:?string}> role_code => [nhãn, department_code]
     */
    private const ROLE_PROFILE = [
        'SUPER_ADMIN' => ['Quản trị hệ thống', 'HANH_CHINH'],
        'ADMIN' => ['Quản trị viên', 'HANH_CHINH'],
        'WAREHOUSE_MANAGER' => ['Quản lý kho', 'QUAN_LY_KHO'],
        'WAREHOUSE_STAFF' => ['Nhân viên kho', 'QUAN_LY_KHO'],
        'WARRANTY_STAFF' => ['Nhân viên bảo hành', 'BAO_HANH'],
        'LEADERSHIP' => ['Ban lãnh đạo', 'HANH_CHINH'],
        'VIEWER' => ['Người xem', 'KINH_DOANH'],
        'SKU_MANAGER' => ['Quản lý SKU (test)', 'KY_THUAT'],
        'MASTER_MANAGER' => ['Quản lý dữ liệu gốc (test)', 'KY_THUAT'],
        'NO_PERMISSION' => ['Không có quyền (test)', null],
        'INBOUND_ONLY_TEST' => ['Chỉ nhập kho (test)', null],
    ];

    public function run(): void
    {
        SeedEnvironment::assertFixtureAccountsAllowed(static::class);

        $departmentIds = Department::query()->pluck('department_id', 'department_code')->all();
        $password = Hash::make(SeedEnvironment::fixturePassword());
        $today = Carbon::today();

        // Đi theo vai trò CÓ THẬT trong CSDL: production không seed 4 vai trò
        // test, và AuthDatabaseSeeder có thể bỏ qua chúng — không giả định.
        //
        // Trừ hai vai ngừng dùng: bảng tài khoản mà BA nghiệm thu
        // (taikhoantheorole v2) chỉ liệt kê 11 vai trò, không có WAREHOUSE_KEEPER/
        // WAREHOUSE_APPROVER. Sinh thêm warehouse-keeper-01..03 sẽ mời người kiểm
        // thử đăng nhập vào đúng mô hình phân quyền vừa bị bãi bỏ. Tài khoản CŨ
        // (keeper01, approver01 từ AuthDatabaseSeeder::USERS) vẫn giữ nguyên cho bộ
        // E2E và tài liệu bàn giao cũ.
        $excludedRoles = (array) (new \ReflectionClass(AuthDatabaseSeeder::class))->getConstant('DEPRECATED_ROLES');

        // SUPER_ADMIN fixture (`super-admin-01..03`) chỉ ở local/testing: trên môi trường
        // demo/UAT quản trị viên đầu tiên đi qua `wms:bootstrap-super-admin`.
        if (! SeedEnvironment::allowsSuperAdminFixtures()) {
            $excludedRoles[] = 'SUPER_ADMIN';
        }

        $roles = Role::query()
            ->whereNotIn('role_code', $excludedRoles)
            ->orderBy('role_code')
            ->get();
        $created = 0;
        $rows = [];

        foreach ($roles as $role) {
            $code = (string) $role->role_code;
            [$label, $departmentCode] = self::ROLE_PROFILE[$code] ?? [$role->role_name ?? $code, null];
            $slug = strtolower(str_replace('_', '-', $code));

            for ($i = 1; $i <= self::PER_ROLE; $i++) {
                $username = $slug.'-'.sprintf('%02d', $i);
                $salt = $created + $i;
                $user = User::updateOrCreate(
                    ['username' => $username],
                    [
                        'display_name' => $label.' '.sprintf('%02d', $i),
                        'email' => $username.'@gmail.com',
                        'phone' => '09'.str_pad((string) (($salt * 7_919_137) % 90_000_000 + 10_000_000), 8, '0', STR_PAD_LEFT),
                        'password_hash' => $password,
                        'status' => 'ACTIVE',
                        // BẮT BUỘC false: mặc định NULL/true sẽ đẩy người đăng nhập
                        // sang màn "đổi mật khẩu lần đầu", đúng thứ làm hỏng mục
                        // đích của bộ tài khoản này (đăng nhập nhanh để soát quyền).
                        'must_change_password' => false,
                        'last_login_at' => $today->copy()->subDays($salt % 21)->setTime(8, ($salt * 13) % 60),
                        'version' => 1,
                        'department_id' => $departmentCode === null
                            ? null
                            : ($departmentIds[$departmentCode] ?? null),
                    ]
                );
                if ($user->wasRecentlyCreated) {
                    $created++;
                }

                $this->attachRole($user, $role);
            }

            $rows[] = $code.' → '.$slug.'-01/02/03';
        }

        $this->command?->info(
            'RoleAccountMatrixSeeder: '.self::PER_ROLE.' tài khoản ACTIVE cho mỗi vai trò ('
            .$roles->count().' vai trò, '.$created.' tài khoản mới). Mật khẩu: giá trị WMS_FIXTURE_PASSWORD (không in ra log).'
        );
        foreach ($rows as $row) {
            $this->command?->line('    '.$row);
        }
    }

    private function attachRole(User $user, Role $role): void
    {
        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }
    }
}
