<?php

namespace Modules\Auth\Console\Commands;

use Illuminate\Console\Command;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Events\UserAccountChanged;
use Modules\Auth\Models\User;

/**
 * Cấp tài khoản nhân viên: nhân viên được admin cấp mật khẩu phải đăng nhập
 * lần đầu trong 7 ngày (User::first_login_deadline_at, set ở
 * UserController::store()). Nếu không, tài khoản phải tự khoá — không thể chỉ
 * kiểm tra lúc login vì nhân viên không đăng nhập thì không có request nào để
 * bắt; đồng thời admin cần thấy đúng status=LOCKED trong danh sách user dù
 * nhân viên chưa từng thử đăng nhập.
 */
class LockExpiredFirstLoginAccounts extends Command
{
    protected $signature = 'auth:lock-expired-first-login';

    protected $description = 'Khoá tài khoản nhân viên chưa đăng nhập lần đầu trong vòng 7 ngày kể từ khi được cấp';

    public function handle(): int
    {
        $lockedCount = 0;

        User::query()
            ->where('status', 'ACTIVE')
            ->where('must_change_password', true)
            ->whereNull('last_login_at')
            ->whereNotNull('first_login_deadline_at')
            ->where('first_login_deadline_at', '<', now())
            ->chunkById(100, function ($users) use (&$lockedCount): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $user->forceFill([
                        'status' => 'LOCKED',
                        'version' => ((int) $user->version) + 1,
                    ])->save();

                    AuditLogger::record(
                        action: 'AUTO_LOCK',
                        objectType: 'app_user',
                        objectId: $user->user_id,
                        actorId: null,
                        reason: 'Không đăng nhập lần đầu trong vòng 7 ngày kể từ khi được cấp tài khoản',
                        result: 'SUCCESS',
                    );

                    event(new UserAccountChanged($user->user_id, 'AUTO_LOCK', null));

                    $lockedCount++;
                }
            }, 'user_id');

        $this->info("Đã khoá {$lockedCount} tài khoản quá hạn đăng nhập lần đầu.");

        return self::SUCCESS;
    }
}
