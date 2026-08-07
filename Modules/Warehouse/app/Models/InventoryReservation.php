<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Non-MVP: bảng để sẵn, chưa wire vào engine xuất kho. */
class InventoryReservation extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_RELEASED = 'released';

    protected $table = 'inventory_reservations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warehouse_id', 'product_id', 'quantity',
        'source_type', 'source_id',
        'status', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'expires_at' => 'datetime',
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
