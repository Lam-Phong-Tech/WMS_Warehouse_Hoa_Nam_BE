<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Product;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportService
{
    public const TEMPLATE_VERSION = 'WWHN-172-SKU-IMPORT-v1.1.0';

    private const COLUMNS = [
        'code', 'sku_code', 'name', 'unit', 'sku_type', 'brand_code',
        'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'published_to_app', 'is_serial_tracked', 'status',
    ];

    /** @return array<string, mixed> */
    public function import(
        string $filePath,
        bool $updateExisting = false,
        ?string $actorId = null,
        ?string $requestId = null,
        ?string $originalName = null,
    ): array {
        $this->assertFile($filePath);
        $checksum = hash_file('sha256', $filePath);
        if (! is_string($checksum)) {
            throw new \InvalidArgumentException('Không tính được SHA-256 của file import.');
        }

        try {
            $rows = IOFactory::load($filePath)->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Workbook Excel không đọc được hoặc đã hỏng.', previous: $e);
        }

        $header = array_shift($rows) ?? [];
        $map = $this->headerMap($header);
        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)
            ->contains(fn (mixed $value): bool => trim((string) $value) !== '')));

        if ($rows === [] || count($rows) > 1000) {
            throw new \InvalidArgumentException($rows === []
                ? 'File import không có dòng dữ liệu.'
                : 'File import vượt quá 1000 dòng dữ liệu.');
        }

        [$records, $errors] = $this->validateRows($rows, $map);
        if ($errors !== []) {
            return $this->result(count($rows), count($records), $errors, 0, 0, false, $checksum, $map);
        }

        return DB::transaction(function () use (
            $records, $rows, $updateExisting, $actorId, $requestId, $originalName, $checksum, $map
        ): array {
            $codes = collect($records)->pluck('code')->all();
            $skuCodes = collect($records)->pluck('sku_code')->all();
            $existing = Product::query()
                ->where(fn ($query) => $query->whereIn('code', $codes)->orWhereIn('sku_code', $skuCodes))
                ->lockForUpdate()
                ->get();
            $byCode = $existing->keyBy(fn (Product $p): string => strtoupper((string) $p->code));
            $bySku = $existing->keyBy(fn (Product $p): string => strtoupper((string) $p->sku_code));

            $dbErrors = [];
            foreach ($records as $index => $record) {
                $codeMatch = $byCode->get($record['code']);
                $skuMatch = $bySku->get($record['sku_code']);
                if ($codeMatch && $skuMatch && $codeMatch->id !== $skuMatch->id) {
                    $dbErrors[] = $this->error($index + 2, 'SKU_IDENTITY_COLLISION', 'code,sku_code', 'code và sku_code trỏ tới hai SKU khác nhau.', $record['code']);
                    continue;
                }
                $matched = $codeMatch ?? $skuMatch;
                if (! $matched) {
                    continue;
                }
                if (strtoupper((string) $matched->status) !== 'ACTIVE') {
                    $dbErrors[] = $this->error($index + 2, 'INACTIVE_SKU_CONFLICT', 'code', 'SKU không ACTIVE, import không được tự kích hoạt.', $record['code']);
                } elseif (! $updateExisting) {
                    $dbErrors[] = $this->error($index + 2, 'DUPLICATE_CODE', 'code', 'SKU đã tồn tại; cần bật update_existing.', $record['code']);
                }
            }
            if ($dbErrors !== []) {
                return $this->result(count($rows), count($records) - count($dbErrors), $dbErrors, 0, 0, false, $checksum, $map);
            }

            $inserted = 0;
            $updated = 0;
            foreach ($records as $record) {
                /** @var Product|null $product */
                $product = $byCode->get($record['code']) ?? $bySku->get($record['sku_code']);
                $before = $product?->toArray() ?? [];
                if ($product) {
                    $record['version'] = ((int) $product->version) + 1;
                    $product->fill($record)->save();
                    $updated++;
                    $action = 'SKU_IMPORT_UPDATE';
                } else {
                    $record['version'] = 1;
                    $product = Product::query()->create($record);
                    $inserted++;
                    $action = 'SKU_IMPORT_CREATE';
                }
                AuditLogger::record($action, 'sku', (string) $product->id, $actorId, $requestId,
                    beforeData: $before, afterData: $product->fresh()->toArray(),
                    reason: 'WWHN-172 atomic import '.$checksum);
            }

            AuditLogger::record('SKU_IMPORT_COMMIT', 'sku_import', null, $actorId, $requestId,
                afterData: [
                    'file_name' => $originalName,
                    'file_sha256' => $checksum,
                    'template_version' => self::TEMPLATE_VERSION,
                    'update_existing' => $updateExisting,
                    'inserted_rows' => $inserted,
                    'updated_rows' => $updated,
                    'transaction_mode' => 'ALL_OR_NOTHING',
                ],
                reason: 'WWHN-172 import summary in same transaction');

            return $this->result(count($rows), count($records), [], $inserted, $updated, true, $checksum, $map);
        }, 3);
    }

    public function createTemplate(string $path): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->fromArray([
            'SKU-DEMO-001', 'SKU-DEMO-001', 'Sản phẩm mẫu', 'cái', 'PRODUCT',
            'BRAND-01', 'CAT-01', 'MODEL-01', '220V', 'STANDARD', 'false', 'false', 'ACTIVE',
        ], null, 'A2');
        (new Xlsx($sheet->getParent()))->save($path);
    }

    private function assertFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new \InvalidArgumentException('Không tìm thấy file import.');
        }
        if (! in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            throw new \InvalidArgumentException('Chỉ chấp nhận file xlsx hoặc xls.');
        }
        if (($size = filesize($filePath)) === false || $size > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('File import vượt quá 10MB.');
        }
    }

    /** @return array<string, int> */
    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $name = strtolower(trim((string) $header));
            if ($name === '') {
                continue;
            }
            if (! in_array($name, self::COLUMNS, true) || isset($map[$name])) {
                throw new \InvalidArgumentException(isset($map[$name]) ? "Cột bị lặp: {$name}." : "Cột không hỗ trợ: {$name}.");
            }
            $map[$name] = (int) $index;
        }
        foreach (['code', 'name'] as $required) {
            if (! isset($map[$required])) {
                throw new \InvalidArgumentException("Thiếu cột bắt buộc: {$required}.");
            }
        }

        return $map;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function validateRows(array $rows, array $map): array
    {
        $records = [];
        $errors = [];
        $seenCode = [];
        $seenSku = [];
        foreach ($rows as $offset => $row) {
            $number = $offset + 2;
            $get = fn (string $column): mixed => isset($map[$column]) ? ($row[$map[$column]] ?? null) : null;
            $code = strtoupper(trim((string) $get('code')));
            $skuCode = strtoupper(trim((string) $get('sku_code'))) ?: $code;
            $name = trim((string) $get('name'));
            $rowErrors = [];
            foreach ([['code', $code, 100], ['sku_code', $skuCode, 100]] as [$field, $value, $max]) {
                if ($value === '' || mb_strlen($value) > $max || ! preg_match('/^[A-Z0-9](?:[A-Z0-9._-]*[A-Z0-9])?$/', $value)) {
                    $rowErrors[] = $this->error($number, 'INVALID_CODE_FORMAT', $field, "{$field} không hợp lệ.", $value);
                }
            }
            if ($name === '' || mb_strlen($name) > 255) {
                $rowErrors[] = $this->error($number, 'INVALID_NAME', 'name', 'name bắt buộc và tối đa 255 ký tự.', $name);
            }
            if (isset($seenCode[$code])) {
                $rowErrors[] = $this->error($number, 'DUPLICATE_CODE_IN_FILE', 'code', "Trùng dòng {$seenCode[$code]}.", $code);
            }
            if (isset($seenSku[$skuCode])) {
                $rowErrors[] = $this->error($number, 'DUPLICATE_SKU_CODE_IN_FILE', 'sku_code', "Trùng dòng {$seenSku[$skuCode]}.", $skuCode);
            }

            $skuType = $this->enum($get('sku_type'), ['PRODUCT', 'COMPONENT'], 'PRODUCT', $number, 'sku_type', $rowErrors);
            $status = $this->enum($get('status'), ['ACTIVE', 'INACTIVE', 'DRAFT'], 'ACTIVE', $number, 'status', $rowErrors);
            $package = $this->nullableEnum($get('package_variant'), ['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT'], $number, 'package_variant', $rowErrors);
            $published = $this->boolean($get('published_to_app'), $number, 'published_to_app', $rowErrors);
            $serial = $this->boolean($get('is_serial_tracked'), $number, 'is_serial_tracked', $rowErrors);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);
                continue;
            }
            $seenCode[$code] = $number;
            $seenSku[$skuCode] = $number;
            $records[] = [
                'code' => $code,
                'sku_code' => $skuCode,
                'name' => $name,
                'unit' => trim((string) $get('unit')) ?: 'cái',
                'sku_type' => $skuType,
                'brand_code' => $this->upper($get('brand_code')),
                'category_code' => $this->upper($get('category_code')),
                'model_code' => $this->upper($get('model_code')),
                'power_or_voltage' => $this->text($get('power_or_voltage')),
                'package_variant' => $package,
                'published_to_app' => $published,
                'is_serial_tracked' => $serial,
                'status' => $status,
            ];
        }

        return [$records, $errors];
    }

    private function enum(mixed $value, array $allowed, string $default, int $row, string $field, array &$errors): string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        if (! in_array($value, $allowed, true)) {
            $errors[] = $this->error($row, 'INVALID_ENUM', $field, $field.' phải thuộc '.implode(', ', $allowed).'.', $value);
            return $default;
        }
        return $value;
    }

    private function nullableEnum(mixed $value, array $allowed, int $row, string $field, array &$errors): ?string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! in_array($value, $allowed, true)) {
            $errors[] = $this->error($row, 'INVALID_ENUM', $field, $field.' phải thuộc '.implode(', ', $allowed).'.', $value);
            return null;
        }
        return $value;
    }

    private function boolean(mixed $value, int $row, string $field, array &$errors): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }
        if (in_array($value, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }
        $errors[] = $this->error($row, 'INVALID_BOOLEAN', $field, $field.' không hợp lệ.', $value);
        return false;
    }

    private function upper(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' ? null : $value;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function result(int $total, int $valid, array $errors, int $inserted, int $updated, bool $committed, string $checksum, array $map): array
    {
        return [
            'template_version' => self::TEMPLATE_VERSION,
            'transaction_mode' => 'ALL_OR_NOTHING',
            'committed' => $committed,
            'partial_write' => false,
            'file_sha256' => $checksum,
            'header_map' => $map,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => count($errors),
            'inserted_rows' => $inserted,
            'updated_rows' => $updated,
            'skipped_rows' => count($errors),
            'row_errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function error(int $row, string $code, string $field, string $message, mixed $value): array
    {
        return compact('row', 'code', 'field', 'message', 'value');
    }
}
