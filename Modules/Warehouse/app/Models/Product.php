<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stub SKU tối giản — xem ghi chú nâng cấp trong migration
 * 2026_01_01_000031_create_products_table.php.
 */
class Product extends Model
{
    use HasUuids;

    protected $table = 'products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'sku_code', 'name', 'unit', 'sku_type',
        'brand_code', 'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'version', 'published_to_app', 'is_serial_tracked', 'status',
    ];

    protected $casts = [
        'version' => 'integer',
        'published_to_app' => 'boolean',
        'is_serial_tracked' => 'boolean',
    ];
}
