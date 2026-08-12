<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasUuids;

    protected $table = 'product_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
