<?php

namespace App\Support\Migration;

/**
 * Gom error row/field của migration theo SRS §13.12 (error-file field spec) và
 * ERD bảng 59. Giá trị PII phải được mask trước khi lưu — `raw_value_masked`.
 */
class MigrationErrorBag
{
    /** Field bị coi là PII, không được lưu raw (SRS §13.10 privacy). */
    private const PII_FIELDS = ['phone', 'address', 'contact_name', 'owner_confirmation', 'serial_number', 'hardware_uid'];

    /** @var list<array<string, mixed>> */
    private array $errors = [];

    public function add(
        string $dataset,
        ?int $rowNo,
        ?string $field,
        string $errorCode,
        string $severity,
        string $message,
        ?string $rawValue = null,
        ?string $suggestedAction = null,
    ): void {
        $this->errors[] = [
            'dataset' => $dataset,
            'row_no' => $rowNo,
            'field_name' => $field,
            'error_code' => $errorCode,
            'severity' => $severity,
            'message' => $message,
            'raw_value_masked' => $rawValue === null ? null : self::mask($field, $rawValue),
            'suggested_action' => $suggestedAction,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->errors;
    }

    /** Số dòng bị chặn (CRITICAL/ERROR); WARNING không chặn commit. */
    public function blockingRowKeys(): array
    {
        $keys = [];
        foreach ($this->errors as $error) {
            if ($error['severity'] === 'WARNING' || $error['row_no'] === null) {
                continue;
            }
            $keys[$error['dataset'].'#'.$error['row_no']] = true;
        }

        return $keys;
    }

    /**
     * Khoá dòng bị loại vì trùng (error_code DUPLICATE*), tách khỏi nhóm invalid.
     *
     * SRS §13.12 yêu cầu "valid + invalid + duplicate = input row count", nên một
     * dòng chỉ được đếm ở đúng một nhóm. Dòng vừa trùng vừa sai định dạng được
     * xếp vào invalid: remediation của nó là sửa dữ liệu, không phải khử trùng.
     *
     * @return array<string, bool>
     */
    public function duplicateRowKeys(): array
    {
        $duplicate = [];
        $other = [];
        foreach ($this->errors as $error) {
            if ($error['severity'] === 'WARNING' || $error['row_no'] === null) {
                continue;
            }
            $key = $error['dataset'].'#'.$error['row_no'];
            if (str_starts_with((string) $error['error_code'], 'DUPLICATE')) {
                $duplicate[$key] = true;
            } else {
                $other[$key] = true;
            }
        }

        return array_diff_key($duplicate, $other);
    }

    /** Dòng bị loại vì lý do khác trùng lặp. @return array<string, bool> */
    public function invalidRowKeys(): array
    {
        return array_diff_key($this->blockingRowKeys(), $this->duplicateRowKeys());
    }

    public function countBySeverity(string $severity): int
    {
        return count(array_filter($this->errors, static fn (array $e): bool => $e['severity'] === $severity));
    }

    public function hasBlocking(): bool
    {
        return $this->countBySeverity('CRITICAL') > 0 || $this->countBySeverity('ERROR') > 0;
    }

    private static function mask(?string $field, string $value): string
    {
        if ($field === null || ! in_array($field, self::PII_FIELDS, true) || $value === '') {
            return $value;
        }

        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2).str_repeat('*', max(1, $length - 4)).mb_substr($value, -2);
    }
}
