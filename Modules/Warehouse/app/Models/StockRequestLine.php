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

    protected $fillable = ['stock_request_id', 'line_no', 'product_id', 'qty_planned', 'note'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
