<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeStockConfig extends Model
{
    use HasUuids;

    protected $table = 'safe_stock_configs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['product_id', 'warehouse_id', 'min_qty', 'max_qty', 'effective_from', 'effective_to'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
