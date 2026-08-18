<?php

namespace App\Support\Migration;

use RuntimeException;

/**
 * Import Package v1.2 reader cho lần nạp dữ liệu cuối (REQ-SRS-021, SRS §13.12).
 *
 * Package là một thư mục controlled (không có public/admin import endpoint theo
 * DEC-MIG-API-001) gồm:
 *   - manifest.json: {"package_version": "1.2", "source_file_name": "...",
 *                     "datasets": {"SKU": "sku.csv", ...}}
 *   - mỗi dataset là một file CSV có header đúng cột bắt buộc của §13.12.
 *
 * Checksum của package là sha256 trên danh sách "dataset:sha256(file)" đã sắp xếp,
 * nên đổi bất kỳ file nào sau dry-run đều làm checksum khác và buộc rerun
 * (SRS §13.12: "File changed after dry-run requires a new checksum and rerun").
 */
class ImportPackage
{
    /** Cột bắt buộc theo SRS §13.12 cho từng dataset. */
    public const DATASET_COLUMNS = [
        'SKU' => ['sku_code', 'sku_name', 'sku_type', 'unit', 'is_serial_tracked', 'version_code', 'status'],
        'PHYSICAL_CODE' => ['code_value', 'code_type', 'carrier_type', 'hardware_uid', 'target_legacy_key', 'status'],
        'ITEM' => ['legacy_item_key', 'sku_code', 'serial_number', 'code_value', 'condition', 'import_batch'],
        'CONTAINER' => ['container_code', 'type', 'carrier', 'physical_code', 'primary_sku', 'parent_tray', 'status'],
        'OPENING_BALANCE' => ['warehouse_code', 'sku_code', 'stock_status', 'quantity', 'source', 'owner_confirmation'],
        'AGENCY' => ['agency_code', 'agency_name', 'phone', 'address', 'status'],
    ];

    /**
     * Thứ tự nạp: master trước, identity giữa, tồn đầu cuối cùng — mọi FK phải
     * tồn tại trước khi dataset phụ thuộc được ghi.
     */
    public const DATASET_ORDER = ['AGENCY', 'SKU', 'PHYSICAL_CODE', 'CONTAINER', 'ITEM', 'OPENING_BALANCE'];

    /** @param array<string, list<array{row_no:int, data:array<string, string>}>> $rows */
    private function __construct(
        public readonly string $directory,
        public readonly string $packageVersion,
        public readonly string $sourceFileName,
        public readonly string $checksum,
        public readonly array $fileChecksums,
        private readonly array $rows,
    ) {
    }

    public static function load(string $directory): self
    {
        $directory = rtrim($directory, "/\\");
        $manifestPath = $directory.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException("PACKAGE_MANIFEST_MISSING: không tìm thấy {$manifestPath}");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('PACKAGE_MANIFEST_INVALID: manifest.json không phải JSON object hợp lệ');
        }

        $version = (string) ($manifest['package_version'] ?? '');
        $datasets = $manifest['datasets'] ?? null;
        if ($version === '' || ! is_array($datasets) || $datasets === []) {
            throw new RuntimeException('PACKAGE_MANIFEST_INVALID: thiếu package_version hoặc datasets');
        }

        $unknown = array_diff(array_keys($datasets), array_keys(self::DATASET_COLUMNS));
        if ($unknown !== []) {
            throw new RuntimeException('PACKAGE_DATASET_UNKNOWN: '.implode(',', $unknown));
        }

        $rows = [];
        $fileChecksums = [];
        foreach (self::DATASET_ORDER as $dataset) {
            if (! isset($datasets[$dataset])) {
                continue;
            }
            $path = $directory.'/'.$datasets[$dataset];
            if (! is_file($path)) {
                throw new RuntimeException("PACKAGE_FILE_MISSING: {$dataset} -> {$path}");
            }
            $fileChecksums[$dataset] = hash_file('sha256', $path);
            $rows[$dataset] = self::readCsv($dataset, $path);
        }

        ksort($fileChecksums);
        $checksum = hash('sha256', implode("\n", array_map(
            static fn (string $dataset, string $hash): string => $dataset.':'.$hash,
            array_keys($fileChecksums),
            $fileChecksums
        )));

        return new self(
            $directory,
            $version,
            (string) ($manifest['source_file_name'] ?? basename($directory)),
            $checksum,
            $fileChecksums,
            $rows,
        );
    }

    /** @return list<string> */
    public function datasets(): array
    {
        return array_values(array_filter(self::DATASET_ORDER, fn (string $d): bool => isset($this->rows[$d])));
    }

    /** @return list<array{row_no:int, data:array<string, string>}> */
    public function rows(string $dataset): array
    {
        return $this->rows[$dataset] ?? [];
    }

    public function totalRows(): int
    {
        return array_sum(array_map('count', $this->rows));
    }

    /** @return list<array{row_no:int, data:array<string, string>}> */
    private static function readCsv(string $dataset, string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("PACKAGE_FILE_UNREADABLE: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);

            throw new RuntimeException("PACKAGE_FILE_EMPTY: {$path}");
        }
        // Bỏ BOM nếu file được xuất từ Excel.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static fn ($column): string => trim((string) $column), $header);

        $missing = array_diff(self::DATASET_COLUMNS[$dataset], $header);
        if ($missing !== []) {
            fclose($handle);

            throw new RuntimeException("PACKAGE_HEADER_MISMATCH: {$dataset} thiếu cột ".implode(',', $missing));
        }

        $rows = [];
        $rowNo = 1;
        while (($record = fgetcsv($handle)) !== false) {
            $rowNo++;
            if ($record === [null] || $record === []) {
                continue;
            }
            $data = [];
            foreach ($header as $index => $column) {
                $data[$column] = trim((string) ($record[$index] ?? ''));
            }
            if (implode('', $data) === '') {
                continue;
            }
            $rows[] = ['row_no' => $rowNo, 'data' => $data];
        }
        fclose($handle);

        return $rows;
    }
}
