<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\User;

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
        'request_no', 'request_type', 'request_date', 'status',
        'src_warehouse_id', 'dst_warehouse_id', 'note',
        'created_by', 'updated_by',
        'submitted_by', 'submitted_at',
        'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'reject_reason',
        'converted_doc_id',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
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
        return $this->hasMany(StockRequestLine::class, 'request_id');
    }

    public function convertedDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'converted_doc_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
