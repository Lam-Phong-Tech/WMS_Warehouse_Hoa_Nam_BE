<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockDocumentLine extends Model
{
    use HasUuids;

    protected $table = 'stock_document_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stock_document_id', 'line_no', 'product_id', 'qty_planned', 'qty_actual',
        'qty_posted', 'completion_type', 'shortage_reason',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inTransitLedger(): HasOne
    {
        return $this->hasOne(InTransitLedger::class);
    }
}
