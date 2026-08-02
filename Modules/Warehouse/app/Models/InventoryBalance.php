<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasUuids;

    protected $table = 'inventory_balances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['warehouse_id', 'product_id', 'on_hand_qty', 'available_qty', 'reserved_qty', 'version'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }
}
