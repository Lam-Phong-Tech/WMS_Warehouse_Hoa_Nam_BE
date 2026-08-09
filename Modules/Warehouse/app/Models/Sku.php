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
    ];

    protected $casts = [
        'is_serial_tracked' => 'boolean',
        'warranty_applicable' => 'boolean',
        'published_to_app' => 'boolean',
        'specification' => 'array',
    ];

    // /products is a legacy façade over this canonical aggregate. Exposing read-only
    // aliases keeps old API/tests interoperable without creating legacy persistence.
    protected $appends = ['id', 'code', 'name', 'version'];

    public function getIdAttribute(): ?string
    {
        return $this->attributes['sku_id'] ?? null;
    }

    public function getCodeAttribute(): ?string
    {
        return $this->attributes['sku_code'] ?? null;
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['sku_name'] ?? null;
    }

    public function getVersionAttribute(): int
    {
        $versionCode = strtoupper((string) ($this->attributes['version_code'] ?? 'V1'));

        return preg_match('/^V(\d+)$/', $versionCode, $matches) === 1
            ? max(1, (int) $matches[1])
            : 1;
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
