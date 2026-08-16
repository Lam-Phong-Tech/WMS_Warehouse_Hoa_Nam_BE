<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\Concerns\ScopesWarehouseData;

class InventoryBalance extends Model
{
    use HasUuids, ScopesWarehouseData;

    protected $table = 'inventory_balances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warehouse_id', 'product_id', 'sku_id', 'stock_status',
        'quantity', 'on_hand_qty', 'available_qty', 'reserved_qty', 'version',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'on_hand_qty' => 'integer',
        'available_qty' => 'integer',
        'reserved_qty' => 'integer',
        'version' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }
}
