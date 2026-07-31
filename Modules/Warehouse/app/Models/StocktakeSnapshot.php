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

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'snapshot_id',
        'session_id',
        'product_id',
        'stock_status',
        'snapshot_qty',
        'stocktake_started_at',
        'created_at',
    ];

    protected $casts = [
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