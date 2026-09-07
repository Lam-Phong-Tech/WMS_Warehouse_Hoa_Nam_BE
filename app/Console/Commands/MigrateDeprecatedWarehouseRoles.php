<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

/**
 * Chuyển tài khoản khỏi hai vai đã ngừng dùng — WAREHOUSE_KEEPER / WAREHOUSE_APPROVER.
 *
 * Vì sao cần một lệnh thay vì bấm tay trên màn IAM: tài liệu BA
 * `taikhoantheorole_da_sua_v2` (nguyên tắc 6) giao việc này cho DEV, và trên môi trường
 * đã triển khai con số không nhỏ — đo trên DEV 07/09/2026 là 12 tài khoản. Bấm tay 12
 * lần là 12 cơ hội gán nhầm vai, và không để lại một bản ghi nào cho thấy đợt chuyển
 * đã chạy tới đâu. Lệnh này chạy được nhiều lần, in ra đúng thứ sắp làm trước khi làm,
 * và ghi audit log cho từng tài khoản.
 *
 * MẶC ĐỊNH LÀ DRY-RUN. Không có `--apply` thì không ghi gì vào CSDL.
 *
 *   php artisan wms:migrate-deprecated-roles                       # xem trước
 *   php artisan wms:migrate-deprecated-roles --apply               # chuyển sang WAREHOUSE_STAFF
 *   php artisan wms:migrate-deprecated-roles --to=WAREHOUSE_MANAGER --only=quanlykho01 --apply
 *
 * Quy tắc chọn vai đích (BA §6 mục ③):
 *   - WAREHOUSE_STAFF   — người thực sự đứng ở bãi nhận/giao và thao tác chứng từ;
 *   - WAREHOUSE_MANAGER — người thực chất chỉ theo dõi và ký duyệt trên giấy.
 * Lệnh KHÔNG tự đoán: mặc định đưa hết về WAREHOUSE_STAFF (vai vận hành, nay đã nhận
 * đủ quyền Post/huỷ) vì đó là ánh xạ đúng cho đa số; ai thuộc nhóm còn lại thì chạy
 * riêng bằng `--only=<username>[,<username>] --to=WAREHOUSE_MANAGER`.
 */
class MigrateDeprecatedWarehouseRoles extends Command
{
    protected $signature = 'wms:migrate-deprecated-roles
        {--apply : Ghi thay đổi vào CSDL. Bỏ cờ này thì chỉ in ra bảng xem trước.}
        {--to=WAREHOUSE_STAFF : Vai đích — WAREHOUSE_STAFF hoặc WAREHOUSE_MANAGER.}
        {--only= : Chỉ xử lý các username này (ngăn cách bằng dấu phẩy).}
        {--keep-fixtures : Bỏ qua tài khoản fixture của seeder (keeper01, approver01, thukho*, duyetkho*, warehouse-keeper-*, warehouse-approver-*).}';

    protected $description = 'Chuyển tài khoản khỏi WAREHOUSE_KEEPER/WAREHOUSE_APPROVER sang vai của mô hình phân quyền mới (mặc định dry-run).';

    /** Hai vai ngừng dùng — khớp AuthDatabaseSeeder::DEPRECATED_ROLES. */
    private const DEPRECATED = ['WAREHOUSE_KEEPER', 'WAREHOUSE_APPROVER'];

    private const ALLOWED_TARGETS = ['WAREHOUSE_STAFF', 'WAREHOUSE_MANAGER'];

    /**
     * Tài khoản do seeder sinh ra, KHÔNG phải người thật. Bộ E2E và tài liệu bàn giao
     * cũ tham chiếu keeper01/approver01, còn nhóm thukho + duyetkho là dữ liệu cố ý
     * INACTIVE/LOCKED để ba bộ lọc màn IAM-01 có gì để lọc. Chuyển chúng đi sẽ làm đỏ
     * những thứ chẳng liên quan gì tới đợt phân quyền.
     */
    private const FIXTURE_PATTERNS = ['keeper01', 'approver01', 'thukho', 'duyetkho', 'warehouse-keeper-', 'warehouse-approver-', 'uat.keeper', 'uat.approver'];

    public function handle(): int
    {
        $target = strtoupper((string) $this->option('to'));
        if (! in_array($target, self::ALLOWED_TARGETS, true)) {
            $this->error('--to phải là một trong: '.implode(', ', self::ALLOWED_TARGETS).'. Nhận được: '.$target);

            return self::FAILURE;
        }

        $targetRole = Role::query()->where('role_code', $target)->first();
        if ($targetRole === null) {
            $this->error("Vai đích {$target} không tồn tại trong CSDL. Chạy AuthDatabaseSeeder trước.");

            return self::FAILURE;
        }

        $rows = $this->candidates();

        if ($rows->isEmpty()) {
            $this->info('Không còn tài khoản nào giữ WAREHOUSE_KEEPER/WAREHOUSE_APPROVER. Không có việc để làm.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->table(
            ['Username', 'Email', 'Trạng thái', 'Vai hiện tại', 'Vai đích', 'Fixture?'],
            $rows->map(fn (object $r): array => [
                $r->username,
                (string) ($r->email ?? ''),
                $r->status,
                $r->role_code,
                $target,
                $this->isFixture($r->username) ? 'có' : '',
            ])->all()
        );

        if (! $apply) {
            $this->warn('DRY-RUN — chưa ghi gì. Thêm --apply để thực hiện.');
            $this->line('Vai đích mặc định là WAREHOUSE_STAFF. Người chỉ giám sát thì chạy riêng với --to=WAREHOUSE_MANAGER --only=<username>.');

            return self::SUCCESS;
        }

        $moved = 0;
        DB::transaction(function () use ($rows, $targetRole, $target, &$moved): void {
            foreach ($rows as $row) {
                $user = User::query()->find($row->user_id);
                if ($user === null) {
                    continue;
                }

                $before = $user->roles()->pluck('role_code')->all();

                // Gỡ vai cũ trước, gắn vai mới sau — và chỉ gắn nếu chưa có, để chạy
                // lại lệnh không tạo dòng user_role trùng.
                DB::table('user_role')
                    ->where('user_id', $row->user_id)
                    ->where('role_id', $row->role_id)
                    ->delete();

                $hasTarget = DB::table('user_role')
                    ->where('user_id', $row->user_id)
                    ->where('role_id', $targetRole->role_id)
                    ->exists();

                if (! $hasTarget) {
                    DB::table('user_role')->insert([
                        'user_role_id' => (string) Str::uuid(),
                        'user_id' => $row->user_id,
                        'role_id' => $targetRole->role_id,
                    ]);
                }

                AuditLogger::record(
                    action: 'UPDATE',
                    objectType: 'user',
                    objectId: (string) $row->user_id,
                    actorId: null,
                    beforeData: ['roles' => $before],
                    afterData: ['roles' => $user->fresh()->roles()->pluck('role_code')->all()],
                    reason: "Chuyển khỏi vai ngừng dùng {$row->role_code} sang {$target} theo tài liệu BA taikhoantheorole v2 (nguyên tắc 6).",
                );

                $moved++;
            }
        });

        $this->info("Đã chuyển {$moved} tài khoản sang {$target}. Mỗi tài khoản có một dòng audit log kèm lý do.");
        $this->warn('Nhớ chạy `php artisan cache:clear` — quyền hiệu dụng của user có cache.');

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function candidates(): Collection
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        return DB::table('app_user')
            ->join('user_role', 'app_user.user_id', '=', 'user_role.user_id')
            ->join('role', 'role.role_id', '=', 'user_role.role_id')
            ->whereIn('role.role_code', self::DEPRECATED)
            ->when($only !== [], fn ($q) => $q->whereIn('app_user.username', $only))
            ->orderBy('role.role_code')
            ->orderBy('app_user.username')
            ->select('app_user.user_id', 'app_user.username', 'app_user.email', 'app_user.status', 'role.role_code', 'role.role_id')
            ->get()
            ->reject(fn (object $r): bool => $this->option('keep-fixtures') && $this->isFixture($r->username))
            ->values();
    }

    private function isFixture(string $username): bool
    {
        foreach (self::FIXTURE_PATTERNS as $pattern) {
            if (str_starts_with($username, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
