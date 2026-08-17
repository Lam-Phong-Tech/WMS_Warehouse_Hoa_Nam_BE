<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Exceptions\WarehouseException;

class StockDocument extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_TRANSFER = 'transfer';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SCANNING = 'SCANNING';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_RECEIVED = self::STATUS_POSTED;

    public const STATUS_ISSUED = self::STATUS_POSTED;

    public const STATUS_REVERSED = 'REVERSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_REJECTED = 'REJECTED';

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

    /**
     * SRS Annex A / Annex D SCR-IN-01 gọi nguồn nhập là `source_reference`; ERD
     * bảng 26 lưu cột `source_name`. List/detail phải trả cả hai tên để client đọc
     * đúng field trong contract mà không phải đổi schema vật lý.
     */
    protected $appends = ['source_reference'];

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

    protected static function booted(): void
    {
        static::updating(function (StockDocument $document) {
            if (! $document->isDirty('status')) {
                return;
            }

            $currentStatus = DB::table($document->getTable())
                ->where($document->getKeyName(), $document->getKey())
                ->lockForUpdate()
                ->value('status');

            if ($currentStatus !== null && $currentStatus !== $document->getOriginal('status')) {
                WarehouseException::versionConflict();
            }
        });
    }

    public function getSourceReferenceAttribute(): ?string
    {
        $value = $this->attributes['source_name'] ?? null;

        return $value === null ? null : (string) $value;
    }

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
