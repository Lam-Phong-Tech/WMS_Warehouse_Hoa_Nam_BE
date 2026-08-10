<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PowerSource extends Model
{
    use HasUuids;

    protected $table = 'power_sources';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
