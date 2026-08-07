<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestLine extends Model
{
    use HasUuids;

    protected $table = 'stock_request_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['request_id', 'line_no', 'product_id', 'qty_planned', 'unit_cost', 'note'];

    protected $casts = [
        'line_no' => 'integer',
        'qty_planned' => 'decimal:3',
        'unit_cost' => 'decimal:3',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }
}
