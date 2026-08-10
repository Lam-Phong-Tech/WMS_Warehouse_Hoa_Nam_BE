<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    use HasUuids;

    protected $table = 'product_models';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
