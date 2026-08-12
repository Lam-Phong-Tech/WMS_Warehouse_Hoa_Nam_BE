<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasUuids;

    protected $table = 'brands';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
