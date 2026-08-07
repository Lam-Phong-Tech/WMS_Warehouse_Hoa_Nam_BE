<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySerial extends Model
{
    use HasUuids;

    public const STATUS_IN_STOCK = 'IN_STOCK';

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';

    public const STATUS_RETURNED_TO_CUSTOMER = 'RETURNED_TO_CUSTOMER';

    protected $table = 'inventory_serials';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'warehouse_id', 'serial_no', 'physical_code_id',
        'condition', 'stock_condition', 'item_condition', 'status',
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
