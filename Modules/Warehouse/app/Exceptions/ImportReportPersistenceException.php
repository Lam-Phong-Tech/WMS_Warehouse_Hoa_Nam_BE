<?php

namespace Modules\Warehouse\Exceptions;

use RuntimeException;

/**
 * DEV224-P1-05R — không ghi được Import báo cáo (`import_reports`).
 *
 * Import báo cáo là OUTPUT BẮT BUỘC của WWHN-224, không phải metadata phụ trợ: nó là
 * nguồn duy nhất để tra cứu lại một lần chạy (dry-run lẫn commit), là chỗ neo file lỗi
 * CSV, và là bảng mà cơ chế idempotent đọc để nhận diện replay. Nuốt lỗi ghi báo cáo rồi
 * vẫn trả 200 (hành vi trước đây) tạo ra ba hệ quả cùng lúc: mất truy vết, `import_report_id`
 * null trong một response "thành công", và — nặng nhất — cửa sổ idempotent bị mù, nên lần
 * gửi lại của chính người dùng sẽ import lần hai trên dữ liệu đã ghi đúng.
 *
 * Vì vậy lỗi này được ném ra TRONG cùng transaction với phần ghi SKU/audit: transaction
 * rollback, controller trả 503 `SKU_IMPORT_REPORT_PERSISTENCE_FAILED` với
 * `partial_write=false`. Không có trạng thái nào ở giữa.
 */
final class ImportReportPersistenceException extends RuntimeException {}
