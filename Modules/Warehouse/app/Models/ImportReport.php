<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportReport extends Model
{
    use HasUuids;

    protected $table = 'import_reports';

    protected $primaryKey = 'import_report_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'import_type', 'mode', 'status', 'file_name', 'file_sha256', 'template_version',
        'total_rows', 'valid_rows', 'error_rows', 'inserted_rows', 'updated_rows',
        'row_errors', 'actor_id', 'request_id',
        'error_file_path', 'error_file_expires_at', 'duplicate_of_replay',
        // WWHN-331 FE331-P1-02R3: vân tay các tùy chọn tham gia ĐỊNH DANH lần import
        // (hiện tại chỉ update_existing). Xem SkuImportContract::optionsFingerprint().
        'options_fingerprint',
        'source_file_id', 'result_version', 'sheet_name', 'update_existing',
        'started_at', 'completed_at', 'processing_duration_ms', 'actor_name', 'actor_account',
    ];

    protected $casts = [
        'row_errors' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'inserted_rows' => 'integer',
        'updated_rows' => 'integer',
        'error_file_expires_at' => 'datetime',
        'duplicate_of_replay' => 'boolean',
        'result_version' => 'integer', 'update_existing' => 'boolean',
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'processing_duration_ms' => 'integer',
    ];

    /**
     * WWHN-330 (DEV330-P1-01) / WWHN-331 (FE331-P1-03): `error_file_path` là đường dẫn
     * lưu trữ NỘI BỘ trên disk `local` (private). Client chỉ được biết "có file để tải
     * hay không", không được biết layout thư mục của server — đúng yêu cầu "không lộ
     * data ngoài scope". Việc tải file luôn đi qua
     * GET /skus/import-reports/{id}/error-file (đã gate permission sku.manage).
     *
     * @var list<string>
     */
    protected $hidden = ['error_file_path', 'sourceFile'];

    protected $with = ['sourceFile'];

    /** @var list<string> */
    protected $appends = ['error_file_available', 'error_file_status', 'source_file_available', 'source_file_status',
        'uploaded_by_name', 'uploaded_by_account', 'uploaded_at', 'file_size_bytes',
        'actual_inserted_rows', 'actual_updated_rows', 'not_written_rows', 'planned_inserted_rows', 'planned_updated_rows', 'row_counts'];

    protected function rowCounts(): Attribute
    {
        return Attribute::make(get: function (): array {
            $errors = is_array($this->row_errors) ? $this->row_errors : [];
            $rows = [];
            foreach ($errors as $index => $error) {
                $key = (string) ($error['row'] ?? 'unknown-'.$index);
                $code = strtoupper((string) ($error['code'] ?? ''));
                $duplicate = str_contains($code, 'DUPLICATE') || str_contains($code, 'ALREADY_EXISTS') || str_contains($code, 'ALREADY_MATCHED');
                $rows[$key] = ($rows[$key] ?? true) && $duplicate;
            }
            $duplicate = count(array_filter($rows));
            $complete = count($errors) === $this->error_rows;

            return [
                'valid' => $complete ? max(0, $this->total_rows - count($rows)) : $this->valid_rows,
                'duplicate' => $duplicate,
                'invalid' => max(0, ($complete ? count($rows) : $this->error_rows) - $duplicate),
            ];
        });
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ImportSourceFile::class, 'source_file_id', 'source_file_id');
    }

    protected function errorFileStatus(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->error_file_available ? 'AVAILABLE'
            : ($this->error_file_expires_at?->isPast() ? 'EXPIRED' : 'UNAVAILABLE'));
    }

    protected function sourceFileAvailable(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->sourceFile?->available() ?? false);
    }

    protected function sourceFileStatus(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->source_file_available ? 'AVAILABLE' : ($this->sourceFile?->file_path ? 'MISSING' : 'NOT_RETAINED'));
    }

    protected function uploadedByName(): Attribute
    {
        return Attribute::make(get: fn () => $this->sourceFile?->uploaded_by_name);
    }

    protected function uploadedByAccount(): Attribute
    {
        return Attribute::make(get: fn () => $this->sourceFile?->uploaded_by_account);
    }

    protected function uploadedAt(): Attribute
    {
        return Attribute::make(get: fn () => $this->sourceFile?->uploaded_at?->toIso8601String());
    }

    protected function fileSizeBytes(): Attribute
    {
        return Attribute::make(get: fn () => $this->sourceFile?->file_size_bytes);
    }

    protected function actualInsertedRows(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->status === 'COMMITTED' && $this->mode === 'COMMIT' ? $this->inserted_rows : 0);
    }

    protected function actualUpdatedRows(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->status === 'COMMITTED' && $this->mode === 'COMMIT' ? $this->updated_rows : 0);
    }

    protected function notWrittenRows(): Attribute
    {
        return Attribute::make(get: fn (): int => max(0, $this->total_rows - $this->actual_inserted_rows - $this->actual_updated_rows));
    }

    protected function plannedInsertedRows(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->mode === 'DRY_RUN' ? $this->inserted_rows : 0);
    }

    protected function plannedUpdatedRows(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->mode === 'DRY_RUN' ? $this->updated_rows : 0);
    }

    /**
     * Nguồn sự thật DUY NHẤT cho việc "nút tải file lỗi có sáng hay không".
     *
     * Trước đây FE phải tự suy ra từ `error_file_path` + tự so `error_file_expires_at`
     * với đồng hồ máy client; đồng hồ client không đáng tin (lệch giờ/timezone) nên nút
     * tải có thể sáng khi file đã hết hạn (404) hoặc tắt khi file vẫn còn hạn. Hạn dùng
     * phải được đánh giá tại server — cùng đúng một điều kiện mà
     * ImportReportController::downloadErrorFile() dùng để quyết định 200 hay 404.
     */
    protected function errorFileAvailable(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->error_file_path !== null
                && $this->error_file_expires_at !== null
                && $this->error_file_expires_at->isFuture(),
        );
    }
}
