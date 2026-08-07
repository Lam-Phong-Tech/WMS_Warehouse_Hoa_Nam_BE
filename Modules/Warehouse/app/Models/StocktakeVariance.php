<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

class StocktakeVariance extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPLIED = 'APPLIED';

    protected $table = 'stocktake_variance';

    protected $primaryKey = 'variance_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'variance_id', 'session_id', 'grain_type', 'grain_id', 'grain_key', 'product_id',
        'stock_status', 'inventory_serial_id', 'container_id', 'container_item_id',
        'expected_qty', 'expected_at_count', 'actual_qty', 'variance_qty',
        'inventory_effect_qty', 'balance_qty_at_review', 'balance_version_at_review',
        'detected_movement_ids', 'acknowledged_movement_ids', 'acknowledged_by',
        'acknowledged_at', 'decision', 'status', 'approved_by', 'applied_movement_id',
        'applied_at',
    ];

    protected $casts = [
        'expected_qty' => 'decimal:3',
        'expected_at_count' => 'decimal:3',
        'actual_qty' => 'decimal:3',
        'variance_qty' => 'decimal:3',
        'inventory_effect_qty' => 'decimal:3',
        'balance_qty_at_review' => 'decimal:3',
        'balance_version_at_review' => 'integer',
        'detected_movement_ids' => 'array',
        'acknowledged_movement_ids' => 'array',
        'acknowledged_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class, 'session_id', 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by', 'user_id');
    }

    public function appliedMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'applied_movement_id');
    }
}
