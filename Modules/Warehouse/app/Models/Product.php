<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transitional compatibility model for code written before `products` was
 * migrated to the canonical `sku` aggregate.
 *
 * The backing relation is a database VIEW (products -> sku), so this class does
 * not introduce a second source of truth. New code must use Sku directly.
 */
class Product extends Model
{
    use HasUuids;

    protected $table = 'products';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name', 'sku_code', 'sku_name', 'sku_type', 'unit',
        'is_serial_tracked', 'brand_id', 'category_id', 'model_id',
        'power_or_voltage', 'package_variant', 'version', 'version_code',
        'warranty_applicable', 'specification', 'published_to_app', 'status',
    ];

    protected $casts = [
        'is_serial_tracked' => 'boolean',
        'warranty_applicable' => 'boolean',
        'published_to_app' => 'boolean',
        'specification' => 'array',
    ];

    protected $appends = ['sku_id', 'sku_code', 'sku_name', 'version'];

    public function getSkuIdAttribute(): ?string
    {
        return $this->attributes['id'] ?? null;
    }

    public function setSkuIdAttribute(?string $value): void
    {
        $this->attributes['id'] = $value;
    }

    public function getSkuCodeAttribute(): ?string
    {
        return $this->attributes['code'] ?? null;
    }

    public function setSkuCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value;
    }

    public function getSkuNameAttribute(): ?string
    {
        return $this->attributes['name'] ?? null;
    }

    public function setSkuNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
    }

    public function getVersionAttribute(): int
    {
        $versionCode = strtoupper((string) ($this->attributes['version_code'] ?? 'V1'));

        return preg_match('/^V(\d+)$/', $versionCode, $matches) === 1
            ? max(1, (int) $matches[1])
            : 1;
    }

    public function setVersionAttribute(mixed $value): void
    {
        $version = max(1, (int) $value);
        $this->attributes['version_code'] = 'V'.$version;
    }
}
