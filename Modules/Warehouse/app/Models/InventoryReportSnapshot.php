<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReportSnapshot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'inventory_report_snapshots';

    protected $fillable = [
        'snapshot_date', 'period_type', 'period_start', 'period_end',
        'warehouse_id', 'product_id',
        'opening_qty', 'receipt_qty', 'issue_qty',
        'transfer_in_qty', 'transfer_out_qty',
        'adjustment_in_qty', 'adjustment_out_qty',
        'closing_qty', 'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_qty' => 'decimal:3',
        'receipt_qty' => 'decimal:3',
        'issue_qty' => 'decimal:3',
        'transfer_in_qty' => 'decimal:3',
        'transfer_out_qty' => 'decimal:3',
        'adjustment_in_qty' => 'decimal:3',
        'adjustment_out_qty' => 'decimal:3',
        'closing_qty' => 'decimal:3',
        'created_at' => 'datetime',
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
