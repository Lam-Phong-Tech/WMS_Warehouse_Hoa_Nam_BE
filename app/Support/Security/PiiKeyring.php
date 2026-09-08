<?php

namespace App\Support\Security;

use RuntimeException;

/**
 * WWHN-SEC-06 — keyring có phiên bản cho PII.
 *
 * MỘT VẤN ĐỀ, HAI CÁCH LÀM SAI PHỔ BIẾN, VÀ CÁCH LÀM ĐÚNG
 *
 * Sai 1 — mã hoá bằng APP_KEY: dữ liệu khách hàng bị buộc vĩnh viễn vào khoá mà
 * quy trình vận hành yêu cầu phải xoay được. Xoay = mất dữ liệu, nên không ai
 * xoay nữa.
 *
 * Sai 2 — một khoá duy nhất không có định danh: muốn xoay khoá thì phải dừng hệ
 * thống, giải mã toàn bộ bằng khoá cũ, mã hoá lại bằng khoá mới, rồi mới chạy
 * lại. Với bảng đang được ghi liên tục thì cửa sổ đó không tồn tại.
 *
 * Đúng — keyring: mỗi khoá có `id`; ciphertext mang `id` ở đầu. Hệ thống GIẢI MÃ
 * được bằng bất kỳ khoá nào còn trong keyring và GHI bằng đúng khoá `active`.
 * Xoay khoá trở thành: thêm khoá mới -> đổi active -> `pii:reencrypt` chạy nền ->
 * gỡ khoá cũ. Không có phút nào hệ thống phải dừng.
 *
 * Trong bộ nhớ tiến trình, keyring được phân giải MỘT LẦN (::resolve cache theo
 * mục đích) — mọi request sau đó không parse lại JSON và không chạy lại HKDF.
 * Đây là điều kiện để lớp mã hoá không nằm trên đường tới hạn của API.
 */
final class PiiKeyring
{
    public const PURPOSE_ENCRYPTION = 'encryption';

    public const PURPOSE_BLIND_INDEX = 'blind_index';

    /**
     * Cache theo tiến trình. KHÔNG phải cache dùng chung (Redis/file): khoá thô
     * không bao giờ được rời khỏi bộ nhớ tiến trình.
     *
     * @var array<string, array{keys: array<string, string>, active: string, derived: bool}>
     */
    private static array $resolved = [];

    /** @return array{keys: array<string, string>, active: string, derived: bool} */
    public static function resolve(string $purpose): array
    {
        if (isset(self::$resolved[$purpose])) {
            return self::$resolved[$purpose];
        }

        $raw = trim((string) config('pii.'.$purpose.'.keys', ''));
        $active = trim((string) config('pii.'.$purpose.'.active', 'v1'));
        $active = $active !== '' ? $active : 'v1';

        if ($raw !== '') {
            return self::$resolved[$purpose] = [
                'keys' => self::parseKeyring($raw, $purpose),
                'active' => $active,
                'derived' => false,
            ];
        }

        // Không có keyring: chỉ chấp nhận ở môi trường phát triển.
        if (! self::mayDeriveFromAppKey()) {
            throw new RuntimeException(
                'PII keyring chưa được cấu hình cho mục đích "'.$purpose.'". '
                .'Ngoài local/testing phải khai PII_ENCRYPTION_KEYS và PII_BLIND_INDEX_KEYS '
                .'(sinh bằng `php artisan pii:make-key`).'
            );
        }

        return self::$resolved[$purpose] = [
            'keys' => [$active => self::deriveDevKey($purpose)],
            'active' => $active,
            'derived' => true,
        ];
    }

    public static function activeKey(string $purpose): string
    {
        $ring = self::resolve($purpose);
        $id = $ring['active'];

        if (! isset($ring['keys'][$id])) {
            throw new RuntimeException(
                'PII active key id "'.$id.'" không có trong keyring "'.$purpose.'". '
                .'Đổi active trước khi thêm khoá là cách chắc chắn nhất để hỏng cả ghi lẫn đọc.'
            );
        }

        return $ring['keys'][$id];
    }

    public static function activeKeyId(string $purpose): string
    {
        return self::resolve($purpose)['active'];
    }

    public static function keyById(string $purpose, string $id): ?string
    {
        return self::resolve($purpose)['keys'][$id] ?? null;
    }

    /**
     * Mọi key id còn giải mã / còn tra cứu được. Dùng cho blind index lúc đang
     * xoay khoá: truy vấn phải khớp CẢ giá trị sinh bằng khoá cũ.
     *
     * @return array<int, string>
     */
    public static function keyIds(string $purpose): array
    {
        return array_keys(self::resolve($purpose)['keys']);
    }

    public static function isDerived(string $purpose): bool
    {
        return self::resolve($purpose)['derived'];
    }

    /** Chỉ dùng trong test: xoá cache tiến trình sau khi đổi config. */
    public static function flush(): void
    {
        self::$resolved = [];
    }

    /**
     * Khoá thô 32 byte cho AES-256 / HMAC-SHA256.
     *
     * @return array<string, string>
     */
    private static function parseKeyring(string $raw, string $purpose): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || $decoded === []) {
            throw new RuntimeException(
                'PII keyring "'.$purpose.'" phải là JSON dạng {"v1":"base64:..."} và không được rỗng.'
            );
        }

        $keys = [];

        foreach ($decoded as $id => $value) {
            $id = trim((string) $id);
            $value = trim((string) $value);

            if ($id === '' || $value === '') {
                throw new RuntimeException('PII keyring "'.$purpose.'" có phần tử rỗng.');
            }

            $material = str_starts_with($value, 'base64:')
                ? base64_decode(substr($value, 7), true)
                : base64_decode($value, true);

            if ($material === false || strlen($material) !== 32) {
                throw new RuntimeException(
                    'PII key "'.$id.'" của keyring "'.$purpose.'" phải là 32 byte mã hoá base64 '
                    .'(sinh bằng `php artisan pii:make-key`).'
                );
            }

            $keys[$id] = $material;
        }

        return $keys;
    }

    private static function mayDeriveFromAppKey(): bool
    {
        if (! (bool) config('pii.allow_derived_dev_keys', true)) {
            return false;
        }

        $env = strtolower(trim((string) config('app.env', 'production')));

        // Cùng allowlist với config/golive.php: APP_ENV gõ sai bị coi là
        // production và KHÔNG được phép chạy bằng khoá dẫn xuất.
        return in_array($env, (array) config('golive.development_environments', ['local', 'testing']), true);
    }

    /**
     * HKDF từ APP_KEY, salt riêng cho từng mục đích nên khoá mã hoá và khoá
     * blind index của cùng một máy dev vẫn khác nhau. Tất định để dữ liệu dev
     * không mất sau mỗi lần khởi động lại.
     */
    private static function deriveDevKey(string $purpose): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY trống — không dẫn xuất được khoá PII cho môi trường dev.');
        }

        $material = str_starts_with($appKey, 'base64:')
            ? (base64_decode(substr($appKey, 7), true) ?: $appKey)
            : $appKey;

        return hash_hkdf('sha256', $material, 32, 'wms-hoanam-pii-'.$purpose, 'wwhn-sec-06');
    }
}
