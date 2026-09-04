<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

/**
 * Phủ kín màn "Danh sách người dùng & phân quyền" (IAM-01).
 *
 * Sau AuthDatabaseSeeder, danh sách chỉ có 11 tài khoản — TẤT CẢ đều ACTIVE, 3
 * vai trò không có người nào (ADMIN / LEADERSHIP / WARRANTY_STAFF), quá nửa
 * không có phòng ban và không ai có số điện thoại / lần đăng nhập gần nhất. Ba
 * bộ lọc của màn (vai trò, phòng ban, trạng thái) vì thế trả rỗng ở phần lớn
 * giá trị, và hai cột "Điện thoại"/"Đăng nhập gần nhất" luôn hiện "Chưa có".
 *
 * Seeder này bổ sung nhân sự để MỌI giá trị của cả ba bộ lọc đều có dữ liệu:
 *  - Mỗi vai trò nghiệp vụ có ≥ 2 tài khoản (kể cả SUPER_ADMIN/ADMIN).
 *  - Mỗi phòng ban (5 phòng, xem DepartmentSeeder + phần bổ sung dưới) có người.
 *  - Cả 3 trạng thái ACTIVE / INACTIVE / LOCKED đều xuất hiện, và xuất hiện ở
 *    nhiều phòng ban khác nhau để lọc chéo (vai trò × phòng ban × trạng thái)
 *    vẫn ra kết quả.
 *
 * Đồng thời BACKFILL phone + last_login_at cho các tài khoản gốc để không cột
 * nào trong bảng bị rỗng. Không đụng `status` của tài khoản gốc (admin,
 * keeper01... là tài khoản đăng nhập thật của demo/E2E — khoá chúng sẽ làm hỏng
 * mọi luồng khác).
 *
 * Idempotent: updateOrCreate theo username; chạy lại không nhân bản.
 */
class IamCoverageSeeder extends Seeder
{
    /** Phòng ban bổ sung ngoài 3 phòng của DepartmentSeeder. */
    private const EXTRA_DEPARTMENTS = [
        [
            'code' => 'KY_THUAT',
            'name' => 'Phòng kỹ thuật',
            'description' => 'Kỹ thuật viên chẩn đoán, sửa chữa máy và hỗ trợ kỹ thuật hiện trường.',
        ],
        [
            'code' => 'HANH_CHINH',
            'name' => 'Phòng hành chính - nhân sự',
            'description' => 'Quản trị hệ thống, nhân sự, tài khoản và phân quyền nội bộ.',
        ],
    ];

    /**
     * Nhân sự bổ sung: [username, họ tên, vai trò, phòng ban, trạng thái, ngày
     * đăng nhập gần nhất tính lùi từ hôm nay (null = chưa từng đăng nhập)].
     *
     * @var list<array{0:string,1:string,2:string,3:?string,4:string,5:?int}>
     */
    private const USERS = [
        // ── Ban lãnh đạo (LEADERSHIP) — trước đây 0 tài khoản ────────────────
        ['giamdoc.hoa', 'Nguyễn Thị Hoa — Giám đốc', 'LEADERSHIP', 'HANH_CHINH', 'ACTIVE', 1],
        ['phogiamdoc.tuan', 'Trần Anh Tuấn — Phó giám đốc', 'LEADERSHIP', 'HANH_CHINH', 'ACTIVE', 4],
        ['truongvung.nam', 'Lê Hoài Nam — Giám đốc vùng Nam', 'LEADERSHIP', 'KINH_DOANH', 'INACTIVE', 62],

        // ── Quản trị (ADMIN) — trước đây 0 tài khoản ─────────────────────────
        ['admin.hethong', 'Phạm Quốc Bảo — Quản trị hệ thống', 'ADMIN', 'HANH_CHINH', 'ACTIVE', 0],
        ['admin.phanquyen', 'Vũ Thị Ngọc — Quản trị phân quyền', 'ADMIN', 'HANH_CHINH', 'ACTIVE', 6],
        ['admin.cu', 'Đỗ Văn Cường — Quản trị (đã nghỉ)', 'ADMIN', 'HANH_CHINH', 'INACTIVE', 145],

        // ── Bảo hành (WARRANTY_STAFF) — trước đây 0 tài khoản ────────────────
        ['baohanh01', 'Hoàng Minh Trí — CV bảo hành', 'WARRANTY_STAFF', 'BAO_HANH', 'ACTIVE', 0],
        ['baohanh02', 'Bùi Thị Lan — CV bảo hành', 'WARRANTY_STAFF', 'BAO_HANH', 'ACTIVE', 2],
        ['baohanh03', 'Ngô Hữu Phước — CV bảo hành', 'WARRANTY_STAFF', 'BAO_HANH', 'LOCKED', 21],
        ['kythuat.baohanh', 'Dương Văn Khoa — KTV bảo hành', 'WARRANTY_STAFF', 'KY_THUAT', 'ACTIVE', 1],
        ['kythuat.hientruong', 'Lý Thành Đạt — KTV hiện trường', 'WARRANTY_STAFF', 'KY_THUAT', 'INACTIVE', 88],

        // ── Vận hành kho ─────────────────────────────────────────────────────
        ['thukho02', 'Trịnh Văn Sơn — Thủ kho ca 2', 'WAREHOUSE_KEEPER', 'QUAN_LY_KHO', 'ACTIVE', 0],
        ['thukho03', 'Phan Thị Kim — Thủ kho chi nhánh', 'WAREHOUSE_KEEPER', 'QUAN_LY_KHO', 'LOCKED', 33],
        ['nhanvienkho02', 'Võ Thị Cúc — Nhân viên kho', 'WAREHOUSE_STAFF', 'QUAN_LY_KHO', 'ACTIVE', 1],
        ['nhanvienkho03', 'Đặng Hoài An — Nhân viên kho', 'WAREHOUSE_STAFF', 'QUAN_LY_KHO', 'ACTIVE', 3],
        ['nhanvienkho04', 'Mai Văn Định — Nhân viên kho (thời vụ)', 'WAREHOUSE_STAFF', 'QUAN_LY_KHO', 'INACTIVE', 97],
        ['duyetkho02', 'Nguyễn Hữu Lâm — Trưởng ca duyệt phiếu', 'WAREHOUSE_APPROVER', 'QUAN_LY_KHO', 'ACTIVE', 2],
        ['duyetkho03', 'Trần Thị Hường — Duyệt phiếu ca đêm', 'WAREHOUSE_APPROVER', 'QUAN_LY_KHO', 'LOCKED', 12],
        ['quanlykho02', 'Lê Quang Huy — Quản lý kho chi nhánh', 'WAREHOUSE_MANAGER', 'QUAN_LY_KHO', 'ACTIVE', 0],
        ['quanlykho03', 'Nguyễn Thị Thu — Quản lý kho vùng', 'WAREHOUSE_MANAGER', 'KINH_DOANH', 'ACTIVE', 5],

        // ── Kinh doanh / tra cứu ─────────────────────────────────────────────
        ['kinhdoanh01', 'Hồ Minh Quân — CV kinh doanh', 'VIEWER', 'KINH_DOANH', 'ACTIVE', 1],
        ['kinhdoanh02', 'Cao Thị Bích — CV kinh doanh', 'VIEWER', 'KINH_DOANH', 'ACTIVE', 8],
        ['kinhdoanh03', 'Đinh Văn Lộc — CV kinh doanh (tạm khoá)', 'VIEWER', 'KINH_DOANH', 'LOCKED', 40],
        ['ketoan.kho', 'Trương Thị Nhung — Kế toán kho', 'VIEWER', 'HANH_CHINH', 'ACTIVE', 2],

        // ── Dữ liệu gốc ──────────────────────────────────────────────────────
        ['danhmuc01', 'Nguyễn Văn Hùng — Phụ trách danh mục', 'MASTER_MANAGER', 'KY_THUAT', 'ACTIVE', 3],
        ['sku01', 'Phạm Thị Mai — Phụ trách SKU', 'SKU_MANAGER', 'KY_THUAT', 'ACTIVE', 1],
        ['sku02', 'Lâm Chí Vỹ — Phụ trách SKU (nghỉ thai sản)', 'SKU_MANAGER', 'KY_THUAT', 'INACTIVE', 70],
    ];

    /**
     * Backfill cho tài khoản gốc: [username, số ngày lùi của last_login_at].
     * KHÔNG đổi status/vai trò của nhóm này.
     *
     * @var array<string,int>
     */
    private const BASE_USER_LAST_LOGIN = [
        'admin' => 0,
        'keeper01' => 0,
        'staff01' => 1,
        'approver01' => 1,
        'manager01' => 2,
        'viewer01' => 4,
        'test-super-admin' => 9,
        'test-sku-manage' => 11,
        'test-master-manage' => 13,
        'test-no-permission' => 18,
        'inbound-tester01' => 7,
    ];

    public function run(): void
    {
        $this->ensureDepartments();

        $departmentIds = Department::query()->pluck('department_id', 'department_code')->all();
        $roles = Role::query()->get()->keyBy('role_code');
        $password = Hash::make($this->fixturePassword());
        $today = Carbon::today();

        $created = 0;
        foreach (self::USERS as [$username, $displayName, $roleCode, $departmentCode, $status, $lastLoginDaysAgo]) {
            $role = $roles->get($roleCode);
            if ($role === null) {
                // Vai trò fixture bị tắt ở môi trường này (NON_PRODUCTION_ROLES).
                continue;
            }

            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'display_name' => $displayName,
                    'email' => $username.'@gmail.com',
                    'phone' => self::phoneFor($username),
                    'password_hash' => $password,
                    'status' => $status,
                    'department_id' => $departmentCode !== null ? ($departmentIds[$departmentCode] ?? null) : null,
                    'last_login_at' => $lastLoginDaysAgo === null
                        ? null
                        : $today->copy()->subDays($lastLoginDaysAgo)->setTime(8 + ($created % 9), ($created % 6) * 10),
                    'must_change_password' => false,
                    'version' => 1,
                ]
            );

            $this->attachRole($user, $role);
            $created++;
        }

        $this->backfillBaseUsers($today);

        $this->command?->info(
            'IamCoverageSeeder: đã bổ sung '.$created.' tài khoản phủ đủ vai trò/phòng ban/trạng thái cho màn IAM-01.'
        );
    }

    private function ensureDepartments(): void
    {
        foreach (self::EXTRA_DEPARTMENTS as $department) {
            Department::updateOrCreate(
                ['department_code' => $department['code']],
                [
                    'department_name' => $department['name'],
                    'status' => 'ACTIVE',
                    'description' => $department['description'],
                ]
            );
        }
    }

    /** Điền số điện thoại + lần đăng nhập gần nhất cho tài khoản gốc (2 cột này vốn rỗng). */
    private function backfillBaseUsers(Carbon $today): void
    {
        $departmentIds = Department::query()->pluck('department_id', 'department_code')->all();

        // viewer01 / test-* trước đây không có phòng ban nên bộ lọc "Phòng ban"
        // bỏ sót hẳn nhóm này; gán phòng phù hợp với vai trò thay vì để trống.
        $departmentByUsername = [
            'viewer01' => 'KINH_DOANH',
            'test-sku-manage' => 'KY_THUAT',
            'test-master-manage' => 'KY_THUAT',
            'test-super-admin' => 'HANH_CHINH',
            'test-no-permission' => 'HANH_CHINH',
            'inbound-tester01' => 'QUAN_LY_KHO',
            'admin' => 'HANH_CHINH',
        ];

        foreach (self::BASE_USER_LAST_LOGIN as $username => $daysAgo) {
            $user = User::query()->where('username', $username)->first();
            if ($user === null) {
                continue;
            }

            $attributes = [
                'last_login_at' => $today->copy()->subDays($daysAgo)->setTime(7 + ($daysAgo % 10), 25),
            ];
            if (blank($user->phone)) {
                $attributes['phone'] = self::phoneFor($username);
            }
            if ($user->department_id === null && isset($departmentByUsername[$username])) {
                $attributes['department_id'] = $departmentIds[$departmentByUsername[$username]] ?? null;
            }

            $user->forceFill($attributes)->save();
        }
    }

    /** Số di động VN 10 chữ số, ổn định theo username (chạy lại không đổi số). */
    private static function phoneFor(string $username): string
    {
        $prefixes = ['090', '091', '093', '094', '096', '097', '098', '032', '035', '038'];
        $hash = crc32($username);
        $prefix = $prefixes[$hash % count($prefixes)];

        return $prefix.substr(str_pad((string) ($hash % 10000000), 7, '0', STR_PAD_LEFT), 0, 7);
    }

    private function attachRole(User $user, Role $role): void
    {
        if ($user->roles()->where('role.role_id', $role->role_id)->exists()) {
            return;
        }

        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
    }

    /** Cùng mật khẩu fixture với AuthDatabaseSeeder để đăng nhập demo đồng nhất. */
    private function fixturePassword(): string
    {
        $ciPassword = env('CI_DEMO_PASSWORD');

        return is_string($ciPassword) && $ciPassword !== '' ? $ciPassword : 'Cntt@1234';
    }
}
