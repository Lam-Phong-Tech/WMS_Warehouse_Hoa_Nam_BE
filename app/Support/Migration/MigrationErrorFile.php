<?php

namespace App\Support\Migration;

use RuntimeException;

/**
 * Error file của lần nạp dữ liệu cuối — SRS §13.12 "Error-file field" và
 * NFR-SRS-017 ("error file đầy đủ row/field/code/message").
 *
 * Trước WWHN-260 các dòng bị loại chỉ nằm trong `migration_errors` và trong khối
 * `errors` của báo cáo JSON; không có artifact nào bàn giao được cho chủ dữ liệu
 * để làm cleansing log (DEV260-P1-04, DEV260-P1-11). File này sinh đúng thứ tự
 * cột của §13.12, rồi bổ sung hai cột cuối để dòng bị loại có **chủ** và
 * **truy vết run**:
 *
 *   source_file, sheet, row_no, field, error_code, message, raw_value, severity,
 *   suggested_action, data_owner, migration_run_code
 *
 * `raw_value` luôn là giá trị đã mask (MigrationErrorBag) — không ghi PII thô.
 */
class MigrationErrorFile
{
    public const COLUMNS = [
        'source_file', 'sheet', 'row_no', 'field', 'error_code', 'message',
        'raw_value', 'severity', 'suggested_action', 'data_owner', 'migration_run_code',
    ];

    /**
     * @param  list<array<string, mixed>>  $errors  dòng lỗi dạng migration_errors
     * @param  array<string, string>  $sourceFileByDataset  dataset → tên file nguồn
     * @return int số dòng đã ghi
     */
    public static function write(
        string $path,
        string $runCode,
        array $errors,
        array $sourceFileByDataset = [],
        string $fallbackSourceFile = ''
    ): int {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("MIGRATION_ERROR_FILE_UNWRITABLE: không tạo được {$directory}");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("MIGRATION_ERROR_FILE_UNWRITABLE: {$path}");
        }

        // BOM để Excel của chủ dữ liệu mở đúng tiếng Việt.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS);

        $owners = (array) config('migration_mapping.dataset_owners', []);
        $written = 0;
        foreach ($errors as $error) {
            $dataset = (string) ($error['dataset'] ?? '');
            fputcsv($handle, [
                $sourceFileByDataset[$dataset] ?? $fallbackSourceFile,
                $dataset,
                $error['row_no'] ?? '',
                $error['field_name'] ?? '',
                $error['error_code'] ?? '',
                $error['message'] ?? '',
                $error['raw_value_masked'] ?? '',
                $error['severity'] ?? '',
                $error['suggested_action'] ?? '',
                $owners[$dataset] ?? 'CHƯA GÁN CHỦ DỮ LIỆU',
                $runCode,
            ]);
            $written++;
        }

        fclose($handle);

        return $written;
    }
}
