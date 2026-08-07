<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DefectCatalog extends Model
{
    use HasUuids;

    protected $table = 'defect_catalogs';

    protected $primaryKey = 'defect_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['defect_code', 'defect_name', 'description', 'status'];
}
