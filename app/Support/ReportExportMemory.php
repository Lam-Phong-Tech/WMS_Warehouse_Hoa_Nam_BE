<?php

namespace App\Support;

/**
 * WWHN-347. Trần bộ nhớ dành riêng cho lúc dựng file export.
 *
 * VÌ SAO CẦN: base image PHP của dự án để `memory_limit=128M` (chỉ perf overlay nâng lên
 * 512M). Đo ngày 09/09/2026 trên stack dev, một file XLSX `document-history` 4.243 dòng ×
 * 17 cột đã cần 130 MB — tức là VƯỢT trần ngay ở một kỳ báo cáo bình thường, dù còn xa
 * hợp đồng 10.000 dòng mà API công bố.
 *
 * Hậu quả không phải là "lỗi 500 rồi thôi": PHP fatal `Allowed memory size exhausted`
 * KHÔNG phải Throwable nên `catch (Throwable)` trong ReportExportJobRunner::run() không
 * bắt được, tiến trình worker chết ngay tại chỗ (container `queue` thoát 139/255) và bản
 * ghi `report_export_jobs` đứng nguyên ở PENDING/PROCESSING với error_code=null. Đúng
 * triệu chứng WWHN-347: job treo, không FAILED, không thông báo lỗi.
 *
 * Nâng trần ở ĐÂY thay vì sửa php.ini vì:
 *   - php.ini là toàn cục: nâng cho mọi request web chỉ để phục vụ export là nới rộng
 *     bề mặt sự cố (một vòng lặp hỏng ở endpoint khác cũng được phép ăn 512 MB);
 *   - trần phải đi theo mã nguồn và có số đo kèm theo, không phụ thuộc việc môi trường
 *     nào đó có nạp đúng overlay hay không.
 */
class ReportExportMemory
{
    /**
     * Nâng `memory_limit` của tiến trình hiện tại lên mức cấu hình, nếu đang thấp hơn.
     *
     * CHỈ NÂNG, KHÔNG BAO GIỜ HẠ: hàm này còn được gọi từ trong một request HTTP thật
     * (đường cứu hộ inline của WWHN-343) và từ CLI test chạy với trần rất cao — hạ trần
     * xuống 512M ở đó sẽ giết những tiến trình vốn đang chạy bình thường.
     *
     * Cũng KHÔNG khôi phục trần cũ sau khi xong: phần đời còn lại của request/worker
     * được phép dùng trần cao hơn là vô hại, còn hạ trần trong lúc bộ nhớ đang giữ ở mức
     * cao lại làm PHP fatal ngay lập tức.
     *
     * @return bool `true` nếu đã thực sự nâng trần.
     */
    public static function raise(): bool
    {
        $target = (string) config('wms_export.memory_limit', '512M');
        if (trim($target) === '') {
            return false;
        }

        $targetBytes = self::toBytes($target);
        $currentBytes = self::toBytes((string) ini_get('memory_limit'));

        // -1 = không giới hạn: đã rộng hơn mọi trần hữu hạn, đụng vào là siết lại.
        if ($currentBytes === -1 || $targetBytes <= 0 || $targetBytes <= $currentBytes) {
            return false;
        }

        return ini_set('memory_limit', $target) !== false;
    }

    /** Đổi giá trị kiểu php.ini ("512M", "1G", "-1") sang byte; -1 giữ nguyên nghĩa vô hạn. */
    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
