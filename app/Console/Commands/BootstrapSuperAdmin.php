<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Events\SecurityAlertRaised;
use Modules\Auth\Support\PasswordHistory;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\CredentialPolicy;
use Throwable;

/**
 * P0 bảo mật 10/09/2026 — cấp tài khoản SUPER_ADMIN ĐẦU TIÊN, tách hẳn khỏi `db:seed`.
 *
 * Trước đây quản trị viên đầu tiên sinh ra từ AuthDatabaseSeeder với mật khẩu hằng số
 * trong source, và mọi lần chạy lại seed đều reset mật khẩu đó. Nay seeder ngoài
 * local/testing không tạo tài khoản nào (xem SeedEnvironment), và đây là đường DUY
 * NHẤT để có SUPER_ADMIN trên môi trường mới:
 *
 *  - fail-closed: thiếu email, thiếu secret, secret yếu/đã lộ, chưa migrate/seed vai
 *    trò → thoát 1, không ghi gì;
 *  - chạy MỘT lần: đã có bất kỳ tài khoản nào giữ SUPER_ADMIN (kể cả đang khoá) thì từ
 *    chối; hai lần chạy song song xếp hàng trên khoá hàng của vai trò SUPER_ADMIN;
 *  - không chiếm tài khoản có sẵn: email/username đã tồn tại thì từ chối;
 *  - không có mật khẩu mặc định và không bao giờ in/ghi log mật khẩu: secret đến từ
 *    biến môi trường của chính tiến trình (nạp từ secret store), hoặc được sinh ngẫu
 *    nhiên (CSPRNG) và ghi vào một tệp MỚI quyền 0600;
 *  - bắt buộc đổi mật khẩu ở lần đăng nhập đầu (must_change_password) và tự khoá sau 7
 *    ngày nếu chưa đăng nhập — cùng chính sách tài khoản do IAM cấp;
 *  - MFA theo chính sách hiện hành: SUPER_ADMIN nằm trong mfa.required_roles, nên khi
 *    cưỡng chế bật, EnforceMfaEnrollment chặn API cho tới khi đăng ký xong;
 *  - audit `SUPER_ADMIN_BOOTSTRAPPED` (và `SUPER_ADMIN_BOOTSTRAP_REFUSED` khi từ chối).
 *
 * Runbook:
 *   # secret đi từ secret store vào biến môi trường, KHÔNG gõ giá trị trên dòng lệnh
 *   docker compose exec -T -e WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD backend \
 *     php artisan wms:bootstrap-super-admin --email=<email quản trị> --force
 *
 *   # hoặc để lệnh tự sinh mật khẩu vào tệp mới quyền 0600, chuyển tay rồi xoá tệp
 *   php artisan wms:bootstrap-super-admin --email=<email quản trị> \
 *     --generate-password-file=/run/secrets/wms-first-admin.txt --force
 */
class BootstrapSuperAdmin extends Command
{
    use ConfirmableTrait;

    public const AUDIT_ACTION = 'SUPER_ADMIN_BOOTSTRAPPED';

    public const AUDIT_REFUSED_ACTION = 'SUPER_ADMIN_BOOTSTRAP_REFUSED';

    private const DEFAULT_DISPLAY_NAME = 'Quản trị hệ thống';

    private const FIRST_LOGIN_DEADLINE_DAYS = 7;

    protected $signature = 'wms:bootstrap-super-admin
        {--email= : Email đăng nhập của quản trị viên đầu tiên (bắt buộc)}
        {--username= : Username IAM; mặc định là phần trước @ của email}
        {--display-name= : Tên hiển thị}
        {--password-env=WMS_BOOTSTRAP_SUPER_ADMIN_PASSWORD : Tên BIẾN MÔI TRƯỜNG chứa mật khẩu ban đầu}
        {--generate-password-file= : Tự sinh mật khẩu ngẫu nhiên và ghi vào tệp MỚI quyền 0600 thay cho biến môi trường}
        {--force : Bỏ bước xác nhận ngoài local/testing}';

    protected $description = 'Tạo tài khoản SUPER_ADMIN đầu tiên — một lần, fail-closed, bắt buộc đổi mật khẩu ở lần đăng nhập đầu.';

    private function relationExists(string $relation): bool
    {
        return Schema::hasTable($relation) || Schema::hasView($relation);
    }

    public function handle(AuditLogger $audit): int
    {
        $isDevelopment = in_array(
            (string) $this->laravel->environment(),
            array_map('strval', (array) config('golive.development_environments', ['local', 'testing'])),
            true,
        );

        if (! $this->confirmToProceed('Sắp tạo tài khoản SUPER_ADMIN đầu tiên trên môi trường không phải development.', fn (): bool => ! $isDevelopment)) {
            return self::FAILURE;
        }

        $email = trim((string) $this->option('email'));
        $username = trim((string) ($this->option('username') ?: Str::before($email, '@')));
        $displayName = trim((string) ($this->option('display-name') ?: self::DEFAULT_DISPLAY_NAME));

        $validator = Validator::make(
            ['email' => $email, 'username' => $username, 'display_name' => $displayName],
            [
                'email' => ['required', 'email', 'max:255'],
                'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
                'display_name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Trên PostgreSQL `app_user` và `user_role` là VIEW của ERD, mà `Schema::hasTable()`
        // chỉ thấy BASE TABLE — kiểm cả view, nếu không lệnh từ chối chạy trên mọi CSDL thật
        // (SQLite của bộ test coi view như bảng nên không lộ lỗi này).
        if (! $this->relationExists('app_user') || ! $this->relationExists('user_role')) {
            $this->error('Chưa có schema IAM. Chạy `php artisan migrate --force` trước.');

            return self::FAILURE;
        }

        $role = Role::query()->where('role_code', 'SUPER_ADMIN')->first();

        if ($role === null) {
            $this->error('Chưa có vai trò SUPER_ADMIN. Chạy `php artisan db:seed --force` trước — ngoài local/testing seeder chỉ tạo danh mục quyền/vai trò, không tạo tài khoản.');

            return self::FAILURE;
        }

        // Kiểm tra sớm, không khoá: đã bootstrap rồi thì không đòi/sinh secret vô ích.
        if ($this->superAdminAlreadyProvisioned($role)) {
            return $this->refuse($audit, 'ALREADY_PROVISIONED');
        }

        $passwordFile = trim((string) $this->option('generate-password-file'));
        $passwordEnv = trim((string) $this->option('password-env'));

        if ($passwordFile !== '') {
            $password = $this->generatePassword();
        } else {
            $password = $this->secretFromEnvironment($passwordEnv);

            if ($password === null) {
                $this->error("Biến môi trường {$passwordEnv} trống hoặc không tồn tại. Nạp mật khẩu ban đầu từ secret store vào biến này (không truyền giá trị trên dòng lệnh), hoặc dùng --generate-password-file.");

                return self::FAILURE;
            }
        }

        $minLength = max(12, (int) config('golive.bootstrap.min_password_length', 16));
        $problems = CredentialPolicy::problems($password, $minLength, [$email, Str::before($email, '@'), $username]);

        if ($problems !== []) {
            $this->error('Mật khẩu ban đầu không đạt chính sách: '.implode('; ', $problems).'.');

            return self::FAILURE;
        }

        // Ghi tệp TRƯỚC khi commit: nếu ghi hỏng sau khi tài khoản đã tồn tại thì không ai
        // biết mật khẩu, còn lệnh bootstrap thì đã tự khoá không cho chạy lại.
        if ($passwordFile !== '' && ! $this->writeSecretFile($passwordFile, $password)) {
            return self::FAILURE;
        }

        $credentialSource = $passwordFile !== '' ? 'generated_file' : 'environment';

        try {
            $outcome = DB::transaction(function () use ($role, $email, $username, $displayName, $password, $credentialSource, $audit): User|string {
                // Hai lần chạy song song xếp hàng tại khoá hàng này; lần sau thấy lần trước
                // đã tạo xong nên dừng thay vì sinh SUPER_ADMIN thứ hai.
                $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if ($this->superAdminAlreadyProvisioned($lockedRole)) {
                    return 'ALREADY_PROVISIONED';
                }

                $identityTaken = User::withTrashed()
                    ->where(fn ($query) => $query->where('email', $email)->orWhere('username', $username))
                    ->exists();

                if ($identityTaken) {
                    return 'IDENTITY_TAKEN';
                }

                $user = User::query()->create([
                    'username' => $username,
                    'display_name' => $displayName,
                    'email' => $email,
                    'password_hash' => Hash::make($password),
                    'status' => 'ACTIVE',
                    'version' => 1,
                    'must_change_password' => true,
                    'first_login_deadline_at' => now()->addDays(self::FIRST_LOGIN_DEADLINE_DAYS),
                ]);

                PasswordHistory::record($user, (string) $user->password_hash, PasswordHistory::SOURCE_SYSTEM);

                $user->roles()->attach($lockedRole->getKey(), ['user_role_id' => (string) Str::uuid()]);

                $audit->log(
                    self::AUDIT_ACTION,
                    'app_user',
                    $user->user_id,
                    null,
                    null,
                    [],
                    [
                        'user_id' => $user->user_id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => 'SUPER_ADMIN',
                        'must_change_password' => true,
                        'credential_source' => $credentialSource,
                        'mfa_enforced' => (bool) config('mfa.enforce', false),
                        'environment' => (string) app()->environment(),
                    ],
                    'Cấp SUPER_ADMIN đầu tiên qua wms:bootstrap-super-admin.',
                    result: 'SUCCESS',
                );

                return $user;
            });
        } catch (Throwable $exception) {
            $this->discardSecretFile($passwordFile);

            throw $exception;
        }

        if (is_string($outcome)) {
            $this->discardSecretFile($passwordFile);

            return $this->refuse($audit, $outcome);
        }

        try {
            event(new SecurityAlertRaised(
                self::AUDIT_ACTION,
                $outcome->user_id,
                "Tài khoản SUPER_ADMIN đầu tiên ({$outcome->username}) vừa được cấp bằng lệnh bootstrap.",
            ));
        } catch (Throwable) {
            // Tài khoản đã tạo và đã có audit trong cùng transaction; thông báo cho quản
            // trị IAM là tín hiệu phụ, không được làm lệnh báo lỗi sau khi đã commit.
            $this->warn('Không gửi được thông báo cảnh báo bảo mật (hàng đợi không sẵn sàng). Audit SUPER_ADMIN_BOOTSTRAPPED vẫn đã được ghi.');
        }

        $this->info("Đã tạo SUPER_ADMIN đầu tiên: {$outcome->username} <{$outcome->email}> (user_id {$outcome->user_id}).");
        $this->line($passwordFile !== ''
            ? "Mật khẩu ban đầu đã ghi vào {$passwordFile} (quyền 0600). Chuyển cho người nhận qua kênh an toàn rồi XOÁ tệp."
            : "Mật khẩu ban đầu lấy từ biến môi trường {$passwordEnv}; lệnh không in lại giá trị này.");
        $this->line('Lần đăng nhập đầu BẮT BUỘC đổi mật khẩu (PASSWORD_CHANGE_REQUIRED); tài khoản tự khoá nếu quá '.self::FIRST_LOGIN_DEADLINE_DAYS.' ngày chưa đăng nhập lần đầu.');

        if ((bool) config('mfa.enforce', false)) {
            $this->line('MFA đang cưỡng chế cho SUPER_ADMIN: sau khi đổi mật khẩu phải đăng ký xác thực hai bước trước khi dùng API.');
        } elseif (! $isDevelopment) {
            $this->warn('MFA_ENFORCE_FOR_PRIVILEGED_ROLES=false — `wms:golive-check` sẽ FAIL ở security.mfa. Đăng ký MFA cho tài khoản này rồi bật cờ.');
        }

        return self::SUCCESS;
    }

    private function superAdminAlreadyProvisioned(Role $role): bool
    {
        // Bất kỳ tài khoản nào từng được gắn SUPER_ADMIN — kể cả INACTIVE/LOCKED hay đã
        // xoá mềm — đều chặn bootstrap. Khôi phục quyền quản trị là thủ tục khác.
        return DB::table('user_role')->where('role_id', $role->getKey())->exists();
    }

    private function refuse(AuditLogger $audit, string $reasonCode): int
    {
        $message = match ($reasonCode) {
            'ALREADY_PROVISIONED' => 'Đã tồn tại tài khoản giữ vai trò SUPER_ADMIN — lệnh bootstrap chỉ chạy MỘT lần. Cấp quyền cho người mới qua IAM bằng tài khoản quản trị hiện có.',
            'IDENTITY_TAKEN' => 'Email hoặc username đã thuộc về một tài khoản có sẵn. Lệnh bootstrap không chiếm quyền tài khoản có sẵn — chọn email/username khác.',
            default => 'Từ chối bootstrap SUPER_ADMIN.',
        };

        $audit->log(
            self::AUDIT_REFUSED_ACTION,
            'app_user',
            null,
            null,
            null,
            [],
            ['reason_code' => $reasonCode, 'environment' => (string) app()->environment()],
            $message,
            result: 'DENIED',
            errorCode: $reasonCode,
        );

        $this->error($message);

        return self::FAILURE;
    }

    private function secretFromEnvironment(string $name): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $name) !== 1) {
            return null;
        }

        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function generatePassword(): string
    {
        // 30 byte CSPRNG → 40 ký tự base64url (~240 bit). Đuôi cố định chỉ để chắc chắn
        // đủ 4 nhóm ký tự của CredentialPolicy, không góp phần vào độ mạnh.
        return rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=').'#'.random_int(10, 99).'aZ';
    }

    private function writeSecretFile(string $path, string $secret): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            $this->error("Thư mục {$directory} không tồn tại.");

            return false;
        }

        $previousUmask = umask(0077);

        try {
            // 'x' = chỉ tạo tệp MỚI: không bao giờ ghi đè một tệp đã có.
            $handle = @fopen($path, 'x');

            if ($handle === false) {
                $this->error("Không tạo được tệp {$path} (đã tồn tại hoặc không có quyền ghi). Lệnh không ghi đè tệp có sẵn.");

                return false;
            }

            @chmod($path, 0600);
            $written = fwrite($handle, $secret.PHP_EOL);
            fclose($handle);

            if ($written === false) {
                @unlink($path);
                $this->error("Không ghi được tệp {$path}.");

                return false;
            }

            return true;
        } finally {
            umask($previousUmask);
        }
    }

    private function discardSecretFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
