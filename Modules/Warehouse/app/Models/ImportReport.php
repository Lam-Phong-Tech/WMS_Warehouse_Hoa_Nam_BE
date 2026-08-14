<?php

namespace Modules\Warehouse\Models;

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
    ];

    protected $casts = [
        'row_errors' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'inserted_rows' => 'integer',
        'updated_rows' => 'integer',
    ];
}
