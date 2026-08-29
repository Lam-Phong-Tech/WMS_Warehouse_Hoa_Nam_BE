<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Token phân trang ĐỤC (opaque) cho nhóm `/api/v1/public/*`.
 *
 * Hợp đồng FE: "cursor là token opaque do backend phát hành". Ở đây "opaque"
 * được hiện thực đúng nghĩa chứ không phải base64 của offset:
 *
 * - Nội dung là keyset (giá trị của các cột ORDER BY ở bản ghi cuối trang), nên
 *   phân trang KHÔNG lệch khi có sản phẩm được publish/unpublish giữa hai trang —
 *   thứ mà OFFSET không làm được.
 * - Token được ký HMAC-SHA256 bằng APP_KEY. Client sửa một byte là token hỏng và
 *   bị từ chối, thay vì lặng lẽ trả về trang sai.
 * - Token gắn chặt với (sort + tập filter). Đổi `sort` hay `q` mà vẫn gửi cursor
 *   cũ sẽ bị từ chối, vì trang tiếp theo của một thứ tự khác là vô nghĩa.
 *
 * Token KHÔNG mã hoá, chỉ ký — giá trị bên trong (tên sản phẩm, thời điểm cập
 * nhật) đều đã public qua chính response, nên không có gì để giấu.
 */
final class PublicApiCursor
{
    /** Đổi khi cấu trúc payload đổi, để token cũ bị từ chối thay vì hiểu sai. */
    private const VERSION = 1;

    private function __construct() {}

    /**
     * @param  array<int, mixed>  $keys  giá trị các cột ORDER BY của bản ghi cuối trang
     */
    public static function encode(string $sort, string $fingerprint, array $keys): string
    {
        $payload = [
            'v' => self::VERSION,
            's' => $sort,
            'f' => $fingerprint,
            'k' => array_values($keys),
        ];

        $body = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $body.'.'.self::base64UrlEncode(self::signature($body));
    }

    /**
     * @return array<int, mixed>|null null nghĩa là "không có cursor" (trang đầu)
     *
     * @throws \InvalidArgumentException khi token sai chữ ký, sai định dạng hoặc
     *                                   không thuộc về (sort, filter) hiện tại
     */
    public static function decode(?string $cursor, string $sort, string $fingerprint): ?array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') {
            return null;
        }

        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('cursor không đúng định dạng.');
        }

        [$body, $signature] = $parts;

        // hash_equals: so sánh chữ ký phải theo thời gian hằng số, tránh rò rỉ
        // từng byte chữ ký qua timing.
        if (! hash_equals(self::signature($body), self::base64UrlDecode($signature))) {
            throw new \InvalidArgumentException('cursor không hợp lệ hoặc đã bị sửa đổi.');
        }

        $decoded = json_decode(self::base64UrlDecode($body), true);
        if (! is_array($decoded) || ! isset($decoded['k']) || ! is_array($decoded['k'])) {
            // Chữ ký đúng mà nội dung hỏng nghĩa là APP_KEY bị dùng để ký một
            // payload lạ — đáng ghi log, không chỉ trả lỗi cho client.
            Log::warning('PublicApiCursor: payload hợp lệ chữ ký nhưng sai cấu trúc.');

            throw new \InvalidArgumentException('cursor không hợp lệ.');
        }

        if (($decoded['v'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('cursor đã hết hiệu lực, vui lòng tải lại danh sách.');
        }

        if (($decoded['s'] ?? null) !== $sort) {
            throw new \InvalidArgumentException('cursor không khớp với tham số sort hiện tại.');
        }

        if (($decoded['f'] ?? null) !== $fingerprint) {
            throw new \InvalidArgumentException('cursor không khớp với bộ lọc hiện tại.');
        }

        return array_values($decoded['k']);
    }

    /**
     * Vân tay của tập filter. Cùng một bộ lọc phải ra cùng một chuỗi bất kể thứ
     * tự tham số client gửi lên, nên phải sắp xếp trước khi băm.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fingerprint(array $filters): string
    {
        $normalized = self::normalizeForFingerprint($filters);

        return substr(hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function normalizeForFingerprint(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = array_values($value);
                sort($value);
            }
            $normalized[$key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private static function signature(string $body): string
    {
        return hash_hmac('sha256', self::VERSION.'|'.$body, (string) config('app.key'), true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }
}
