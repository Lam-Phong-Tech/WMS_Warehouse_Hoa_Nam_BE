<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDocumentLine extends Model
{
    use HasUuids;

    protected $table = 'stock_document_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stock_document_id', 'line_no', 'product_id', 'item_instance_id', 'qty_planned', 'qty_actual', 'scanned_qty',
        'completion_type', 'shortage_reason',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }

    public function itemInstance(): BelongsTo
    {
        return $this->belongsTo(ItemInstance::class, 'item_instance_id', 'item_id');
    }
}
