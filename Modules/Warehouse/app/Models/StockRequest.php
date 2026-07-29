<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockRequest extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_TRANSFER = 'transfer';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'stock_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'request_type', 'request_no', 'request_date', 'status', 'src_warehouse_id', 'dst_warehouse_id',
        'note', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'reject_reason', 'rejected_by', 'rejected_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at', 'converted_doc_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'request_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function srcWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'src_warehouse_id');
    }

    public function dstWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'dst_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class);
    }

    public function convertedDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'converted_doc_id');
    }
}
