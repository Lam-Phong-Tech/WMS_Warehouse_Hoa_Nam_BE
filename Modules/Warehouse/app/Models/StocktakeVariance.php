<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeVariance extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPLIED = 'APPLIED';

    protected $table = 'stocktake_variance';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'variance_id',
        'session_id',
        'product_id',
        'sku_id',
        'stock_status',
        'expected_qty',
        'actual_qty',
        'variance_qty',
        'decision',
        'status',
        'approved_by',
        'applied_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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