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
        'brand_id', 'category_id', 'model_id', 'power_source_id', 'package_variant_id',
        'power_or_voltage', 'package_variant', 'version', 'version_code',
        'warranty_applicable', 'specification', 'published_to_app', 'status',
        'code', 'name', 'min_stock', 'max_stock',
        'family_name', 'variant_label',
    ];

    protected $casts = [
        'is_serial_tracked' => 'boolean',
        'warranty_applicable' => 'boolean',
        'published_to_app' => 'boolean',
        'specification' => 'array',
        'version' => 'integer',
        'min_stock' => 'float',
        'max_stock' => 'float',
    ];

    protected $appends = ['id', 'code', 'name'];

    public function getIdAttribute(): ?string
    {
        return $this->attributes['sku_id'] ?? null;
    }

    public function getCodeAttribute(): ?string
    {
        return $this->attributes['sku_code'] ?? null;
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['sku_code'] = $value;
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['sku_name'] ?? null;
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['sku_name'] = $value;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id');
    }

    public function powerSource(): BelongsTo
    {
        return $this->belongsTo(PowerSource::class, 'power_source_id', 'id');
    }

    public function packageVariant(): BelongsTo
    {
        return $this->belongsTo(PackageVariant::class, 'package_variant_id', 'id');
    }
}
