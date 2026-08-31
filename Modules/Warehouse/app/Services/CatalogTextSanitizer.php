<?php

namespace Modules\Warehouse\Services;

/**
 * Chặn barcode/EAN lọt vào text công khai (description/short_description/
 * specifications/package). Nguồn Hand Tools DongCheng có 488/541 dòng chứa
 * "Mã vạch: <13 số>" ngay trong cột "Phụ kiện và gói hàng" — cột này VẪN cần
 * giữ phần "Đóng gói: ..." (quy cách đóng gói là dữ liệu hợp lệ), chỉ riêng
 * mã vạch là phải biến mất trước khi ghi DB.
 *
 * "Không tin client đã tự lọc" (yêu cầu P0 #5): sanitize chạy TRONG
 * PvCatalogImportService, không phải ở tầng ETL nạp Excel — một client khác
 * gọi thẳng endpoint import với text thô vẫn phải bị lọc như nhau.
 */
final class CatalogTextSanitizer
{
    /**
     * Nhãn mã vạch tiếng Việt/Anh đi kèm một chuỗi số — loại luôn cả nhãn lẫn số.
     */
    private const LABELED_PATTERN = '/\b(?:Mã\s*vạch|Ma\s*vach|barcode|EAN(?:-?13)?|GTIN|UPC)\s*[:=]?\s*[\d][\d\-\s]{5,20}\d/iu';

    /**
     * Chuỗi số trần 8–14 chữ số (EAN-8/UPC-12/EAN-13/GTIN-14) không có nhãn đi
     * kèm — vẫn là mã vạch, chỉ là OCR/nhập liệu không giữ lại nhãn.
     */
    private const BARE_DIGIT_RUN = '/(?<![\d.])\d{8,14}(?![\d.])/u';

    private function __construct() {}

    public static function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = preg_replace(self::LABELED_PATTERN, '', $text);
        $clean = preg_replace(self::BARE_DIGIT_RUN, '', (string) $clean);
        $clean = self::tidyPunctuation((string) $clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * True nếu text gốc có chứa mẫu mã vạch (dùng để đánh dấu "đã sanitize" cho
     * audit/dry-run — bản thân việc lọc luôn chạy vô điều kiện).
     */
    public static function containsBarcode(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        return preg_match(self::LABELED_PATTERN, $text) === 1 || preg_match(self::BARE_DIGIT_RUN, $text) === 1;
    }

    /**
     * Sanitize đệ quy mọi giá trị chuỗi trong specifications (object/array lồng
     * nhau tự do) — key vẫn giữ nguyên, chỉ value là free text mới cần lọc.
     *
     * @param  array<string, mixed>|null  $specifications
     * @return array<string, mixed>|null
     */
    public static function sanitizeArray(?array $specifications): ?array
    {
        if ($specifications === null) {
            return null;
        }

        $result = [];
        foreach ($specifications as $key => $value) {
            if (is_string($value)) {
                $result[$key] = self::sanitize($value);

                continue;
            }
            if (is_array($value)) {
                $result[$key] = self::sanitizeArray($value);

                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private static function tidyPunctuation(string $text): string
    {
        // Xoá dấu ";"/"," mồ côi do đã cắt mất phần mã vạch phía sau, gộp
        // khoảng trắng thừa, rồi trim hai đầu.
        $text = preg_replace('/\s*[;,]\s*(?=[;,]|$)/u', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B;,");

        return trim($text);
    }
}
