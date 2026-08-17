<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ImportService
{
    public const TEMPLATE_VERSION = 'WWHN-172-SKU-IMPORT-v1.1.0';

    /** Current export template. Legacy code/name/version headers remain accepted on import. */
    private const TEMPLATE_COLUMNS = [
        'sku_code', 'sku_name', 'unit', 'sku_type', 'brand_code', 'category_code',
        'model_code', 'power_or_voltage', 'package_variant', 'version_code',
        'published_to_app', 'is_serial_tracked', 'status',
    ];

    private const ACCEPTED_COLUMNS = [
        'code', 'sku_code', 'name', 'sku_name', 'unit', 'sku_type', 'brand_code',
        'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'version', 'version_code', 'published_to_app', 'is_serial_tracked', 'status',
    ];

    /** @return array<string, mixed> */
    public function import(
        string $filePath,
        bool $updateExisting = false,
        ?string $actorId = null,
        ?string $requestId = null,
        ?string $originalName = null,
        bool $dryRun = false,
    ): array {
        $this->assertFile($filePath);
        $checksum = hash_file('sha256', $filePath);
        if (! is_string($checksum)) {
            throw new InvalidArgumentException('Không tính được SHA-256 của file import.');
        }

        try {
            $rows = IOFactory::load($filePath)->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Workbook Excel không đọc được hoặc đã hỏng.', previous: $e);
        }

        $header = array_shift($rows) ?? [];
        $map = $this->headerMap($header);
        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)
            ->contains(fn (mixed $value): bool => trim((string) $value) !== '')));

        if ($rows === [] || count($rows) > 1000) {
            throw new InvalidArgumentException($rows === []
                ? 'File import không có dòng dữ liệu.'
                : 'File import vượt quá 1000 dòng dữ liệu.');
        }

        [$records, $errors] = $this->validateRows($rows, $map);
        if ($errors !== []) {
            return $this->result(count($rows), count($records), $errors, 0, 0, false, $checksum, $map, $dryRun);
        }

        return DB::transaction(function () use (
            $records, $rows, $updateExisting, $actorId, $requestId, $originalName, $checksum, $map, $dryRun
        ): array {
            $skuCodes = collect($records)->pluck('sku_code')->all();
            $existing = Sku::query()
                ->whereIn('sku_code', $skuCodes)
                ->lockForUpdate()
                ->get();
            $bySku = $existing->keyBy(fn (Sku $sku): string => strtoupper((string) $sku->sku_code));

            $dbErrors = [];
            foreach ($records as $index => $record) {
                /** @var Sku|null $matched */
                $matched = $bySku->get($record['sku_code']);
                if (! $matched) {
                    continue;
                }
                if (strtoupper((string) $matched->status) !== 'ACTIVE') {
                    $dbErrors[] = $this->error(
                        $index + 2,
                        'INACTIVE_SKU_CONFLICT',
                        'sku_code',
                        'SKU không ACTIVE, import không được tự kích hoạt.',
                        $record['sku_code'],
                    );
                } elseif (! $updateExisting) {
                    $dbErrors[] = $this->error(
                        $index + 2,
                        'DUPLICATE_CODE',
                        'sku_code',
                        'SKU đã tồn tại; cần bật update_existing.',
                        $record['sku_code'],
                    );
                }
            }

            if ($dbErrors !== []) {
                return $this->result(count($rows), count($records) - count($dbErrors), $dbErrors, 0, 0, false, $checksum, $map, $dryRun);
            }

            $wouldInsert = 0;
            $wouldUpdate = 0;
            foreach ($records as $record) {
                if ($bySku->get($record['sku_code'])) {
                    $wouldUpdate++;
                } else {
                    $wouldInsert++;
                }
            }

            if ($dryRun) {
                AuditLogger::record(
                    'SKU_IMPORT_DRY_RUN_CHECK',
                    'sku_import',
                    null,
                    $actorId,
                    $requestId,
                    afterData: [
                        'file_name' => $originalName,
                        'file_sha256' => $checksum,
                        'template_version' => self::TEMPLATE_VERSION,
                        'update_existing' => $updateExisting,
                        'would_insert_rows' => $wouldInsert,
                        'would_update_rows' => $wouldUpdate,
                        'transaction_mode' => 'DRY_RUN_NO_WRITE',
                    ],
                    reason: 'WWHN-224 dry-run check, no business row written',
                );

                return $this->result(count($rows), count($records), [], $wouldInsert, $wouldUpdate, false, $checksum, $map, true);
            }

            $inserted = 0;
            $updated = 0;
            foreach ($records as $record) {
                /** @var Sku|null $sku */
                $sku = $bySku->get($record['sku_code']);
                $before = $sku?->toArray() ?? [];
                $attributes = $record;
                if ($attributes['version_code'] === null) {
                    unset($attributes['version_code']);
                }

                if ($sku) {
                    $sku->fill($attributes)->save();
                    $updated++;
                    $action = 'SKU_IMPORT_UPDATE';
                } else {
                    $sku = Sku::query()->create($attributes);
                    $inserted++;
                    $action = 'SKU_IMPORT_CREATE';
                }

                AuditLogger::record(
                    $action,
                    'sku',
                    (string) $sku->sku_id,
                    $actorId,
                    $requestId,
                    beforeData: $before,
                    afterData: $sku->fresh()->toArray(),
                    reason: 'WWHN-172 atomic import '.$checksum,
                );
            }

            AuditLogger::record(
                'SKU_IMPORT_COMMIT',
                'sku_import',
                null,
                $actorId,
                $requestId,
                afterData: [
                    'file_name' => $originalName,
                    'file_sha256' => $checksum,
                    'template_version' => self::TEMPLATE_VERSION,
                    'update_existing' => $updateExisting,
                    'inserted_rows' => $inserted,
                    'updated_rows' => $updated,
                    'transaction_mode' => 'ALL_OR_NOTHING',
                ],
                reason: 'WWHN-172 import summary in same transaction',
            );

            return $this->result(count($rows), count($records), [], $inserted, $updated, true, $checksum, $map, false);
        }, 3);
    }

    public function createTemplate(string $path): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(self::TEMPLATE_COLUMNS, null, 'A1');
        $sheet->fromArray([
            'SKU-DEMO-001', 'Sản phẩm mẫu', 'cái', 'PRODUCT',
            null, null, null, '220V', 'BARE', 'V1', 'false', 'false', 'ACTIVE',
        ], null, 'A2');
        (new Xlsx($sheet->getParent()))->save($path);
    }

    private function assertFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException('Không tìm thấy file import.');
        }
        if (! in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('Chỉ chấp nhận file xlsx hoặc xls.');
        }
        if (($size = filesize($filePath)) === false || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('File import vượt quá 10MB.');
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
            if (! in_array($name, self::ACCEPTED_COLUMNS, true) || isset($map[$name])) {
                throw new InvalidArgumentException(isset($map[$name]) ? "Cột bị lặp: {$name}." : "Cột không hỗ trợ: {$name}.");
            }
            $map[$name] = (int) $index;
        }

        if (! isset($map['sku_code']) && ! isset($map['code'])) {
            throw new InvalidArgumentException('Thiếu cột bắt buộc: sku_code (hoặc alias legacy code).');
        }
        if (! isset($map['sku_name']) && ! isset($map['name'])) {
            throw new InvalidArgumentException('Thiếu cột bắt buộc: sku_name (hoặc alias legacy name).');
        }

        return $map;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function validateRows(array $rows, array $map): array
    {
        $records = [];
        $errors = [];
        $seenSku = [];

        foreach ($rows as $offset => $row) {
            $number = $offset + 2;
            $get = fn (string $column): mixed => isset($map[$column]) ? ($row[$map[$column]] ?? null) : null;
            $skuCode = $this->upper($get('sku_code')) ?? $this->upper($get('code')) ?? '';
            $skuName = $this->text($get('sku_name')) ?? $this->text($get('name')) ?? '';
            $rowErrors = [];

            if ($skuCode === '' || mb_strlen($skuCode) > 100 || ! preg_match('/^[A-Z0-9](?:[A-Z0-9._-]*[A-Z0-9])?$/', $skuCode)) {
                $rowErrors[] = $this->error($number, 'INVALID_CODE_FORMAT', 'sku_code', 'sku_code không hợp lệ.', $skuCode);
            }
            if ($skuName === '' || mb_strlen($skuName) > 255) {
                $rowErrors[] = $this->error($number, 'INVALID_NAME', 'sku_name', 'sku_name bắt buộc và tối đa 255 ký tự.', $skuName);
            }
            if (isset($seenSku[$skuCode])) {
                $rowErrors[] = $this->error($number, 'DUPLICATE_SKU_CODE_IN_FILE', 'sku_code', "Trùng dòng {$seenSku[$skuCode]}.", $skuCode);
            }

            $skuType = $this->enum($get('sku_type'), ['PRODUCT', 'COMPONENT'], 'PRODUCT', $number, 'sku_type', $rowErrors);
            $status = $this->enum($get('status'), ['ACTIVE', 'INACTIVE'], 'ACTIVE', $number, 'status', $rowErrors);
            $package = $this->nullableEnum($get('package_variant'), $this->allowedPackageVariants(), $number, 'package_variant', $rowErrors);
            $published = $this->boolean($get('published_to_app'), $number, 'published_to_app', $rowErrors);
            $serial = $this->boolean($get('is_serial_tracked'), $number, 'is_serial_tracked', $rowErrors);
            $versionCode = $this->text($get('version_code')) ?? $this->text($get('version'));
            if ($versionCode !== null && mb_strlen($versionCode) > 30) {
                $rowErrors[] = $this->error($number, 'INVALID_VERSION_CODE', 'version_code', 'version_code tối đa 30 ký tự.', $versionCode);
            }

            $brandId = $this->resolveMasterId($get('brand_code'), 'BRAND', $number, 'brand_code', $rowErrors);
            $categoryId = $this->resolveMasterId($get('category_code'), 'CATEGORY', $number, 'category_code', $rowErrors);
            $modelId = $this->resolveMasterId($get('model_code'), 'MODEL', $number, 'model_code', $rowErrors);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $seenSku[$skuCode] = $number;
            $records[] = [
                'sku_code' => $skuCode,
                'sku_name' => $skuName,
                'unit' => $this->text($get('unit')) ?? 'cái',
                'sku_type' => $skuType,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'model_id' => $modelId,
                'power_or_voltage' => $this->text($get('power_or_voltage')),
                'package_variant' => $package,
                'version_code' => $versionCode,
                'published_to_app' => $published,
                'is_serial_tracked' => $serial,
                'status' => $status,
            ];
        }

        return [$records, $errors];
    }

    private function resolveMasterId(mixed $value, string $type, int $row, string $field, array &$errors): ?string
    {
        $code = $this->upper($value);
        if ($code === null) {
            return null;
        }

        $master = ProductMaster::query()
            ->where('master_type', $type)
            ->where('code', $code)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $master) {
            $errors[] = $this->error($row, 'UNKNOWN_MASTER_CODE', $field, "Không tìm thấy {$type} ACTIVE với code {$code}.", $code);

            return null;
        }

        return (string) $master->master_id;
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

    /**
     * AC-SRS-029 "thêm loại không cần code change": quy cách đóng gói hợp lệ đọc
     * từ master package_variants. Master rỗng (fixture test tối giản) thì rơi về
     * bộ mã baseline để import cũ không vỡ.
     *
     * @return array<int, string>
     */
    private function allowedPackageVariants(): array
    {
        $codes = DB::table('package_variants')
            ->where('status', 'ACTIVE')
            ->pluck('package_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $codes === [] ? ['BARE', 'KIT', 'SET', 'BODY_ONLY'] : $codes;
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
    private function result(int $total, int $valid, array $errors, int $inserted, int $updated, bool $committed, string $checksum, array $map, bool $dryRun): array
    {
        return [
            'template_version' => self::TEMPLATE_VERSION,
            'transaction_mode' => $dryRun ? 'DRY_RUN_NO_WRITE' : 'ALL_OR_NOTHING',
            'dry_run' => $dryRun,
            'committed' => $committed,
            'partial_write' => false,
            'file_sha256' => $checksum,
            'header_map' => $map,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => count($errors),
            'inserted_rows' => $dryRun ? 0 : $inserted,
            'updated_rows' => $dryRun ? 0 : $updated,
            'would_insert_rows' => $dryRun ? $inserted : 0,
            'would_update_rows' => $dryRun ? $updated : 0,
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
