<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Concerns\ScopesWarehouseData;

class StockMovement extends Model
{
    use HasUuids, ScopesWarehouseData;

    public const OPENING = 'OPENING';

    public const RECEIPT_POSTED = 'RECEIPT';

    public const RECEIPT_IN = self::RECEIPT_POSTED;

    public const ISSUE_POSTED = 'ISSUE';

    public const ISSUE_OUT = self::ISSUE_POSTED;

    public const COMPONENT_ISSUE = 'COMPONENT_ISSUE';

    public const REVERSAL = 'REVERSAL';

    public const RECEIPT_REVERSED = self::REVERSAL;

    public const ISSUE_REVERSED = self::REVERSAL;

    protected $table = 'stock_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'movement_no', 'business_key', 'movement_type', 'warehouse_id', 'product_id', 'sku_id', 'stock_status',
        'quantity', 'qty_before', 'qty_delta', 'qty_after',
        'stock_document_id', 'stock_document_line_id',
        'source_type', 'source_id', 'source_line_id', 'item_id', 'container_id',
        'original_movement_id', 'correction_chain_id', 'idempotency_record_id',
        'created_by', 'request_id', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'qty_before' => 'integer',
        'qty_delta' => 'integer',
        'qty_after' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('stock_movements is append-only and cannot be updated.'));
        static::deleting(fn () => throw new \LogicException('stock_movements is append-only and cannot be deleted.'));
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

    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
