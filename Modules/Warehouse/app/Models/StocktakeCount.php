<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeCount extends Model
{
    protected $table = 'stocktake_count';

    protected $primaryKey = 'count_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'count_id', 'session_id', 'grain_type', 'grain_id', 'grain_key', 'product_id',
        'stock_status', 'inventory_serial_id', 'container_id', 'container_item_id',
        'scan_batch_id', 'actual_qty', 'counted_by', 'counted_at', 'count_cutoff_at',
    ];

    protected $casts = [
        'actual_qty' => 'decimal:3',
        'counted_at' => 'datetime',
        'count_cutoff_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class, 'session_id', 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inventorySerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }
}
