<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

class ImportService
{
    private const MAX_FILE_SIZE = 10240; // 10MB
    private const MAX_ROWS = 1000;
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];

    public function import(string $filePath, bool $updateExisting = false): array
    {
        // Validate file
        $this->validateFile($filePath);

        // Load spreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Remove header row
        $header = array_shift($rows);
        $totalRows = count($rows);

        if ($totalRows === 0) {
            return $this->buildResult(0, 0, 0, 0, 0, 0, false, []);
        }

        if ($totalRows > self::MAX_ROWS) {
            throw new \InvalidArgumentException("File contains more than " . self::MAX_ROWS . " rows");
        }

        // Validate headers
        $this->validateHeaders($header);

        // Process rows
        $result = $this->processRows($rows, $updateExisting);

        return $result;
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
        $requiredHeaders = ['code', 'name'];
        $normalizedHeaders = array_map(function ($header) {
            return strtolower(trim($header));
        }, $headers);

        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $normalizedHeaders)) {
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

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1-based + header
            $rowData = $this->parseRow($row);

            // Skip empty rows
            if (empty($rowData['code']) && empty($rowData['name'])) {
                continue;
            }

            // Validate row
            $validationError = $this->validateRow($rowData, $rowNumber, $seenCodes);
            if ($validationError) {
                $rowErrors[] = $validationError;
                $errorRows++;
                continue;
            }

            $validRows++;

            // Check if code exists in database
            $existing = Product::where('code', $rowData['code'])->first();

            if ($existing) {
                if ($updateExisting) {
                    // Update mode
                    $existing->update($rowData);
                    $updatedRows++;
                } else {
                    // Skip duplicate
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'code' => 'DUPLICATE_CODE',
                        'field' => 'code',
                        'message' => "Product code '{$rowData['code']}' already exists",
                        'value' => $rowData['code'],
                    ];
                    $skippedRows++;
                    $errorRows++;
                }
            } else {
                // Insert new
                Product::create($rowData);
                $insertedRows++;
            }
        }

        $partialWrite = ($insertedRows + $updatedRows) > 0 && $errorRows > 0;

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
            'code' => $this->parseCode($row[0] ?? null),
            'sku_code' => $this->parseString($row[1] ?? null),
            'name' => $this->parseString($row[2] ?? null),
            'unit' => $this->parseString($row[3] ?? null) ?: 'cái',
            'sku_type' => $this->parseSkuType($row[4] ?? null),
            'brand_code' => $this->parseString($row[5] ?? null),
            'category_code' => $this->parseString($row[6] ?? null),
            'model_code' => $this->parseString($row[7] ?? null),
            'power_or_voltage' => $this->parseString($row[8] ?? null),
            'package_variant' => $this->parsePackageVariant($row[9] ?? null),
            'published_to_app' => $this->parseBoolean($row[10] ?? null),
            'is_serial_tracked' => $this->parseBoolean($row[11] ?? null),
            'status' => $this->parseStatus($row[12] ?? null),
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
        // Check required fields
        if (empty($rowData['code']) && empty($rowData['name'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_REQUIRED_FIELDS',
                'field' => 'code,name',
                'message' => 'Code and name are required',
                'value' => '',
            ];
        }

        if (empty($rowData['code'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_CODE',
                'field' => 'code',
                'message' => 'Code field is required',
                'value' => '',
            ];
        }

        if (empty($rowData['name'])) {
            return [
                'row' => $rowNumber,
                'code' => 'MISSING_NAME',
                'field' => 'name',
                'message' => 'Name field is required',
                'value' => '',
            ];
        }

        // Check code format
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-]*[A-Z0-9]$/', $rowData['code'])) {
            return [
                'row' => $rowNumber,
                'code' => 'INVALID_CODE_FORMAT',
                'field' => 'code',
                'message' => "Invalid code format '{$rowData['code']}'. Must be uppercase alphanumeric with hyphens",
                'value' => $rowData['code'],
            ];
        }

        if (strlen($rowData['code']) > 100) {
            return [
                'row' => $rowNumber,
                'code' => 'CODE_TOO_LONG',
                'field' => 'code',
                'message' => "Code exceeds 100 characters",
                'value' => $rowData['code'],
            ];
        }

        // Check name length
        if (strlen($rowData['name']) > 255) {
            return [
                'row' => $rowNumber,
                'code' => 'NAME_TOO_LONG',
                'field' => 'name',
                'message' => "Name exceeds 255 characters",
                'value' => $rowData['name'],
            ];
        }

        // Check duplicate in file
        if (isset($seenCodes[$rowData['code']])) {
            return [
                'row' => $rowNumber,
                'code' => 'DUPLICATE_CODE_IN_FILE',
                'field' => 'code',
                'message' => "Code '{$rowData['code']}' already exists in file (row {$seenCodes[$rowData['code']]})",
                'value' => $rowData['code'],
            ];
        }

        $seenCodes[$rowData['code']] = $rowNumber;

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