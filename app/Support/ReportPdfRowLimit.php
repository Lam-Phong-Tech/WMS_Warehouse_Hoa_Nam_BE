<?php

namespace App\Support;

/**
 * WWHN-345. Trần dòng của nhánh PDF, dùng chung cho các báo cáo "aligned"
 * (label-log / import-match / warranty-defects) — ba controller này tự dựng bytes trong
 * `exportPdfBytes()` của riêng mình nên KHÔNG đi qua `OperationalReportController::exportPdf()`,
 * tức là không được hàng rào WWHN-347 che.
 *
 * VÌ SAO PHẢI CÓ, đo ngày 09/09/2026 trên đúng bảng 20 cột của RPT-05 "Nhãn đóng gói"
 * (`php -d memory_limit=3G`, dữ liệu thật 06→09/2026):
 *
 *              CSS cũ (border-collapse)      CSS rút gọn (WWHN-347)
 *    200 dòng          172 MB /  2,9 s              130 MB /  2,7 s
 *    500 dòng          572 MB / 11,5 s              286 MB / 10,0 s
 *    800 dòng        1.188 MB / 25,8 s              460 MB / 20,7 s
 *
 * Dompdf dựng một frame cho MỖI ô nên chi phí tăng nhanh hơn tuyến tính. Hợp đồng export
 * chung là 10.000 dòng (BR-EXP-05) — ở mức đó nhánh PDF cần hàng chục GB, tức là không
 * bao giờ đạt được. Trước bản vá, một lần "Xuất PDF" 166 dòng của RPT-05 đã vượt
 * `memory_limit` 128M: PHP fatal "Allowed memory size exhausted" KHÔNG phải Throwable nên
 * `catch (Throwable)` của ReportExportJobRunner không bắt được, tiến trình dựng file chết
 * tại chỗ và bản ghi `report_export_jobs` nằm lại PENDING/PROCESSING với error_code=null —
 * đúng triệu chứng WWHN-345 (TC_FUNC_06). Khi tiến trình đó là container `queue` thì worker
 * chết theo, và MỌI export sau đó cũng treo.
 *
 * Trần đọc từ `wms_export.pdf_row_limit` — CÙNG một khoá, cùng mã lỗi và cùng câu chữ với
 * hàng rào WWHN-347 ở OperationalReportController, để FE chỉ phải hiểu một hợp đồng.
 * Trần 800 dòng chỉ đúng khi bảng dùng CSS rút gọn (460 MB, vừa trong ngân sách 512M của
 * ReportExportMemory); đổi CSS bảng PDF nặng trở lại thì phải đo và hạ trần theo.
 */
final class ReportPdfRowLimit
{
    /**
     * Trần dòng THỰC TẾ của một bảng `$columnCount` cột.
     *
     * WWHN-344 bổ sung: `pdf_row_limit` (800) được hiệu chuẩn trên bảng 17–20 cột, nhưng
     * Dompdf tốn bộ nhớ theo Ô chứ không theo dòng, nên cùng con số 800 áp cho một báo cáo
     * RỘNG HƠN là vượt ngân sách đã đo mà không ai thấy — đúng cách import-match (22 cột)
     * lọt lưới: 800 × 22 = 17.600 ô so với 800 × 20 = 16.000 ô đã đo ở 460 MB.
     *
     * Nên trần thực tế là chặt hơn giữa trần dòng công bố và ngân sách ô
     * (`wms_export.pdf_cell_limit`). Truyền 0 (mặc định) = giữ nguyên hành vi cũ, để các
     * chỗ gọi có sẵn của WWHN-345/347 không đổi.
     */
    public static function limitFor(int $columnCount = 0): int
    {
        $rowLimit = (int) config('wms_export.pdf_row_limit', 800);
        $cellLimit = (int) config('wms_export.pdf_cell_limit', 16000);

        if ($rowLimit <= 0 || $cellLimit <= 0 || $columnCount <= 0) {
            return $rowLimit;
        }

        return max(1, min($rowLimit, intdiv($cellLimit, $columnCount)));
    }

    /**
     * Chặn TRƯỚC khi gọi Dompdf. Vượt trần là lỗi nghiệp vụ 422 có mã, không phải sự cố
     * im lặng: người dùng nhận được đúng con số và lối đi tiếp (thu hẹp bộ lọc / xuất XLSX).
     *
     * @param  list<mixed>  $rows
     * @param  int  $columnCount  Số cột của bảng; 0 = chỉ dùng trần dòng (hành vi cũ).
     */
    public static function assert(array $rows, int $columnCount = 0): void
    {
        $limit = self::limitFor($columnCount);
        if ($limit <= 0 || count($rows) <= $limit) {
            return;
        }

        ApiError::abort(
            422,
            'REPORT_EXPORT_PDF_ROW_LIMIT_EXCEEDED',
            'Bản PDF chỉ dựng được tối đa '.number_format($limit, 0, ',', '.').' dòng '
                .'(kết quả hiện tại '.number_format(count($rows), 0, ',', '.').' dòng). '
                .'Hãy thu hẹp bộ lọc, hoặc chọn Xuất XLSX nếu cần trọn dữ liệu.',
            [
                'limit' => $limit,
                'row_count' => count($rows),
                'suggested_format' => 'xlsx',
            ]
        );
    }
}
