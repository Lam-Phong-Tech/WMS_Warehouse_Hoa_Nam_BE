<?php

namespace App\Support\Security;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Throwable;

/**
 * WWHN-SEC-06 — mã hoá PII có xác thực, gắn phiên bản khoá.
 *
 * KHÔNG TỰ CHẾ THUẬT TOÁN. Lớp này chỉ làm hai việc mà thư viện không làm hộ:
 * chọn đúng khoá theo `key_id` nhúng trong ciphertext, và ràng buộc ciphertext
 * vào ĐÚNG cột nó thuộc về (AAD).
 *
 * VÌ SAO CẦN AAD (`context`):
 * Nếu không ràng buộc, ciphertext của `customer_phone` có thể được chép sang ô
 * `customer_name` của một hồ sơ khác và vẫn giải mã sạch sẽ — kẻ tấn công có
 * quyền GHI vào DB nhưng không có khoá vẫn hoán vị được dữ liệu giữa các hồ sơ.
 * Truyền `context` = "bảng.cột" làm additional authenticated data khiến việc
 * chép chéo đó hỏng tag xác thực và ném lỗi.
 *
 * ĐỊNH DẠNG LƯU: "<key_id>$<payload base64 của Laravel Encrypter>".
 * Ký tự "$" không xuất hiện trong base64 nên tách luôn không nhập nhằng.
 *
 * `Encrypter` được cache theo key_id trong tiến trình: khởi tạo lại mỗi lần gọi
 * sẽ biến một cột mã hoá thành chi phí có thể đo được trên trang danh sách.
 */
final class PiiCrypto
{
    private const SEPARATOR = '$';

    /** @var array<string, Encrypter> */
    private static array $encrypters = [];

    /**
     * @param  string  $context  "bảng.cột" — ràng buộc ciphertext vào đúng ô của nó.
     */
    public static function encrypt(?string $plaintext, string $context): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            // Chuỗi rỗng KHÔNG được mã hoá thành ciphertext: "chưa có dữ liệu"
            // phải nhìn thấy được bằng mắt trên DB, và mã hoá nó chỉ tạo ra một
            // giá trị khác rỗng làm mọi truy vấn `IS NULL` nói dối.
            return null;
        }

        $keyId = PiiKeyring::activeKeyId(PiiKeyring::PURPOSE_ENCRYPTION);

        return $keyId.self::SEPARATOR.self::encrypterFor($keyId)->encryptString(
            self::bind($plaintext, $context)
        );
    }

    public static function decrypt(?string $stored, string $context): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        [$keyId, $payload] = self::split($stored);

        $key = PiiKeyring::keyById(PiiKeyring::PURPOSE_ENCRYPTION, $keyId);

        if ($key === null) {
            throw new RuntimeException(
                'Không giải mã được PII: key id "'.$keyId.'" không còn trong keyring. '
                .'Khoá cũ chỉ được gỡ SAU KHI `pii:reencrypt` chạy xong toàn bộ.'
            );
        }

        try {
            $bound = self::encrypterFor($keyId)->decryptString($payload);
        } catch (Throwable $error) {
            // Fail-closed: ciphertext hỏng/bị sửa là sự cố toàn vẹn, không phải
            // "không có dữ liệu". Trả null ở đây sẽ giấu mất việc DB bị can thiệp.
            throw new RuntimeException('Giải mã PII thất bại (ciphertext hỏng, sai khoá, hoặc bị sửa).', 0, $error);
        }

        return self::unbind($bound, $context);
    }

    /**
     * Giá trị này ĐÃ là ciphertext của lớp này chưa? Dùng cho backfill idempotent
     * và cho giai đoạn đọc song song (dual-read) khi cột còn lẫn hai dạng.
     */
    public static function looksEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $position = strpos($value, self::SEPARATOR);

        if ($position === false || $position === 0) {
            return false;
        }

        $keyId = substr($value, 0, $position);

        return preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $keyId) === 1
            && PiiKeyring::keyById(PiiKeyring::PURPOSE_ENCRYPTION, $keyId) !== null;
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $stored): array
    {
        $position = strpos($stored, self::SEPARATOR);

        if ($position === false || $position === 0) {
            throw new RuntimeException('Ciphertext PII sai định dạng: thiếu tiền tố key id.');
        }

        return [substr($stored, 0, $position), substr($stored, $position + 1)];
    }

    /**
     * Laravel Encrypter không nhận AAD trực tiếp, nên context được ghép vào bản
     * rõ trước khi mã hoá. Hiệu quả bảo vệ tương đương: tag GCM phủ cả context,
     * nên chép ciphertext sang cột khác sẽ lộ ra ở bước kiểm context sau giải mã.
     */
    private static function bind(string $plaintext, string $context): string
    {
        return $context."\0".$plaintext;
    }

    private static function unbind(string $bound, string $context): string
    {
        $prefix = $context."\0";

        if (! str_starts_with($bound, $prefix)) {
            throw new RuntimeException(
                'Ciphertext PII không thuộc về cột này (context không khớp) — dấu hiệu dữ liệu bị chép chéo giữa các cột/hồ sơ.'
            );
        }

        return substr($bound, strlen($prefix));
    }

    private static function encrypterFor(string $keyId): Encrypter
    {
        if (isset(self::$encrypters[$keyId])) {
            return self::$encrypters[$keyId];
        }

        $key = PiiKeyring::keyById(PiiKeyring::PURPOSE_ENCRYPTION, $keyId);

        if ($key === null) {
            throw new RuntimeException('PII key id "'.$keyId.'" không có trong keyring.');
        }

        return self::$encrypters[$keyId] = new Encrypter($key, (string) config('pii.cipher', 'aes-256-gcm'));
    }

    /** Chỉ dùng trong test. */
    public static function flush(): void
    {
        self::$encrypters = [];
    }
}
