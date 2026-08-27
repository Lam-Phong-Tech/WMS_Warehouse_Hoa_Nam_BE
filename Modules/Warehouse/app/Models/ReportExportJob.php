<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportExportJob extends Model
{
    use HasUuids;

    protected $table = 'report_export_jobs';

    protected $primaryKey = 'export_job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'export_job_id', 'report_code', 'format', 'filters', 'requested_by',
        'status', 'row_count', 'file_path', 'file_name',
        'error_code', 'error_message', 'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'row_count' => 'integer',
        'expires_at' => 'datetime',
    ];
}
