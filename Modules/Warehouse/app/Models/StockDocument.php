<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\User;

class StockDocument extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_TRANSFER = 'transfer';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_PARTIALLY_POSTED = 'partially_posted';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    // Trạng thái riêng cho doc_type=transfer.
    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RETURN_IN_TRANSIT = 'return_in_transit';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_LOSS = 'completed_with_loss';

    public const STATUS_RETURNED = 'returned';

    protected $table = 'stock_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'doc_type', 'doc_no', 'doc_date', 'status', 'src_warehouse_id', 'dst_warehouse_id',
        'note', 'create_idempotency_key',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'posted_by', 'posted_at', 'closed_by', 'closed_at',
        'reversed_by', 'reversed_at', 'reverse_reason',
        'reject_reason', 'rejected_by', 'rejected_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'closed_at' => 'datetime',
        'reversed_at' => 'datetime',
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
        return $this->hasMany(StockDocumentLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DocumentStatusHistory::class);
    }

    public function inTransitLedgers(): HasMany
    {
        return $this->hasMany(InTransitLedger::class, 'document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
