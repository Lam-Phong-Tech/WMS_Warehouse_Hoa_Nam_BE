<?php

namespace Modules\Auth\Console\Commands;

use Illuminate\Console\Command;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\LoginThrottle;

class ClearLoginThrottle extends Command
{
    protected $signature = 'auth:clear-login-throttle {--email= : Email định danh đăng nhập cần xoá bộ đếm}';

    protected $description = 'Xoá bộ đếm đăng nhập sai và trạng thái step-up của một định danh email (có ghi audit)';

    public function handle(LoginThrottle $throttle): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        if ($email === '') {
            $this->error('Vui lòng truyền --email=...');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $throttle->clear($email);

        AuditLogger::record(
            action: 'LOGIN_THROTTLE_CLEARED',
            objectType: 'app_user',
            objectId: $user !== null ? (string) $user->user_id : null,
            actorId: null,
            afterData: [
                'action' => 'CLEAR_LOGIN_THROTTLE',
                'operator' => get_current_user() ?: 'cli',
                'email_hash' => $throttle->emailHash($email),
            ],
            reason: 'Operator cleared login throttle via CLI',
            result: 'SUCCESS',
        );

        $this->info('Đã xoá bộ đếm đăng nhập cho định danh được chỉ định.');

        return self::SUCCESS;
    }
}
