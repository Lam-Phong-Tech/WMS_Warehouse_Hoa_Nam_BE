<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Support\SkuImportContract;

/**
 * WWHN-330 (DEV330-P1-01) — sinh file lỗi CSV thật cho các dòng bị reject khi import,
 * để người dùng tải về đối soát thay vì chỉ đọc JSON response tức thời.
 *
 * DEV330-P1-01R3 — hai thay đổi về hợp đồng:
 *
 * 1. **Hạn dùng đọc từ hợp đồng đã chốt** (`warehouse.sku_import.error_file_expiry_hours`,
 *    DEC-IMP-001 §5) — cùng nguồn với giá trị API trả ra trong `constraints` và với giá
 *    trị UI hiển thị, nên ba nơi không thể nói khác nhau. Hằng số `EXPIRY_HOURS = 24`
 *    kèm ghi chú "TẠM" đã bị gỡ hẳn: nó không còn được đọc ở đâu, và để lại một con số
 *    chưa duyệt trong code chính là hạng mục mà review chỉ ra.
 *
 * 2. **Cột file lỗi theo đúng schema baseline SRS §13.12** — đây là nơi DUY NHẤT trong
 *    4 tài liệu baseline định nghĩa hình dạng một error file:
 *    `source_file, sheet, row_no, field, error_code, message, raw_value (Masked if PII),
 *    severity, suggested_action`. Bản trước tự đặt 5 cột riêng (`row, field, error_code,
 *    message, value`) — đọc được nhưng lệch chuẩn, và người vận hành phải học hai định
 *    dạng file lỗi khác nhau cho cùng một hệ thống.
 */
class ImportErrorFileService
{
    /**
     * Tên đĩa lưu file lỗi — đọc từ config để nhiều instance sau load balancer
     * dùng chung một đĩa (xem config/wms_import.php). Ghi, tải về và dọn dẹp
     * PHẢI cùng đi qua đây.
     */
    public static function diskName(): string
    {
        return (string) config('wms_import.error_disk', self::DISK);
    }

    /** Mặc định khi chưa cấu hình; nơi ghi/đọc/dọn phải gọi diskName(). */
    public const DISK = 'local';

    public const STORAGE_SUBDIR = 'import-error-files';

    /**
     * Cột file lỗi — trật tự và tên khớp SRS §13.12 (bảng "Error file schema").
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'source_file', 'sheet', 'row_no', 'field', 'error_code',
        'message', 'raw_value', 'severity', 'suggested_action',
    ];

    /**
     * Phân loại severity theo SRS §13.12 (`CRITICAL | ERROR | WARNING`).
     *
     * Trong luồng import all-or-nothing này MỌI dòng lỗi đều chặn commit — severity
     * không quyết định "có ghi hay không" mà nói cho người sửa file biết bản chất lỗi:
     * `CRITICAL` = xung đột định danh/tham chiếu (phải quyết định nghiệp vụ mới sửa
     * được), `ERROR` = sai định dạng một ô (sửa tại chỗ là xong). Không dùng `WARNING`
     * vì ở đây không có lỗi nào không chặn — gán WARNING sẽ nói sai sự thật.
     *
     * @var array<string, string>
     */
    private const SEVERITY_BY_CODE = [
        'DUPLICATE_CODE' => 'CRITICAL',
        'DUPLICATE_SKU_CODE_IN_FILE' => 'CRITICAL',
        'INACTIVE_SKU_CONFLICT' => 'CRITICAL',
        'UNKNOWN_MASTER_CODE' => 'CRITICAL',
    ];

    /**
     * `suggested_action` của SRS §13.12: chỉ dẫn sửa cụ thể cho từng mã lỗi, để file lỗi
     * tự đủ nghĩa khi mở bằng Excel — người nhận file không phải quay lại hỏi dev.
     *
     * @var array<string, string>
     */
    private const SUGGESTED_ACTION_BY_CODE = [
        'INVALID_CODE_FORMAT' => 'Sửa sku_code: chỉ chữ HOA/số, dấu . _ - ở giữa, tối đa 100 ký tự.',
        'INVALID_NAME' => 'Nhập sku_name (bắt buộc), tối đa 255 ký tự.',
        'DUPLICATE_SKU_CODE_IN_FILE' => 'Xoá hoặc gộp dòng trùng sku_code ngay trong file trước khi import lại.',
        'DUPLICATE_CODE' => 'SKU đã tồn tại: bật tùy chọn "cập nhật SKU đã tồn tại" nếu thực sự muốn ghi đè.',
        'INACTIVE_SKU_CONFLICT' => 'SKU đang INACTIVE: kích hoạt lại bằng chức năng sửa SKU, import không tự kích hoạt.',
        'UNKNOWN_MASTER_CODE' => 'Kiểm tra mã danh mục (brand/category/model) đã tồn tại và đang ACTIVE.',
        'INVALID_ENUM' => 'Chọn đúng một trong các giá trị hợp lệ được liệt kê ở cột message.',
        'INVALID_BOOLEAN' => 'Dùng 1/0, true/false, yes/no hoặc để trống.',
        'INVALID_VERSION_CODE' => 'Rút gọn version_code xuống tối đa 30 ký tự.',
    ];

    /** Số giờ file lỗi còn hiệu lực trước khi bị coi là hết hạn (404 khi tải). */
    public static function expiryHours(): int
    {
        return SkuImportContract::errorFileExpiryHours();
    }

    /**
     * Safe read projection of the persisted rejected rows. It uses the same masking,
     * severity and spreadsheet escaping as the downloadable error file.
     * Successful source rows are not persisted in import_reports.
     *
     * @param array<int, array<string, mixed>> $rowErrors
     * @return array<int, array<string, mixed>>
     */
    public function previewRows(array $rowErrors): array
    {
        return array_map(function (array $error): array {
            $code = (string) ($error['code'] ?? '');

            return [
                'row_no' => (int) ($error['row'] ?? 0),
                'field' => $this->sanitizeCell((string) ($error['field'] ?? '')),
                'error_code' => $this->sanitizeCell($code),
                'message' => $this->sanitizeCell((string) ($error['message'] ?? '')),
                'raw_value' => $this->sanitizeCell($this->maskIfPii($this->stringifyValue($error['value'] ?? null))),
                'severity' => self::SEVERITY_BY_CODE[$code] ?? 'ERROR',
                'suggested_action' => self::SUGGESTED_ACTION_BY_CODE[$code] ?? 'Sửa giá trị theo mô tả ở cột message rồi import lại.',
            ];
        }, $rowErrors);
    }

    /**
     * Sinh file CSV lỗi từ danh sách row_errors, đã sanitize giá trị để tránh CSV/formula
     * injection và giới hạn độ dài field không lộ dữ liệu ngoài phạm vi cần thiết.
     *
     * @param  array<int, array{row:int,code:string,field:string,message:string,value:mixed}>  $rowErrors
     * @return array{path: string, expires_at: Carbon}|null null nếu không có lỗi nào để ghi
     */
    public function generate(
        string $reportId,
        array $rowErrors,
        ?string $sourceFile = null,
        ?string $sheetName = null,
    ): ?array {
        if ($rowErrors === []) {
            return null;
        }

        $disk = Storage::disk(self::diskName());
        $relativePath = self::STORAGE_SUBDIR.'/'.$reportId.'.csv';

        $handle = fopen('php://temp', 'w+');
        // UTF-8 BOM để Excel mở tiếng Việt không bị lỗi font.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS);

        $source = $this->sanitizeCell((string) ($sourceFile ?? ''));
        $sheet = $this->sanitizeCell((string) ($sheetName ?? ''));

        foreach ($rowErrors as $error) {
            $code = (string) ($error['code'] ?? '');

            fputcsv($handle, [
                $source,
                $sheet,
                (string) ($error['row'] ?? ''),
                $this->sanitizeCell((string) ($error['field'] ?? '')),
                $this->sanitizeCell($code),
                $this->sanitizeCell((string) ($error['message'] ?? '')),
                $this->sanitizeCell($this->maskIfPii($this->stringifyValue($error['value'] ?? null))),
                self::SEVERITY_BY_CODE[$code] ?? 'ERROR',
                $this->sanitizeCell(self::SUGGESTED_ACTION_BY_CODE[$code] ?? 'Sửa giá trị theo mô tả ở cột message rồi import lại.'),
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
     * SRS §13.12 yêu cầu `raw_value` được che nếu là PII.
     *
     * Template SKU (§13.12, dataset SKU) không có cột PII nào — chỉ mã, tên hàng, đơn vị,
     * enum và mã danh mục. Nhưng file do người dùng nộp, không phải do hệ thống sinh: một
     * ô dán nhầm email/số điện thoại của khách vẫn có thể lọt vào và sẽ được chép nguyên
     * vào file lỗi rồi tải về. Che theo dạng dữ liệu là hàng rào rẻ và không thể sai ở
     * hướng nguy hiểm — cùng lắm là che nhầm một chuỗi vô hại.
     */
    private function maskIfPii(string $value): string
    {
        $value = (string) preg_replace_callback(
            '/[\p{L}0-9._%+-]+@[\p{L}0-9.-]+\.\p{L}{2,}/u',
            static fn (array $m): string => mb_substr($m[0], 0, 2).'***@***',
            $value,
        );

        return (string) preg_replace_callback(
            '/(?<!\d)(?:\+?84|0)\d{8,10}(?!\d)/',
            static fn (array $m): string => mb_substr($m[0], 0, 3).str_repeat('*', max(0, mb_strlen($m[0]) - 3)),
            $value,
        );
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
