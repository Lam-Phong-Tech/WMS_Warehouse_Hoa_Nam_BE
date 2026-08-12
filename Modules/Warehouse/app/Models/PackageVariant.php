<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PackageVariant extends Model
{
    use HasUuids;

    protected $table = 'package_variants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
