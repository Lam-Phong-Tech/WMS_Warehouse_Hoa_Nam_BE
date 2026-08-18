<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\Log;
use Modules\Warehouse\Exceptions\ImportReportPersistenceException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Support\SkuImportContract;
use Throwable;

/**
 * DEV224-P1-05R — nơi DUY NHẤT ghi `import_reports`, và là nơi giữ bất biến
 * "Import báo cáo là output bắt buộc".
 *
 * Vì sao tách hẳn một lớp thay vì để nguyên hàm private trong controller như trước:
 *
 * 1. **Đúng ranh giới transaction.** Trước đây `ImportController::persistReport()` chạy
 *    SAU khi `ImportService` đã commit transaction ghi SKU, nên hai việc "ghi SKU" và
 *    "ghi báo cáo" không bao giờ nguyên tử được — ghi báo cáo hỏng thì SKU đã nằm trong
 *    DB rồi, không còn gì để rollback. Đưa việc ghi vào một collaborator do
 *    `ImportService` gọi TRONG transaction làm hai việc đó thành một đơn vị nguyên tử.
 * 2. **Bơm lỗi kiểm chứng được.** Register đòi failure injection chứng minh 503 + không
 *    ghi dở. Có seam này thì test bind một bản giả ném lỗi ĐÚNG lúc SKU vừa được ghi
 *    xong trong transaction — tức đúng kịch bản nguy hiểm nhất — thay vì phải phá bảng
 *    và chỉ chứng minh được một nhánh lỗi sớm.
 * 3. **Một chỗ giữ chính sách log.** Thông điệp lỗi gốc của DB có thể chứa giá trị ô dữ
 *    liệu; log ở đây chỉ ghi định danh kỹ thuật (request/checksum/mode/lớp exception),
 *    không ghi nội dung dòng — SRS §13.8 cấm đẩy dữ liệu nghiệp vụ thô vào application log.
 */
class ImportReportWriter
{
    /**
     * Ghi một Import báo cáo cho lần chạy vừa xong. Ném
     * `ImportReportPersistenceException` nếu không ghi được — KHÔNG trả null, vì null
     * chính là cách hành vi cũ biến một sự cố toàn vẹn dữ liệu thành một response
     * thành công thiếu output.
     *
     * @param  array<string, mixed>  $result  kết quả thô của ImportService (chưa gắn contract)
     */
    public function write(
        array $result,
        ?string $actorId,
        ?string $requestId,
        ?string $fileName,
        bool $updateExisting,
    ): ImportReport {
        $dryRun = (bool) ($result['dry_run'] ?? false);
        $hasErrors = ($result['row_errors'] ?? []) !== [];
        $status = match (true) {
            $hasErrors => 'REJECTED',
            $dryRun => 'VALID',
            default => 'COMMITTED',
        };

        try {
            return ImportReport::query()->create([
                'import_type' => 'SKU',
                'mode' => $dryRun ? 'DRY_RUN' : 'COMMIT',
                'status' => $status,
                'file_name' => $fileName,
                'file_sha256' => $result['file_sha256'] ?? null,
                'template_version' => $result['template_version'] ?? null,
                'total_rows' => $result['total_rows'] ?? 0,
                'valid_rows' => $result['valid_rows'] ?? 0,
                'error_rows' => $result['error_rows'] ?? 0,
                'inserted_rows' => $dryRun ? ($result['would_insert_rows'] ?? 0) : ($result['inserted_rows'] ?? 0),
                'updated_rows' => $dryRun ? ($result['would_update_rows'] ?? 0) : ($result['updated_rows'] ?? 0),
                'row_errors' => $result['row_errors'] ?? [],
                'actor_id' => $actorId,
                'request_id' => $requestId,
                // WWHN-331 FE331-P1-02R3: lưu vân tay tùy chọn để lần nộp sau so được
                // ĐỦ định danh (file + actor + tùy chọn), không chỉ trùng nội dung file.
                'options_fingerprint' => SkuImportContract::optionsFingerprint($updateExisting),
            ]);
        } catch (Throwable $e) {
            // Tên sự kiện log mang hậu tố _ROLLBACK để phân biệt dứt khoát với hành vi
            // cũ (`IMPORT_REPORT_WRITE_FAILED` = đã nuốt lỗi, request vẫn 200). Ai đọc
            // log production sẽ thấy ngay đây là một lần import KHÔNG để lại dữ liệu.
            Log::error('IMPORT_REPORT_WRITE_FAILED_ROLLBACK', [
                'request_id' => $requestId,
                'file_sha256' => $result['file_sha256'] ?? null,
                'mode' => $dryRun ? 'DRY_RUN' : 'COMMIT',
                'status' => $status,
                'exception' => $e::class,
            ]);

            throw new ImportReportPersistenceException(
                'Không ghi được Import báo cáo; giao dịch import phải rollback.',
                previous: $e,
            );
        }
    }
}
