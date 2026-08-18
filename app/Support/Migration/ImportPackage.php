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
 * Checksum của package (DEV299-P1-03) phủ **cả manifest lẫn dữ liệu**:
 *
 *     sha256( "manifest:" + sha256(manifest đã chuẩn hoá) + LF
 *             + "DATASET:sha256(file)" đã sắp xếp )
 *
 * Trước đây checksum chỉ băm danh sách hash file, nên sửa `source_file_name`,
 * `package_version` hay ánh xạ dataset -> file (trỏ sang một file khác có cùng
 * nội dung) sau dry-run vẫn cho ra checksum cũ và COMMIT vẫn chạy: identity của
 * nguồn không được bảo vệ đúng như SRS §13.12 ("File changed after dry-run
 * requires a new checksum and rerun") yêu cầu.
 *
 * Chuẩn hoá manifest = chỉ giữ các trường normative (`package_version`,
 * `source_file_name`, `datasets` đã ksort) rồi encode JSON ổn định, nên format
 * lại file (thụt lề, thứ tự khoá, xuống dòng) KHÔNG làm hỏng checksum, còn đổi
 * nội dung normative thì chắc chắn làm hỏng.
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
     * Cột tuỳ chọn theo SRS §13.12. Dataset SKU của §13.12 liệt kê
     * "brand/category/model" bên cạnh nhóm cột bắt buộc: nếu package có các cột
     * này thì migration phải map sang FK master thật, nếu không catalog sau
     * cutover mất phân loại (DEV260-P1-03 — full dataset coverage, không chỉ
     * cột định danh).
     */
    public const DATASET_OPTIONAL_COLUMNS = [
        'SKU' => ['brand_code', 'category_code', 'model_code'],
        'PHYSICAL_CODE' => [],
        'ITEM' => [],
        'CONTAINER' => [],
        'OPENING_BALANCE' => [],
        'AGENCY' => [],
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
        public readonly string $manifestChecksum,
        public readonly array $manifest,
        public readonly array $fileChecksums,
        public readonly array $fileNames,
        private readonly array $rows,
    ) {}

    public static function load(string $directory): self
    {
        $directory = rtrim($directory, '/\\');
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
        $fileNames = [];
        foreach (self::DATASET_ORDER as $dataset) {
            if (! isset($datasets[$dataset])) {
                continue;
            }
            $path = $directory.'/'.$datasets[$dataset];
            if (! is_file($path)) {
                throw new RuntimeException("PACKAGE_FILE_MISSING: {$dataset} -> {$path}");
            }
            $fileChecksums[$dataset] = hash_file('sha256', $path);
            $fileNames[$dataset] = (string) $datasets[$dataset];
            $rows[$dataset] = self::readCsv($dataset, $path);
        }

        ksort($fileChecksums);
        ksort($fileNames);

        $sourceFileName = (string) ($manifest['source_file_name'] ?? basename($directory));

        // DEV299-P1-03: manifest là một phần của identity nguồn, không phải
        // metadata tự do — nên nó nằm trong checksum cùng với hash từng file.
        $normalizedManifest = self::normalizeManifest($version, $sourceFileName, $datasets);
        $manifestChecksum = hash('sha256', (string) json_encode(
            $normalizedManifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $datasetLines = array_map(
            static fn (string $dataset, string $hash): string => $dataset.':'.$hash,
            array_keys($fileChecksums),
            $fileChecksums
        );

        $checksum = hash('sha256', implode("\n", array_merge(
            ['manifest:'.$manifestChecksum],
            $datasetLines
        )));

        return new self(
            $directory,
            $version,
            $sourceFileName,
            $checksum,
            $manifestChecksum,
            $normalizedManifest,
            $fileChecksums,
            $fileNames,
            $rows,
        );
    }

    /**
     * Chỉ giữ phần normative của manifest và sắp xếp ổn định, để checksum bám
     * nội dung chứ không bám cách format file.
     *
     * @param  array<string, mixed>  $datasets
     * @return array{package_version:string, source_file_name:string, datasets:array<string, string>}
     */
    private static function normalizeManifest(string $version, string $sourceFileName, array $datasets): array
    {
        $normalizedDatasets = [];
        foreach ($datasets as $dataset => $file) {
            $normalizedDatasets[(string) $dataset] = trim((string) $file);
        }
        ksort($normalizedDatasets);

        return [
            'package_version' => $version,
            'source_file_name' => $sourceFileName,
            'datasets' => $normalizedDatasets,
        ];
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

    /**
     * Số dòng input theo từng dataset — cơ sở kiểm bất biến
     * "valid + invalid + duplicate = input row count" (SRS §13.12) ở mức file.
     *
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        $counts = [];
        foreach ($this->datasets() as $dataset) {
            $counts[$dataset] = count($this->rows[$dataset]);
        }

        return $counts;
    }

    /** Tên file nguồn của dataset — cột `source_file` của error file §13.12. */
    public function fileName(string $dataset): string
    {
        return $this->fileNames[$dataset] ?? $this->sourceFileName;
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
