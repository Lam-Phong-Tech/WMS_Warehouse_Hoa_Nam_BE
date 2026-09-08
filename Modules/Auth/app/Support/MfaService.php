<?php

namespace Modules\Auth\Support;

use App\Support\Security\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;

/**
 * WWHN-SEC-08 — nghiệp vụ MFA.
 *
 * Ba thứ ở đây đáng đọc kỹ vì chúng là chỗ MFA hay bị cài sai thành "bước bấm
 * thêm" thay vì một lớp bảo vệ thật:
 *
 *  1. PHIÊN CHỜ (challenge) không phải phiên đăng nhập. Sau khi mật khẩu đúng
 *     mà chưa qua MFA, server KHÔNG phát access token. Nó phát một mã ngắn hạn
 *     nằm trong cache, dùng đúng một lần. Nếu bước này trả token "hạn chế" thì
 *     kẻ có mật khẩu đã vào được hệ thống rồi.
 *
 *  2. MÃ ĐÃ DÙNG KHÔNG DÙNG LẠI ĐƯỢC. TOTP hợp lệ trong cả một cửa sổ 30-90
 *     giây; không ghi lại mã vừa dùng thì một mã bị nhìn trộm/chụp màn hình còn
 *     hiệu lực cho lần đăng nhập thứ hai.
 *
 *  3. TRẦN SỐ LẦN THỬ. 6 chữ số là 10^6 khả năng — không có trần thì dò xong
 *     trong vài phút bằng script.
 */
class MfaService
{
    private const CHALLENGE_CACHE_PREFIX = 'mfa:challenge:';

    private const USED_CODE_CACHE_PREFIX = 'mfa:used:';

    public function statusFor(User $user): ?UserMfa
    {
        return UserMfa::query()->find($user->user_id);
    }

    public function isEnabled(User $user): bool
    {
        return $this->statusFor($user)?->isConfirmed() === true;
    }

    /**
     * Vai trò của tài khoản có nằm trong nhóm bắt buộc MFA không.
     */
    public function isPrivileged(User $user): bool
    {
        $required = array_map('strtoupper', (array) config('mfa.required_roles', []));

        if ($required === []) {
            return false;
        }

        return $user->activeRoleModels()
            ->contains(static fn (Role $role): bool => in_array(strtoupper((string) $role->role_code), $required, true));
    }

    /**
     * Tài khoản này ĐANG bị chặn vì chưa đăng ký MFA?
     */
    public function mustEnrollNow(User $user): bool
    {
        return (bool) config('mfa.enforce', false)
            && $this->isPrivileged($user)
            && ! $this->isEnabled($user);
    }

    /**
     * Bắt đầu đăng ký: sinh bí mật mới và lưu ở trạng thái CHƯA xác nhận.
     *
     * Gọi lại khi đang có bản ghi chưa xác nhận sẽ sinh bí mật MỚI — đúng ý đồ:
     * người dùng quét QR hỏng ở lần trước không bị kẹt với một bí mật họ không
     * có trong điện thoại. Nhưng bản ghi ĐÃ xác nhận thì KHÔNG bị ghi đè: đổi
     * thiết bị phải đi qua `disable()` (cần mật khẩu + mã hiện tại).
     *
     * @return array{secret: string, uri: string, already_enabled: bool}
     */
    public function beginEnrollment(User $user): array
    {
        $existing = $this->statusFor($user);

        if ($existing?->isConfirmed() === true) {
            return ['secret' => '', 'uri' => '', 'already_enabled' => true];
        }

        $secret = Totp::generateSecret();

        UserMfa::query()->updateOrCreate(
            ['user_id' => $user->user_id],
            [
                'secret' => $secret,
                'confirmed_at' => null,
                'recovery_codes' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
            ],
        );

        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri(
                $secret,
                (string) ($user->email ?: $user->username),
                (string) config('mfa.issuer'),
                (int) config('mfa.totp.period'),
                (int) config('mfa.totp.digits'),
            ),
            'already_enabled' => false,
        ];
    }

    /**
     * Xác nhận đăng ký bằng một mã hợp lệ, trả về mã khôi phục MỘT LẦN DUY NHẤT.
     *
     * @return array<int, string>|null null nếu mã sai
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        $record = $this->statusFor($user);

        if ($record === null || $record->isConfirmed()) {
            return null;
        }

        if (! $this->verifyTotp($record, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $record->forceFill([
            'confirmed_at' => now(),
            // Chỉ băm được lưu. Người dùng thấy bản rõ đúng một lần ở response
            // này; hệ thống không có cách nào hiện lại.
            'recovery_codes' => array_map(static fn (string $plain): string => Hash::make($plain), $codes),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();

        return $codes;
    }

    /**
     * Tắt MFA. Bên gọi PHẢI kiểm mật khẩu hiện tại trước.
     */
    public function disable(User $user): void
    {
        UserMfa::query()->where('user_id', $user->user_id)->delete();
    }

    /**
     * Phát mã phiên chờ sau khi mật khẩu đúng.
     *
     * Lưu trong cache (Valkey) chứ không phải JWT: cần THU HỒI ĐƯỢC ngay khi
     * dùng xong, và một JWT stateless thì không thu hồi được — dùng lại được
     * suốt thời gian sống của nó.
     */
    public function issueChallenge(User $user): array
    {
        $token = Str::random(64);
        $ttl = max(60, (int) config('mfa.challenge_ttl_seconds', 300));

        Cache::put(self::CHALLENGE_CACHE_PREFIX.hash('sha256', $token), [
            'user_id' => (string) $user->user_id,
            // Ghim vào phiên bản tài khoản: đổi mật khẩu/thu hồi quyền giữa hai
            // bước sẽ làm phiên chờ mất hiệu lực.
            'password_changed_at' => (string) ($user->password_changed_at ?? ''),
        ], $ttl);

        return ['mfa_token' => $token, 'expires_in' => $ttl];
    }

    public function consumeChallenge(string $token): ?User
    {
        $key = self::CHALLENGE_CACHE_PREFIX.hash('sha256', $token);
        $payload = Cache::get($key);

        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return null;
        }

        $user = User::query()->find($payload['user_id']);

        if ($user === null) {
            Cache::forget($key);

            return null;
        }

        if ((string) ($user->password_changed_at ?? '') !== (string) ($payload['password_changed_at'] ?? '')) {
            // Mật khẩu đổi giữa hai bước — phiên chờ này thuộc về credential cũ.
            Cache::forget($key);

            return null;
        }

        // MỘT LẦN DUY NHẤT: xoá ngay khi tiêu thụ, kể cả khi bước sau thất bại.
        // Giữ lại để "cho người dùng thử tiếp" sẽ biến mã phiên chờ thành một
        // credential dùng nhiều lần trong 5 phút.
        Cache::forget($key);

        return $user;
    }

    /**
     * Xác minh mã TOTP hoặc mã khôi phục. Trả về loại đã dùng, hoặc null.
     *
     * @return 'totp'|'recovery'|null
     */
    public function verifySecondFactor(User $user, string $code): ?string
    {
        $record = $this->statusFor($user);

        if ($record === null || ! $record->isConfirmed() || $record->isLocked()) {
            return null;
        }

        if ($this->verifyTotp($record, $code)) {
            $record->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_at' => now()])->save();

            return 'totp';
        }

        if ($this->consumeRecoveryCode($record, $code)) {
            $record->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_at' => now()])->save();

            return 'recovery';
        }

        $this->registerFailure($record);

        return null;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) ($this->statusFor($user)?->recovery_codes ?? []));
    }

    // ------------------------------------------------------------------

    private function verifyTotp(UserMfa $record, string $code): bool
    {
        $secret = $record->secret;

        if ($secret === null || $secret === '') {
            return false;
        }

        if (! Totp::verify(
            $secret,
            $code,
            null,
            (int) config('mfa.totp.period', 30),
            (int) config('mfa.totp.window', 1),
            (int) config('mfa.totp.digits', 6),
        )) {
            return false;
        }

        // Chống dùng lại: TOTP hợp lệ suốt cả cửa sổ, nên một mã bị nhìn trộm
        // vẫn dùng được lần thứ hai nếu không ghi lại. Khoá theo (user, mã) và
        // sống hết cửa sổ hiện tại.
        $usedKey = self::USED_CODE_CACHE_PREFIX.$record->user_id.':'.hash('sha256', preg_replace('/\s+/', '', $code) ?? '');
        $ttl = (int) config('mfa.totp.period', 30) * (2 * (int) config('mfa.totp.window', 1) + 1);

        if (Cache::get($usedKey) !== null) {
            return false;
        }

        Cache::put($usedKey, 1, max(30, $ttl));

        return true;
    }

    private function consumeRecoveryCode(UserMfa $record, string $code): bool
    {
        $codes = (array) ($record->recovery_codes ?? []);
        $candidate = strtoupper(trim($code));

        foreach ($codes as $index => $hash) {
            if (Hash::check($candidate, (string) $hash)) {
                // MỘT LẦN: gỡ khỏi danh sách ngay. Đây là điểm phân biệt mã khôi
                // phục với mật khẩu phụ.
                unset($codes[$index]);
                $record->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function registerFailure(UserMfa $record): void
    {
        $max = max(1, (int) config('mfa.max_verify_attempts', 5));
        $attempts = $record->failed_attempts + 1;

        $record->forceFill([
            'failed_attempts' => $attempts,
            'locked_until' => $attempts >= $max
                ? now()->addSeconds(max(60, (int) config('mfa.lockout_seconds', 900)))
                : $record->locked_until,
        ])->save();
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        $count = max(1, (int) config('mfa.recovery_codes.count', 8));
        $bytes = max(4, (int) config('mfa.recovery_codes.bytes', 5));
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            // Dạng "XXXXX-XXXXX" cho dễ đọc/gõ lại khi người dùng in ra giấy.
            $hex = strtoupper(bin2hex(random_bytes($bytes)));
            $codes[] = substr($hex, 0, 5).'-'.substr($hex, 5);
        }

        return $codes;
    }
}
