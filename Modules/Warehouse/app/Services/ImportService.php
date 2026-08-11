<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    private const MAX_FILE_SIZE = 10240; // 10MB
    private const MAX_ROWS = 1000;
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];

    public function import(string $filePath, bool $updateExisting = false): array
    {
        $this->validateFile($filePath);
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        $header = array_shift($rows);
        $totalRows = count($rows);

        if ($totalRows === 0) {
            return $this->buildResult(0, 0, 0, 0, 0, 0, false, []);
        }

        if ($totalRows > self::MAX_ROWS) {
            throw new \InvalidArgumentException("File contains more than " . self::MAX_ROWS . " rows");
        }

        $this->validateHeaders($header);

        return $this->processRows($rows, $updateExisting);
    }

    private function validateFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \InvalidArgumentException("Invalid file type. Allowed: " . implode(', ', self::ALLOWED_EXTENSIONS));
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE * 1024) {
            throw new \InvalidArgumentException("File size exceeds " . self::MAX_FILE_SIZE . "KB limit");
        }
    }

    private function validateHeaders(array $headers): void
    {
        $requiredHeaders = ['sku_code', 'sku_name'];
        $normalizedHeaders = array_map(function ($header) {
            return strtolower(trim($header));
        }, $headers);

        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $normalizedHeaders) && !in_array(str_replace('sku_', '', $required), $normalizedHeaders)) {
                throw new \InvalidArgumentException("Missing required column: {$required}");
            }
        }
    }

    private function processRows(array $rows, bool $updateExisting): array
    {
        $insertedRows = 0;
        $updatedRows = 0;
        $skippedRows = 0;
        $rowErrors = [];
        $seenCodes = [];
        $validRows = 0;
        $errorRows = 0;

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                $rowData = $this->parseRow($row);

                if (empty($rowData['sku_code']) && empty($rowData['sku_name'])) {
                    continue;
                }

                $validationError = $this->validateRow($rowData, $rowNumber, $seenCodes);
                if ($validationError) {
                    $rowErrors[] = $validationError;
                    $errorRows++;
                    continue;
                }

                $validRows++;

                $existing = Sku::where('sku_code', $rowData['sku_code'])->first();

                if ($existing) {
                    if ($updateExisting) {
                        $existing->update($rowData);
                        $updatedRows++;
                    } else {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'code' => 'DUPLICATE_CODE',
                            'field' => 'sku_code',
                            'message' => "Sku code '{$rowData['sku_code']}' already exists",
                            'value' => $rowData['sku_code'],
                        ];
                        $skippedRows++;
                        $errorRows++;
                    }
                } else {
                    Sku::create($rowData);
                    $insertedRows++;
                }
            }

            if ($errorRows > 0) {
                DB::rollBack();
                $insertedRows = 0;
                $updatedRows = 0;
                $partialWrite = false;
            } else {
                DB::commit();
                $partialWrite = false;
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->buildResult(
            $validRows + $errorRows,
            $validRows,
            $errorRows,
            $insertedRows,
            $updatedRows,
            $skippedRows,
            $partialWrite,
            $rowErrors
        );
    }

    private function parseRow(array $row): array
    {
        return [
            'sku_code' => $this->parseCode($row[0] ?? null),
            'sku_name' => $this->parseString($row[1] ?? null),
            'unit' => $this->parseString($row[2] ?? null) ?: 'cái',
            'sku_type' => $this->parseSkuType($row[3] ?? null),
            'brand_code' => $this->parseString($row[4] ?? null),
            'category_code' => $this->parseString($row[5] ?? null),
            'model_code' => $this->parseString($row[6] ?? null),
            'power_or_voltage' => $this->parseString($row[7] ?? null),
            'package_variant' => $this->parsePackageVariant($row[8] ?? null),
            'published_to_app' => $this->parseBoolean($row[9] ?? null),
            'is_serial_tracked' => $this->parseBoolean($row[10] ?? null),
            'status' => $this->parseStatus($row[11] ?? null),
        ];
    }

    private function parseCode(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        return strtoupper(trim($value));
    }

    private function parseString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        return trim($value);
    }

    private function parseSkuType(?string $value): string
    {
        if (empty($value)) {
            return 'PRODUCT';
        }
        $value = strtoupper(trim($value));
        return in_array($value, ['PRODUCT', 'COMPONENT']) ? $value : 'PRODUCT';
    }

    private function parseStatus(?string $value): string
    {
        if (empty($value)) {
            return 'ACTIVE';
        }
        $value = strtoupper(trim($value));
        return in_array($value, ['ACTIVE', 'INACTIVE', 'DRAFT']) ? $value : 'ACTIVE';
    }

    private function parsePackageVariant(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        return in_array($value, ['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT']) ? $value : null;
    }

    private function parseBoolean(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        $value = strtolower(trim($value));
        return in_array($value, ['true', '1', 'yes', 'y']);
    }

    private function validateRow(array $rowData, int $rowNumber, array &$seenCodes): ?array
    {
        if (empty($rowData['sku_code']) && empty($rowData['sku_name'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_REQUIRED_FIELDS',
                'field' => 'sku_code,sku_name',
                'message' => 'Sku code and name are required',
                'value' => '',
            ];
        }

        if (empty($rowData['sku_code'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_CODE',
                'field' => 'sku_code',
                'message' => 'Sku code field is required',
                'value' => '',
            ];
        }

        if (empty($rowData['sku_name'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_NAME',
                'field' => 'sku_name',
                'message' => 'Sku name field is required',
                'value' => '',
            ];
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9\-]*[A-Z0-9]$/', $rowData['sku_code'])) {
            return [
                'row' => $rowNumber,
                'code' => 'INVALID_CODE_FORMAT',
                'field' => 'sku_code',
                'message' => "Invalid code format '{$rowData['sku_code']}'. Must be uppercase alphanumeric with hyphens",
                'value' => $rowData['sku_code'],
            ];
        }

        if (strlen($rowData['sku_code']) > 100) {
            return [
                'row' => $rowNumber,
                'code' => 'CODE_TOO_LONG',
                'field' => 'sku_code',
                'message' => "Code exceeds 100 characters",
                'value' => $rowData['sku_code'],
            ];
        }

        if (strlen($rowData['sku_name']) > 255) {
            return [
                'row' => $rowNumber,
                'code' => 'NAME_TOO_LONG',
                'field' => 'sku_name',
                'message' => "Name exceeds 255 characters",
                'value' => $rowData['sku_name'],
            ];
        }

        if (isset($seenCodes[$rowData['sku_code']])) {
            return [
                'row' => $rowNumber,
                'code' => 'DUPLICATE_CODE_IN_FILE',
                'field' => 'sku_code',
                'message' => "Code '{$rowData['sku_code']}' already exists in file (row {$seenCodes[$rowData['sku_code']]})",
                'value' => $rowData['sku_code'],
            ];
        }

        $seenCodes[$rowData['sku_code']] = $rowNumber;

        return null;
    }

    private function buildResult(
        int $totalRows,
        int $validRows,
        int $errorRows,
        int $insertedRows,
        int $updatedRows,
        int $skippedRows,
        bool $partialWrite,
        array $rowErrors
    ): array {
        return [
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'inserted_rows' => $insertedRows,
            'updated_rows' => $updatedRows,
            'skipped_rows' => $skippedRows,
            'partial_write' => $partialWrite,
            'row_errors' => $rowErrors,
        ];
    }
}