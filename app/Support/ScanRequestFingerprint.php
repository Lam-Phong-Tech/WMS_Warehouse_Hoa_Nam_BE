<?php

namespace App\Support;

/**
 * WWHN-188 / DEV188-P1-03R2 — hiện thực DEC-SCAN-IDEMP-001.
 *
 * Fingerprint tất định (deterministic) cho một *request scan*, dùng làm
 * `request_hash` của {@see IdempotencyStore} thay cho
 * {@see IdempotencyStore::requestHash()}.
 *
 * Vì sao không dùng thẳng `IdempotencyStore::requestHash()`:
 *
 * 1. Contract scan nhận cả `qty` lẫn `quantity` (alias Annex C vs legacy). Hash
 *    thô trên `$request->all()` sẽ coi `{"qty":2}` và `{"quantity":2}` là hai
 *    payload khác nhau → retry hợp lệ bị trả 409 IDEMPOTENCY_CONFLICT sai.
 * 2. Hash thô còn nuốt cả `printer_config_id` — đây là gợi ý thiết bị in nhãn,
 *    không phải nội dung được ghi nhận vào `scanned_qty`; đổi máy in giữa hai
 *    lần retry không được biến thành xung đột payload.
 * 3. Fingerprint **cố ý không chứa** version (`If-Match`) và không chứa mốc thời
 *    gian. Đây chính là điểm reviewer yêu cầu ("deterministic fingerprint thay vì
 *    time-window heuristic"): một retry sau khi client re-fetch document (version
 *    đã nhảy 1 → 2) vẫn phải cho ra đúng fingerprint cũ thì mới nhận diện được
 *    "đúng hành động này đã chạy rồi" và replay thay vì cộng dồn lần hai.
 *
 * Danh tính request đến từ `Idempotency-Key` do client cấp — mỗi *hành động quét*
 * của người dùng sinh một key, và mọi lần gửi lại của đúng hành động đó dùng lại
 * đúng key. Nhờ vậy hai lần quét hợp lệ liên tiếp cùng nội dung (đúng nghiệp vụ
 * COMPONENT quantity-mode) vẫn là hai key khác nhau và vẫn được cộng dồn.
 */
final class ScanRequestFingerprint
{
    /** Đổi prefix khi đổi tập trường canonical để hash cũ không bị so nhầm. */
    public const VERSION = 'scanfp1';

    /**
     * Các trường thuộc "nội dung được ghi nhận" của một scan. Thứ tự cố định,
     * không phụ thuộc thứ tự key client gửi lên.
     *
     * @var list<string>
     */
    private const SEMANTIC_FIELDS = ['line_id', 'quantity', 'code_value', 'item_id', 'container_id'];

    /**
     * @param  array<string,mixed>  $payload  payload đã qua validate của scan
     */
    public static function for(string $operation, string $documentId, array $payload): string
    {
        $canonical = [
            'fp' => self::VERSION,
            'operation' => $operation,
            'document_id' => $documentId,
        ];

        foreach (self::SEMANTIC_FIELDS as $field) {
            $canonical[$field] = $field === 'quantity'
                ? (int) ($payload[$field] ?? 0)
                : self::normalizeNullableString($payload[$field] ?? null);
        }

        return self::VERSION.':'.hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
