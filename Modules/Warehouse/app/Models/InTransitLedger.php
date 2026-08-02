<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InTransitLedger extends Model
{
    use HasUuids;

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RETURN_IN_TRANSIT = 'return_in_transit';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_LOSS = 'completed_with_loss';

    public const STATUS_RETURNED = 'returned';

    protected $table = 'in_transit_ledger';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id', 'document_line_id', 'product_id',
        'src_warehouse_id', 'dst_warehouse_id',
        'qty_dispatched', 'qty_received', 'qty_return_in_transit', 'qty_returned', 'qty_lost',
        'status', 'dispatched_at', 'received_at', 'returned_at',
    ];

    protected $casts = [
        'qty_dispatched' => 'decimal:3',
        'qty_received' => 'decimal:3',
        'qty_return_in_transit' => 'decimal:3',
        'qty_returned' => 'decimal:3',
        'qty_lost' => 'decimal:3',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'document_id');
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(StockDocumentLine::class, 'document_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }

    public function srcWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'src_warehouse_id');
    }

    public function dstWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'dst_warehouse_id');
    }
}
