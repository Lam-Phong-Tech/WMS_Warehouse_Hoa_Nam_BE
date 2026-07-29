<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InTransitLedger extends Model
{
    use HasUuids;

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_LOSS = 'completed_with_loss';

    public const STATUS_RETURN_IN_TRANSIT = 'return_in_transit';

    public const STATUS_RETURNED = 'returned';

    protected $table = 'in_transit_ledger';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stock_document_id', 'stock_document_line_id', 'product_id',
        'src_warehouse_id', 'dst_warehouse_id',
        'qty_dispatched', 'qty_received', 'qty_returned', 'qty_return_in_transit', 'qty_lost',
        'status', 'dispatched_at', 'received_at', 'returned_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StockDocumentLine::class, 'stock_document_line_id');
    }
}
