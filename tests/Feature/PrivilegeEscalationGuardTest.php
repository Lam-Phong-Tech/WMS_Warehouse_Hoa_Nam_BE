<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * Hồi quy cho 3 đường leo thang đặc quyền phát hiện trong audit phân quyền 27/08.
 *
 * Điểm chung của cả ba: chúng đều nằm TRONG phạm vi quyền hợp lệ của một quản trị
 * viên cấp dưới (`iam.user.manage` / `iam.role.manage`), nên không middleware
 * `permission:` nào chặn được. `PreventSelfRoleAssignment` (SCR-IAM-01) chỉ bịt
 * đúng một đường trong ba.
 */
class PrivilegeEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    private const IAM_PERMISSIONS = [
        'iam.user.view', 'iam.user.manage', 'iam.role.view', 'iam.role.manage', 'iam.permission.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── SCR-IAM-04: cấp role SUPER_ADMIN cho tài khoản khác ────────────────

    public function test_quan_tri_thuong_khong_cap_duoc_role_super_admin_cho_tai_khoan_khac(): void
    {
        $superAdminRole = $this->role('SUPER_ADMIN');
        $actor = $this->userWithPermissions('manager', self::IAM_PERMISSIONS);
        $victim = $this->plainUser('nguoi-moi');

        $response = $this->postJson("/api/v1/users/{$victim->user_id}/roles", [
            'role_ids' => [$superAdminRole->role_id],
            'reason' => 'Thử leo thang đặc quyền qua tài khoản trung gian',
        ], $this->headersFor($actor));

        $response->assertStatus(403)->assertJsonPath('error.code', 'SUPER_ADMIN_GRANT_DENIED');
        $this->assertFalse($victim->fresh()->isSuperAdmin());
    }

    public function test_chan_ca_dang_payload_roles_co_scope(): void
    {
        $superAdminRole = $this->role('SUPER_ADMIN');
        $actor = $this->userWithPermissions('manager2', self::IAM_PERMISSIONS);
        $victim = $this->plainUser('nguoi-moi-2');

        $response = $this->postJson("/api/v1/users/{$victim->user_id}/roles", [
            'roles' => [['role_id' => $superAdminRole->role_id]],
            'reason' => 'Thử leo thang bằng payload roles[] thay vì role_ids[]',
        ], $this->headersFor($actor));

        $response->assertStatus(403)->assertJsonPath('error.code', 'SUPER_ADMIN_GRANT_DENIED');
    }

    public function test_super_admin_van_cap_duoc_role_super_admin(): void
    {
        $superAdminRole = $this->role('SUPER_ADMIN');
        $actor = $this->superAdmin('root');
        $target = $this->plainUser('nguoi-ke-nhiem');

        $this->postJson("/api/v1/users/{$target->user_id}/roles", [
            'role_ids' => [$superAdminRole->role_id],
            'reason' => 'Bổ nhiệm quản trị hệ thống kế nhiệm',
        ], $this->headersFor($actor))->assertOk();

        $this->assertTrue($target->fresh()->isSuperAdmin());
    }

    // ── SCR-IAM-03: sửa thẳng tài khoản super admin ────────────────────────

    public function test_quan_tri_thuong_khong_doi_duoc_mat_khau_cua_super_admin(): void
    {
        $actor = $this->userWithPermissions('manager3', self::IAM_PERMISSIONS);
        $root = $this->superAdmin('root2');
        $hashTruoc = $root->password_hash;

        $response = $this->patchJson("/api/v1/users/{$root->user_id}", [
            'password' => 'ChiemQuyen@123',
        ], $this->headersFor($actor));

        $response->assertStatus(403)->assertJsonPath('error.code', 'SUPER_ADMIN_TARGET_PROTECTED');
        $this->assertSame($hashTruoc, $root->fresh()->password_hash);
    }

    public function test_quan_tri_thuong_khong_khoa_duoc_tai_khoan_super_admin(): void
    {
        $actor = $this->userWithPermissions('manager4', self::IAM_PERMISSIONS);
        $root = $this->superAdmin('root3');

        $this->patchJson("/api/v1/users/{$root->user_id}", [
            'status' => 'LOCKED',
        ], $this->headersFor($actor))->assertStatus(403);

        $this->assertSame('ACTIVE', $root->fresh()->status);
    }

    public function test_van_sua_duoc_tai_khoan_thuong(): void
    {
        $actor = $this->userWithPermissions('manager5', self::IAM_PERMISSIONS);
        $target = $this->plainUser('nhan-vien');

        $this->patchJson("/api/v1/users/{$target->user_id}", [
            'display_name' => 'Nhân viên đã đổi tên',
        ], $this->headersFor($actor))->assertOk();
    }

    // ── SCR-IAM-02: tự nâng quyền qua ma trận role ─────────────────────────

    public function test_khong_gan_duoc_cho_role_quyen_ma_chinh_minh_khong_co(): void
    {
        $actor = $this->userWithPermissions('manager6', self::IAM_PERMISSIONS);
        $target = $this->role('LOW_ROLE');
        $vuotTam = $this->permission('warranty.pii.export');

        $response = $this->postJson("/api/v1/roles/{$target->role_id}/permissions", [
            'permission_ids' => [$vuotTam->permission_id],
            'reason' => 'Thử tự nâng quyền qua ma trận IAM',
        ], $this->headersFor($actor));

        $response->assertStatus(403)->assertJsonPath('error.code', 'PERMISSION_DELEGATION_DENIED');
        $this->assertSame(0, $target->permissions()->count());
    }

    public function test_van_gan_duoc_quyen_nam_trong_pham_vi_cua_minh(): void
    {
        $actor = $this->userWithPermissions('manager7', self::IAM_PERMISSIONS);
        $target = $this->role('LOW_ROLE_2');
        $trongTam = Permission::query()->where('permission_key', 'iam.user.view')->firstOrFail();

        $this->postJson("/api/v1/roles/{$target->role_id}/permissions", [
            'permission_ids' => [$trongTam->permission_id],
            'reason' => 'Uỷ quyền xem danh sách người dùng cho tổ trưởng',
        ], $this->headersFor($actor))->assertOk();

        $this->assertSame(1, $target->permissions()->count());
    }

    /**
     * Chỉ phần THAY ĐỔI mới bị soi: quyền ngoài tầm mà role đã giữ sẵn và request
     * không đụng tới thì vẫn giữ nguyên, để quản trị cấp dưới không bị khoá cứng
     * khỏi màn ma trận chỉ vì role đó có một quyền cao hơn họ.
     */
    public function test_giu_nguyen_quyen_ngoai_tam_ma_request_khong_dung_toi(): void
    {
        $actor = $this->userWithPermissions('manager8', self::IAM_PERMISSIONS);
        $target = $this->role('MIXED_ROLE');

        $ngoaiTam = $this->permission('warranty.pii.view');
        $trongTam = Permission::query()->where('permission_key', 'iam.user.view')->firstOrFail();
        $target->permissions()->attach($ngoaiTam->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $this->postJson("/api/v1/roles/{$target->role_id}/permissions", [
            'permission_ids' => [$ngoaiTam->permission_id, $trongTam->permission_id],
            'reason' => 'Bổ sung quyền xem người dùng, giữ nguyên phần còn lại',
        ], $this->headersFor($actor))->assertOk();

        $this->assertEqualsCanonicalizing(
            ['iam.user.view', 'warranty.pii.view'],
            $target->fresh()->permissions()->pluck('permission_key')->all()
        );
    }

    public function test_khong_thu_hoi_duoc_quyen_ngoai_tam_khoi_role_khac(): void
    {
        $actor = $this->userWithPermissions('manager9', self::IAM_PERMISSIONS);
        $target = $this->role('HIGH_ROLE');
        $ngoaiTam = $this->permission('warranty.pii.view');
        $target->permissions()->attach($ngoaiTam->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $this->postJson("/api/v1/roles/{$target->role_id}/permissions", [
            'permission_ids' => [],
            'reason' => 'Thử gỡ quyền cao hơn phạm vi của mình',
        ], $this->headersFor($actor))->assertStatus(403);

        $this->assertSame(1, $target->fresh()->permissions()->count());
    }

    public function test_quyen_cap_rieng_cho_tai_khoan_cung_chiu_gioi_han_uy_quyen(): void
    {
        $actor = $this->userWithPermissions('manager10', self::IAM_PERMISSIONS);
        $target = $this->plainUser('nguoi-nhan');
        $ngoaiTam = $this->permission('warranty.pii.export');

        $this->postJson("/api/v1/users/{$target->user_id}/permissions", [
            'permission_id' => $ngoaiTam->permission_id,
            'reason' => 'Thử cấp riêng một quyền vượt tầm',
        ], $this->headersFor($actor))->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DELEGATION_DENIED');
    }

    // ── Mã vai trò hệ thống ────────────────────────────────────────────────

    public function test_khong_tao_duoc_role_moi_mang_ma_super_admin(): void
    {
        $actor = $this->userWithPermissions('manager11', self::IAM_PERMISSIONS);

        $this->postJson('/api/v1/roles', [
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Giả danh quản trị hệ thống',
        ], $this->headersFor($actor))->assertStatus(422);
    }

    public function test_khong_doi_ten_duoc_role_thuong_thanh_super_admin(): void
    {
        $actor = $this->userWithPermissions('manager12', self::IAM_PERMISSIONS);
        $target = $this->role('RENAME_ME');

        $this->patchJson("/api/v1/roles/{$target->role_id}", [
            'role_code' => 'SUPER_ADMIN',
        ], $this->headersFor($actor))->assertStatus(422);

        $this->assertSame('RENAME_ME', $target->fresh()->role_code);
    }

    // ── Quyền đã ngừng hiệu lực ────────────────────────────────────────────

    public function test_khong_gan_lai_duoc_quyen_da_ngung_hieu_luc(): void
    {
        $actor = $this->superAdmin('root4');
        $target = $this->role('LEGACY_ROLE');
        $daThuHoi = Permission::create([
            'permission_id' => (string) Str::uuid(),
            'permission_key' => 'user.manage',
            'permission_name' => 'Mã cũ đã thu hồi',
            'scope_type' => 'FUNCTION',
            'status' => 'INACTIVE',
        ]);

        $this->postJson("/api/v1/roles/{$target->role_id}/permissions", [
            'permission_ids' => [$daThuHoi->permission_id],
            'reason' => 'Thử gán lại mã quyền đã thu hồi',
        ], $this->headersFor($actor))->assertStatus(422);
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    private function role(string $code): Role
    {
        return Role::firstOrCreate(
            ['role_code' => $code],
            ['role_name' => $code, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
        );
    }

    private function permission(string $key): Permission
    {
        return Permission::firstOrCreate(
            ['permission_key' => $key],
            [
                'permission_id' => (string) Str::uuid(),
                'permission_name' => $key,
                'scope_type' => 'FUNCTION',
                'status' => 'ACTIVE',
            ],
        );
    }

    /** @param  array<int, string>  $permissionKeys */
    private function userWithPermissions(string $username, array $permissionKeys): User
    {
        $role = $this->role('ROLE_'.strtoupper($username));
        foreach ($permissionKeys as $key) {
            $permission = $this->permission($key);
            if (! $role->permissions()->where('permission.permission_id', $permission->permission_id)->exists()) {
                $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
            }
        }

        $user = $this->plainUser($username);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user->fresh();
    }

    private function superAdmin(string $username): User
    {
        $user = $this->plainUser($username);
        $user->roles()->attach($this->role('SUPER_ADMIN')->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user->fresh();
    }

    private function plainUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'display_name' => $username,
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
        ]);
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }
}
