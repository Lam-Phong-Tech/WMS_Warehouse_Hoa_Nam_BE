<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasUuids;

    protected $table = 'warehouses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'address', 'contact_person', 'phone', 'status', 'suspension_reason'];
}
