<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** GĐ1-REQ-011: phòng ban master gán cho user trong IAM. */
class Department extends Model
{
    use HasUuids;

    protected $table = 'department';

    protected $primaryKey = 'department_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'department_code', 'department_name', 'status', 'description',
    ];
}
