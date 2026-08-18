<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
    protected $hidden = ['error_file_path'];

    /** @var list<string> */
    protected $appends = ['error_file_available'];

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
