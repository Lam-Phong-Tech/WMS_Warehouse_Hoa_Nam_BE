<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

class StocktakeSession extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COUNTED = 'COUNTED';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'stocktake_session';

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_id', 'session_no', 'warehouse_id', 'status', 'stocktake_started_at',
        'count_cutoff_at', 'reviewed_at', 'reviewed_by', 'movement_warning_flag',
        'applied_at', 'applied_by', 'created_by', 'session_version',
    ];

    protected $casts = [
        'stocktake_started_at' => 'datetime',
        'count_cutoff_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'movement_warning_flag' => 'boolean',
        'applied_at' => 'datetime',
        'session_version' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by', 'user_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(StocktakeSnapshot::class, 'session_id', 'session_id');
    }

    public function counts(): HasMany
    {
        return $this->hasMany(StocktakeCount::class, 'session_id', 'session_id');
    }

    public function variances(): HasMany
    {
        return $this->hasMany(StocktakeVariance::class, 'session_id', 'session_id');
    }
}
