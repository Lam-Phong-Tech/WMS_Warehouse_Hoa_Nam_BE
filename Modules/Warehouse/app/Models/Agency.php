<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    use HasUuids;

    protected $table = 'agencies';

    protected $primaryKey = 'agency_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['agency_code', 'agency_name', 'phone', 'address', 'status'];
}
