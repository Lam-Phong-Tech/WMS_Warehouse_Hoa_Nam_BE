<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;

/**
 * Kho mật khẩu đã dùng. Không trả/nhật mật khẩu gốc ở bất kỳ đâu.
 *
 * Mật khẩu hệ thống cấp (admin tạo, admin đặt lại, bootstrap) có nguồn
 * SYSTEM_ISSUED và được giữ vĩnh viễn. Mật khẩu người dùng tự đặt có nguồn
 * USER_SET và chỉ giữ tối đa `history_limit` bản gần nhất.
 */
final class PasswordHistory
{
    public const SOURCE_SYSTEM = 'SYSTEM_ISSUED';

    public const SOURCE_USER = 'USER_SET';

    /**
     * Mật khẩu hiện tại có phải do hệ thống cấp?
     *
     * Tài khoản chưa từng đổi mật khẩu (password_changed_at NULL) được coi là
     * đang dùng mật khẩu ban đầu; tài khoản đang must_change_password cũng vậy
     * (ví dụ admin vừa đặt lại). Đây là tín hiệu cần thiết để nâng mật khẩu A
     * của tài khoản cũ lên lịch sử vĩnh viễn ngay lần đổi đầu tiên.
     */
    public static function sourceForCurrentPassword(User $user): string
    {
        return $user->must_change_password || $user->passwordChangedAtEpoch() === null
            ? self::SOURCE_SYSTEM
            : self::SOURCE_USER;
    }

    public static function record(User $user, string $hash, string $source): void
    {
        if ($hash === '') {
            return;
        }

        $exists = DB::table('password_histories')
            ->where('user_id', $user->user_id)
            ->where('password_hash', $hash)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('password_histories')->insert([
            'user_id' => $user->user_id,
            'password_hash' => $hash,
            'source' => $source,
            'created_at' => now(),
        ]);
    }

    public static function recordCurrent(User $user): void
    {
        self::record($user, (string) $user->password_hash, self::sourceForCurrentPassword($user));
    }

    /**
     * Ghi mật khẩu VỪA TRỞ THÀNH mật khẩu cũ sau một lần đổi. Chỉ mật khẩu
     * người dùng tự đặt mới bị giới hạn; mật khẩu hệ thống cấp giữ vĩnh viễn.
     */
    public static function recordPrevious(User $user, string $hash, string $source): void
    {
        self::record($user, $hash, $source);

        if ($source === self::SOURCE_USER) {
            self::pruneUserSet($user);
        }
    }

    public static function hasUsed(User $user, string $plainPassword): bool
    {
        $systemHashes = DB::table('password_histories')
            ->where('user_id', $user->user_id)
            ->where('source', self::SOURCE_SYSTEM)
            ->pluck('password_hash');

        foreach ($systemHashes as $hash) {
            if (Hash::check($plainPassword, (string) $hash)) {
                return true;
            }
        }

        foreach (self::recentUserHashes($user) as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    public static function pruneUserSet(User $user): void
    {
        $limit = self::userHistoryLimit();
        $staleIds = DB::table('password_histories')
            ->where('user_id', $user->user_id)
            ->where('source', self::SOURCE_USER)
            ->orderByDesc('id')
            ->pluck('id')
            ->slice($limit);

        if ($staleIds->isNotEmpty()) {
            DB::table('password_histories')->whereIn('id', $staleIds->all())->delete();
        }
    }

    /** @return array<int, string> */
    private static function recentUserHashes(User $user): array
    {
        $limit = self::userHistoryLimit();
        if ($limit === 0) {
            return [];
        }

        return DB::table('password_histories')
            ->where('user_id', $user->user_id)
            ->where('source', self::SOURCE_USER)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('password_hash')
            ->map(static fn ($hash): string => (string) $hash)
            ->all();
    }

    private static function userHistoryLimit(): int
    {
        return max(0, (int) config('login_security.password.history_limit', 5));
    }
}
