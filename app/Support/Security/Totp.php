<?php

namespace App\Support\Security;

use InvalidArgumentException;

/**
 * WWHN-SEC-08 — TOTP theo RFC 6238 (dựa trên HOTP, RFC 4226).
 *
 * VÌ SAO TỰ CÀI THAY VÌ THÊM THƯ VIỆN
 *
 * Nguyên tắc "không tự chế thuật toán mật mã" nhắm vào việc TỰ NGHĨ RA phép mã
 * hoá. TOTP không phải thứ được nghĩ ra ở đây: nó là một công thức 6 dòng trên
 * `hash_hmac('sha1', ...)` được RFC 6238 đặc tả từng bit, và cách chứng minh một
 * bản cài đúng là chạy chính BỘ VECTOR KIỂM THỬ của RFC — đã làm ở
 * `tests/Feature/WwhnSec08MfaTest::test_khop_vector_kiem_thu_cua_rfc_6238`.
 *
 * Đổi lại, thêm một dependency composer vào lúc này nghĩa là: sửa
 * composer.json/lock, dựng lại volume vendor của cả 4 container, và mở rộng bề
 * mặt chuỗi cung ứng cho đúng 40 dòng mã. Đánh đổi không đáng.
 *
 * PHẦN KHÔNG ĐƯỢC TỰ CHẾ vẫn không tự chế: bí mật TOTP sinh bằng
 * `random_bytes()` và được LƯU dưới lớp AEAD của PiiCrypto.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Bí mật 20 byte = 160 bit, đúng khuyến nghị của RFC 4226 §4 R6. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Mã tại một thời điểm.
     *
     * @param  string  $secret  base32, không phân biệt hoa thường, cho phép dấu '='
     */
    public static function code(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6, string $algorithm = 'sha1'): string
    {
        $counter = intdiv($timestamp ?? time(), max(1, $period));

        return self::hotp(self::base32Decode($secret), $counter, $digits, $algorithm);
    }

    /**
     * Xác minh mã người dùng nhập.
     *
     * `$window` là số BƯỚC THỜI GIAN chấp nhận lệch về hai phía. RFC 6238 §5.2
     * nói rõ vì sao cần: đồng hồ điện thoại và server không bao giờ khớp tuyệt
     * đối, và người dùng mất vài giây để gõ. window=1 (±30 giây) là mặc định cân
     * bằng; đặt 0 sẽ tạo ra lỗi "mã đúng vẫn báo sai" không thể debug từ phía hỗ
     * trợ, đặt quá lớn thì nới cửa sổ tấn công tương ứng.
     *
     * So sánh bằng `hash_equals` — so bằng `===` để lộ độ dài tiền tố trùng qua
     * thời gian thực thi.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $period = 30, int $window = 1, int $digits = 6, string $algorithm = 'sha1'): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.$digits.'}$/', $code)) {
            return false;
        }

        $now = $timestamp ?? time();
        $binarySecret = self::base32Decode($secret);
        $counter = intdiv($now, max(1, $period));
        $valid = false;

        // KHÔNG return sớm khi khớp: thoát ngay ở bước đầu tiên làm thời gian
        // thực thi phụ thuộc vị trí bước khớp, tức là rò rỉ độ lệch đồng hồ của
        // thiết bị. Duyệt hết cửa sổ rồi mới trả kết quả.
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = self::hotp($binarySecret, $counter + $offset, $digits, $algorithm);
            $valid = hash_equals($candidate, $code) || $valid;
        }

        return $valid;
    }

    /**
     * URI `otpauth://` để dựng QR cho Google Authenticator / Microsoft
     * Authenticator / 1Password.
     */
    public static function provisioningUri(string $secret, string $accountName, string $issuer, int $period = 30, int $digits = 6, string $algorithm = 'sha1'): string
    {
        // Label theo đặc tả Key Uri Format: "Issuer:account", cả hai đều phải
        // được encode; issuer lặp lại ở query để app cũ không đoán sai.
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($algorithm),
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** HOTP — RFC 4226 §5.3 (dynamic truncation). */
    private static function hotp(string $binarySecret, int $counter, int $digits, string $algorithm): string
    {
        if ($binarySecret === '') {
            throw new InvalidArgumentException('Bí mật TOTP rỗng.');
        }

        // Counter là số nguyên 64-bit big-endian.
        $binaryCounter = pack('J', max(0, $counter));
        $hash = hash_hmac($algorithm, $binaryCounter, $binarySecret, true);

        // 4 bit thấp của byte cuối chỉ ra offset bắt đầu lấy 4 byte.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // Không thêm padding '=': mọi app xác thực đều chấp nhận chuỗi không
        // padding, và chuỗi ngắn hơn thì người dùng gõ tay dễ hơn.
        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');

        if ($encoded === '') {
            throw new InvalidArgumentException('Chuỗi base32 không hợp lệ.');
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                throw new InvalidArgumentException('Ký tự base32 không hợp lệ.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }
}
