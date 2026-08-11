<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ledger — chặn update/delete ở tầng Eloquent, không chỉ ở DB. */
class StockMovement extends Model
{
    use HasUuids;

    public const OPENING = 'OPENING';

    public const RECEIPT_POSTED = 'RECEIPT_POSTED';

    public const RECEIPT_IN = self::RECEIPT_POSTED;

    public const ISSUE_POSTED = 'ISSUE_POSTED';

    public const ISSUE_OUT = self::ISSUE_POSTED;

    public const ADJUST_IN = 'ADJUST_IN';

    public const ADJUST_OUT = 'ADJUST_OUT';

    public const RECEIPT_REVERSED = 'RECEIPT_REVERSED';

    public const ISSUE_REVERSED = 'ISSUE_REVERSED';

    public const TRANSFER_OUT = 'TRANSFER_OUT';

    public const TRANSFER_IN = 'TRANSFER_IN';

    public const TRANSFER_RETURN = 'TRANSFER_RETURN';

    protected $table = 'stock_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'movement_no', 'business_key', 'movement_type', 'warehouse_id', 'product_id', 'stock_status',
        'qty_before', 'qty_delta', 'qty_after', 'stock_document_id', 'stock_document_line_id',
        'original_movement_id', 'created_by', 'request_id', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('stock_movements là append-only ledger, không được update.');
        });

        static::deleting(function () {
            throw new \LogicException('stock_movements là append-only ledger, không được xoá.');
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'product_id', 'sku_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(StockDocumentLine::class, 'stock_document_line_id');
    }
}
