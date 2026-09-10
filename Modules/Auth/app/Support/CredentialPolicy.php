<?php

namespace Modules\Auth\Support;

/**
 * Chính sách tối thiểu cho mật khẩu do HỆ THỐNG cấp ra ngoài luồng người dùng tự
 * đặt: mật khẩu ban đầu của `wms:bootstrap-super-admin` và mật khẩu fixture của môi
 * trường demo/UAT. Không thay rule của LoginRequest/StoreUserRequest.
 *
 * Trả về DANH SÁCH VI PHẠM bằng lời thay vì true/false để thông báo lỗi nói được
 * phải sửa gì — và không bao giờ lặp lại chính mật khẩu trong thông báo.
 */
final class CredentialPolicy
{
    /**
     * @param  array<int, string|null>  $personalFragments  email/username — không được nằm trong mật khẩu
     * @return array<int, string>
     */
    public static function problems(string $password, int $minLength, array $personalFragments = []): array
    {
        $problems = [];

        if (mb_strlen($password) < $minLength) {
            $problems[] = "tối thiểu {$minLength} ký tự";
        }

        $characterClasses = (int) preg_match('/\p{Ll}/u', $password)
            + (int) preg_match('/\p{Lu}/u', $password)
            + (int) preg_match('/\d/', $password)
            + (int) preg_match('/[^\p{L}\d]/u', $password);

        if ($characterClasses < 3) {
            $problems[] = 'cần ít nhất 3 trong 4 nhóm ký tự: chữ thường, chữ hoa, chữ số, ký tự đặc biệt';
        }

        if (self::isKnownDefault($password)) {
            $problems[] = 'trùng một mật khẩu mặc định hoặc đã bị lộ (golive.credential)';
        }

        $lowered = mb_strtolower($password);
        foreach ($personalFragments as $fragment) {
            $fragment = mb_strtolower(trim((string) $fragment));

            if (mb_strlen($fragment) >= 4 && str_contains($lowered, $fragment)) {
                $problems[] = 'không được chứa email hoặc tên đăng nhập';

                break;
            }
        }

        return $problems;
    }

    /** So không phân biệt hoa thường: `cntt@1234` cũng là mật khẩu đã lộ. */
    public static function isKnownDefault(string $password): bool
    {
        $candidate = mb_strtolower($password);

        foreach (self::knownDefaults() as $known) {
            if (hash_equals(mb_strtolower($known), $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mật khẩu ĐÃ LỘ của chính dự án + mật khẩu mặc định yếu phổ biến.
     *
     * @return array<int, string>
     */
    public static function knownDefaults(): array
    {
        $values = array_map(
            static fn ($value): string => (string) $value,
            array_merge(
                (array) config('golive.credential.compromised', []),
                (array) config('golive.credential.known_defaults', []),
            ),
        );

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }
}
