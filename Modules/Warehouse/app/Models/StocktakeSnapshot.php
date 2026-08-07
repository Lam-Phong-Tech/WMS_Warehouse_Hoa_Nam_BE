<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeSnapshot extends Model
{
    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_DAMAGED = 'DAMAGED';

    public const STATUS_RESERVED = 'RESERVED';

    protected $table = 'stocktake_snapshot';

    protected $primaryKey = 'snapshot_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'session_id', 'grain_type', 'grain_id', 'grain_key', 'product_id',
        'stock_status', 'inventory_serial_id', 'container_id', 'container_item_id',
        'snapshot_qty', 'stocktake_started_at', 'created_at',
    ];

    protected $casts = [
        'snapshot_qty' => 'decimal:3',
        'stocktake_started_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class, 'session_id', 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
