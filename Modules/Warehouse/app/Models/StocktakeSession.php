<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StocktakeSession extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COUNTED = 'COUNTED';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'stocktake_session';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_no',
        'warehouse_id',
        'status',
        'stocktake_started_at',
        'count_cutoff_at',
        'reviewed_at',
        'applied_at',
        'closed_at',
        'created_by',
        'session_version',
    ];

    protected $casts = [
        'stocktake_started_at' => 'datetime',
        'count_cutoff_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

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