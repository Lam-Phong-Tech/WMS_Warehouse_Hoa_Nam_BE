<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeCount extends Model
{
    protected $table = 'stocktake_count';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'count_id',
        'session_id',
        'product_id',
        'sku_id',
        'stock_status',
        'item_id',
        'container_id',
        'scan_batch_id',
        'expected_qty',
        'actual_qty',
        'variance_qty',
        'counted_by',
        'counted_at',
        'count_cutoff_at',
    ];

    protected $casts = [
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
}