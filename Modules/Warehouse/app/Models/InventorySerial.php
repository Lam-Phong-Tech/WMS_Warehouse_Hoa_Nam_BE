<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySerial extends Model
{
    use HasUuids;

    protected $table = 'inventory_serials';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['product_id', 'warehouse_id', 'serial_no', 'status'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
