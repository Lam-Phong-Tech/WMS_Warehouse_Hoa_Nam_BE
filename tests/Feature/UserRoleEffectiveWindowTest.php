<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\RoleEffectiveWindow;
use Tests\TestCase;

/**
 * Sự cố 11/11/2026: "hiệu lực đến 2026-11-11" được lưu thành 00:00 UTC (07:00 giờ
 * VN) nên tài khoản mất mọi vai trò ngay sáng ngày hết hạn. Ngày lịch phải bao
 * trọn ngày theo múi giờ ứng dụng.
 */
class UserRoleEffectiveWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_date_only_window_covers_whole_calendar_days_in_app_timezone(): void
    {
        $from = RoleEffectiveWindow::normalizeFrom('2026-11-01');
        $to = RoleEffectiveWindow::normalizeTo('2026-11-11');

        $this->assertSame('2026-11-01T00:00:00+07:00', $from?->toIso8601String());
        $this->assertSame('2026-11-11T23:59:59+07:00', $to?->toIso8601String());
        $this->assertNull(RoleEffectiveWindow::normalizeTo(null));
        $this->assertNull(RoleEffectiveWindow::normalizeTo(''));
    }

    public function test_assigned_role_stays_active_through_the_last_day_and_expires_after_it(): void
    {
        $headers = $this->adminHeaders();
        $role = Role::create(['role_code' => 'ADMIN', 'role_name' => 'Quản trị viên', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $target = User::create([
            'username' => 'window-target', 'display_name' => 'Window target', 'email' => 'window@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1,
        ]);

        $this->postJson("/api/v1/users/{$target->user_id}/roles", [
            'roles' => [['role_id' => $role->role_id, 'effective_from' => '2026-11-01', 'effective_to' => '2026-11-11']],
            'reason' => 'Gán quyền có thời hạn',
        ], $headers)->assertOk();

        // 10:37 sáng ngày hết hạn (giờ VN) — vẫn còn hiệu lực.
        $this->travelToVietnam('2026-11-11 10:37:00');
        $payload = AuthUserPayload::authorization($target->fresh());
        $this->assertSame(['ADMIN'], array_column($payload['roles'], 'role_code'));
        $this->assertSame('2026-11-11T23:59:59+07:00', $payload['roles'][0]['effective_to']);
        $this->assertSame('2026-11-01T00:00:00+07:00', $payload['roles'][0]['effective_from']);
        $this->assertSame([], $payload['inactive_roles']);

        // Sang ngày hôm sau — hết hạn, hiển thị lý do thay vì im lặng 0 quyền.
        $this->travelToVietnam('2026-11-12 00:00:01');
        $payload = AuthUserPayload::authorization($target->fresh());
        $this->assertSame([], $payload['roles']);
        $this->assertSame('ADMIN', $payload['inactive_roles'][0]['role_code']);
        $this->assertSame('EXPIRED', $payload['inactive_roles'][0]['reason']);
        $this->assertSame('2026-11-11T23:59:59+07:00', $payload['inactive_roles'][0]['effective_to']);
    }

    public function test_future_window_is_reported_as_not_yet_effective(): void
    {
        $this->travelToVietnam('2026-11-11 09:00:00');
        $role = Role::create(['role_code' => 'VIEWER', 'role_name' => 'Người xem', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'future-user', 'display_name' => 'Future', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_role_id' => (string) Str::uuid(), 'user_id' => $user->user_id, 'role_id' => $role->role_id,
            'effective_from' => RoleEffectiveWindow::normalizeFrom('2026-11-20'), 'effective_to' => null,
        ]);

        $payload = AuthUserPayload::authorization($user->fresh());

        $this->assertSame([], $payload['roles']);
        $this->assertSame('NOT_YET_EFFECTIVE', $payload['inactive_roles'][0]['reason']);
        $this->assertNull($payload['inactive_roles'][0]['effective_to']);
    }

    private function travelToVietnam(string $wallClock): void
    {
        $instant = CarbonImmutable::parse($wallClock, 'Asia/Ho_Chi_Minh');
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    private function adminHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6), 'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }
}
