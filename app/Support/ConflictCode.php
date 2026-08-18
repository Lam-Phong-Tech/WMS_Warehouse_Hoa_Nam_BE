<?php

namespace App\Support;

/**
 * WWHN-234 (DEV234-P1-02) — nguồn duy nhất của bốn lớp xung đột 409 trong SRS §8.2.
 *
 * §8.2 quy định 409 = "STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT /
 * DOWNSTREAM_DEPENDENCY_EXISTS". Bốn mã này KHÔNG phải "ví dụ tuỳ chọn": chúng là bốn
 * *lớp* xung đột mà client phải phân biệt được để chọn hành vi:
 *
 * | Lớp                          | Client phải làm gì                                          |
 * |------------------------------|-------------------------------------------------------------|
 * | STATE_CONFLICT               | Dừng. Trạng thái nghiệp vụ không cho phép action này.        |
 * | IDEMPOTENCY_CONFLICT         | Dừng. Sinh Idempotency-Key mới cho payload mới.              |
 * | CONCURRENCY_CONFLICT         | Reload state rồi thử lại — KHÔNG đổi key, KHÔNG auto-submit. |
 * | DOWNSTREAM_DEPENDENCY_EXISTS | Đảo dependency theo thứ tự ngược trước (BR-REV-04).          |
 *
 * Lỗi gốc mà WWHN-234 tìm ra: cùng một tình huống nghiệp vụ lại phát hai mã khác nhau
 * tuỳ đường code đi qua —
 *   - mất CAS trên `inventory_balances` trả `VERSION_CONFLICT` (3 chỗ) trong khi
 *     `IdempotencyStore` trả `CONCURRENCY_CONFLICT` cho đúng lớp đó;
 *   - "phải đảo dependency theo thứ tự ngược" trả `DOWNSTREAM_DEPENDENCY_EXISTS` ở
 *     nhánh quantity nhưng `REVERSAL_DEPENDENCY_EXISTS` ở nhánh item — hai nhánh nằm
 *     trong *cùng một file*, cùng một câu văn tiếng Việt.
 * FE không map hai mã lệch đó nên request thua race chỉ hiện lỗi chung, trái
 * AC-GWT-CONC-001 ("UI request thua hiển thị refresh/retry, không tự submit lại bằng
 * key mới").
 *
 * Mã miền cụ thể hơn (`ITEM_ALREADY_MATCHED`, `CONTAINER_CLOSED`, …) vẫn được phép:
 * chúng là biến thể chi tiết của lớp `STATE_CONFLICT`. Ràng buộc duy nhất — và là thứ
 * `scripts/verify-wwhn234-closure.mjs` canh — là **lớp concurrency và lớp
 * downstream-dependency phải dùng đúng hằng ở đây**, vì hai lớp đó quyết định client
 * có được retry hay không.
 *
 * @see docs/evidence/WWHN-234/CONCURRENCY_STATE_REVIEW.md §7
 */
final class ConflictCode
{
    /** Trạng thái nghiệp vụ không cho phép action. Client dừng. */
    public const STATE = 'STATE_CONFLICT';

    /** Cùng Idempotency-Key, khác payload hash. Client phải sinh key mới. */
    public const IDEMPOTENCY = 'IDEMPOTENCY_CONFLICT';

    /**
     * Thua race: mất CAS version, lease đang chạy, hoặc bản ghi bị request khác đổi
     * giữa lúc đọc và ghi. Client reload rồi thử lại với **cùng** Idempotency-Key.
     */
    public const CONCURRENCY = 'CONCURRENCY_CONFLICT';

    /** BR-REV-04/TC-REV-02: còn giao dịch phụ thuộc phía sau, phải đảo ngược thứ tự. */
    public const DOWNSTREAM_DEPENDENCY = 'DOWNSTREAM_DEPENDENCY_EXISTS';

    /**
     * Bốn lớp của §8.2, dùng cho verifier và cho test contract.
     *
     * @return list<string>
     */
    public static function canonicalSet(): array
    {
        return [self::STATE, self::IDEMPOTENCY, self::CONCURRENCY, self::DOWNSTREAM_DEPENDENCY];
    }
}
