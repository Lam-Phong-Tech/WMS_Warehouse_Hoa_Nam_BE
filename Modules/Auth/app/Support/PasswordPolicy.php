<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;

/**
 * Chính sách mật khẩu mới dùng chung cho đổi mật khẩu, quên mật khẩu và các
 * luồng admin đặt mật khẩu. Thông điệp ở đây là copy đã chốt với người dùng;
 * controller chỉ việc gắn chúng vào đúng field.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 64;

    public const MSG_CURRENT_REQUIRED = 'Vui lòng nhập mật khẩu hiện tại';

    public const MSG_NEW_REQUIRED = 'Vui lòng nhập mật khẩu mới';

    public const MSG_CONFIRM_REQUIRED = 'Vui lòng xác nhận mật khẩu';

    public const MSG_WRONG_CURRENT = 'Mật khẩu hiện tại không chính xác';

    public const MSG_MIN = 'Mật khẩu mới tối thiểu 8 ký tự';

    public const MSG_MAX = 'Mật khẩu mới không được vượt quá 64 ký tự';

    public const MSG_COMPLEXITY = 'Mật khẩu phải bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt';

    public const MSG_WHITESPACE = 'Mật khẩu không được chứa khoảng trắng';

    public const MSG_USERNAME = 'Mật khẩu mới không được chứa tên đăng nhập';

    public const MSG_SAME_AS_CURRENT = 'Mật khẩu mới không được trùng với mật khẩu hiện tại';

    public const MSG_REUSED = 'Mật khẩu mới không được trùng với các mật khẩu đã sử dụng trước đó';

    public const MSG_CONFIRM_MISMATCH = 'Mật khẩu xác nhận không khớp với mật khẩu mới';

    public static function hasWhitespace(string $password): bool
    {
        return preg_match('/[\s\p{Z}]/u', $password) === 1;
    }

    public static function hasAllCharacterClasses(string $password): bool
    {
        return preg_match('/\p{Ll}/u', $password) === 1
            && preg_match('/\p{Lu}/u', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^\p{L}\d]/u', $password) === 1;
    }

    /**
     * Trả thông điệp field-level cho các rule cần User hiện tại (không thể đặt
     * trong Validator rule tĩnh). Trả null khi mật khẩu mới hợp lệ.
     */
    public static function reuseMessage(User $user, string $plainPassword, string $currentHash): ?string
    {
        if (Hash::check($plainPassword, $currentHash)) {
            return self::MSG_SAME_AS_CURRENT;
        }

        // Mật khẩu mặc định/đã lộ cũng là mật khẩu không được dùng lại.
        if (PasswordHistory::hasUsed($user, $plainPassword) || CredentialPolicy::isKnownDefault($plainPassword)) {
            return self::MSG_REUSED;
        }

        $lowered = mb_strtolower($plainPassword);
        foreach (self::personalFragments($user) as $fragment) {
            if (mb_strlen($fragment) >= 3 && str_contains($lowered, $fragment)) {
                return self::MSG_USERNAME;
            }
        }

        return null;
    }

    /**
     * Username nội bộ có thể là UUID (tài khoản tạo mới). Khi đó phần local
     * của email mới là thứ người dùng thực sự nhận ra; kiểm tra cả hai để rule
     * "không chứa tên đăng nhập" vẫn có ý nghĩa.
     *
     * @return array<int, string>
     */
    private static function personalFragments(User $user): array
    {
        $email = mb_strtolower(trim((string) $user->email));
        $localPart = str_contains($email, '@') ? strstr($email, '@', true) : $email;

        return array_values(array_filter([
            mb_strtolower(trim((string) $user->username)),
            is_string($localPart) ? $localPart : '',
        ], static fn (string $fragment): bool => $fragment !== ''));
    }
}
