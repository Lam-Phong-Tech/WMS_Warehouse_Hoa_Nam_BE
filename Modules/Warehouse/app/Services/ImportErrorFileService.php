<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Support\SkuImportContract;

/**
 * WWHN-330 (DEV330-P1-01) — sinh file lỗi CSV thật cho các dòng bị reject khi import,
 * để người dùng tải về đối soát thay vì chỉ đọc JSON response tức thời.
 *
 * WWHN-331 FE331-P1-03R3: hạn dùng của file lỗi không còn là hằng số "tạm" trong lớp
 * này mà đọc từ hợp đồng đã chốt (`warehouse.sku_import.error_file_expiry_hours`,
 * DEC-IMP-001) — cùng nguồn với giá trị API trả ra trong `constraints` và với giá trị
 * UI hiển thị, nên ba nơi không thể nói khác nhau.
 */
class ImportErrorFileService
{
    public const DISK = 'local';

    public const STORAGE_SUBDIR = 'import-error-files';

    /**
     * @deprecated WWHN-331 FE331-P1-03R3 — dùng ImportErrorFileService::expiryHours().
     *             Hằng số chỉ còn để không phá tham chiếu cũ; mọi phép tính hạn dùng
     *             thật đã chuyển sang hợp đồng config đã duyệt.
     */
    public const EXPIRY_HOURS = 24;

    /** Số giờ file lỗi còn hiệu lực trước khi bị coi là hết hạn (404 khi tải). */
    public static function expiryHours(): int
    {
        return SkuImportContract::errorFileExpiryHours();
    }

    /**
     * Sinh file CSV lỗi từ danh sách row_errors, đã sanitize giá trị để tránh CSV/formula
     * injection và giới hạn độ dài field không lộ dữ liệu ngoài phạm vi cần thiết.
     *
     * @param  array<int, array{row:int,code:string,field:string,message:string,value:mixed}>  $rowErrors
     * @return array{path: string, expires_at: Carbon}|null null nếu không có lỗi nào để ghi
     */
    public function generate(string $reportId, array $rowErrors): ?array
    {
        if ($rowErrors === []) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $relativePath = self::STORAGE_SUBDIR.'/'.$reportId.'.csv';

        $handle = fopen('php://temp', 'w+');
        // UTF-8 BOM để Excel mở tiếng Việt không bị lỗi font.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['row', 'field', 'error_code', 'message', 'value']);

        foreach ($rowErrors as $error) {
            fputcsv($handle, [
                (string) ($error['row'] ?? ''),
                $this->sanitizeCell((string) ($error['field'] ?? '')),
                $this->sanitizeCell((string) ($error['code'] ?? '')),
                $this->sanitizeCell((string) ($error['message'] ?? '')),
                $this->sanitizeCell($this->stringifyValue($error['value'] ?? null)),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $disk->put($relativePath, $csv);

        return [
            'path' => $relativePath,
            'expires_at' => Carbon::now()->addHours(self::expiryHours()),
        ];
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sanitize: cắt độ dài (tránh lộ payload quá lớn/ngoài phạm vi), bỏ ký tự điều khiển,
     * và chặn CSV formula injection (giá trị bắt đầu bằng =, +, -, @, tab, CR).
     */
    private function sanitizeCell(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        $value = mb_substr($value, 0, 500);

        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'".$value;
        }

        return $value;
    }
}
