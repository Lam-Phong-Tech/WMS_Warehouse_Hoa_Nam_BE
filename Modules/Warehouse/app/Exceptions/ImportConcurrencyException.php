<?php

namespace Modules\Warehouse\Exceptions;

use RuntimeException;

/**
 * WWHN-330 DEV330-P1-07R3 — không giành được khoá tuần tự hoá cho một lần import COMMIT
 * trong thời gian chờ đã duyệt.
 *
 * Vì sao đây là exception chứ không phải "chạy tiếp cho xong":
 *
 * Khoá tồn tại để bước "kiểm tra đã import file này chưa" và bước "ghi SKU + tạo Import
 * báo cáo" là MỘT thao tác nguyên tử. Bỏ khoá rồi chạy tiếp (fail-open) nghĩa là bỏ đúng
 * cái bất biến mà khoá sinh ra để giữ: hai request COMMIT trùng đủ định danh cùng lọt qua
 * bước kiểm tra và tạo hai dòng `import_reports` COMMITTED cho cùng một file — hệ thống đã
 * hứa idempotent nhưng lại thực hiện hai lần, và không ai biết vì response cả hai đều 200.
 *
 * Với một hạng mục Data Integrity, khi không bảo đảm được bất biến thì phải TỪ CHỐI, không
 * được lặng lẽ hạ tiêu chuẩn. Người dùng nhận 409 kèm hướng dẫn thử lại — một thông điệp
 * đúng sự thật và khắc phục được — thay vì một kết quả sai mà không ai phát hiện.
 *
 * Ánh xạ ra API: 409 `CONCURRENCY_CONFLICT` (một trong bốn mã 409 hợp lệ theo §8.2, cùng
 * STATE_CONFLICT / IDEMPOTENCY_CONFLICT / DOWNSTREAM_DEPENDENCY_EXISTS).
 */
class ImportConcurrencyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $waitedSeconds,
    ) {
        parent::__construct($message);
    }
}
