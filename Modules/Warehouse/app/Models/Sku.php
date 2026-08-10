<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sku extends Model
{
    use HasUuids;

    protected $table = 'sku';

    protected $primaryKey = 'sku_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sku_code', 'sku_name', 'sku_type', 'unit', 'is_serial_tracked',
        'brand_id', 'category_id', 'model_id', 'power_or_voltage', 'package_variant',
        'version_code', 'warranty_applicable', 'specification', 'published_to_app', 'status',
        'code', 'name',
    ];

    protected $casts = [
        'is_serial_tracked' => 'boolean',
        'warranty_applicable' => 'boolean',
        'published_to_app' => 'boolean',
        'specification' => 'array',
    ];

    protected $appends = ['code', 'name', 'id'];

    public function getCodeAttribute()
    {
        return $this->sku_code;
    }

    public function setCodeAttribute($value)
    {
        $this->attributes['sku_code'] = $value;
    }

    public function getNameAttribute()
    {
        return $this->sku_name;
    }

    public function setNameAttribute($value)
    {
        $this->attributes['sku_name'] = $value;
    }

    public function getIdAttribute()
    {
        return $this->sku_id;
    }

    public function setIdAttribute($value)
    {
        $this->sku_id = $value;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductMaster::class, 'brand_id', 'master_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductMaster::class, 'category_id', 'master_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductMaster::class, 'model_id', 'master_id');
    }
}
