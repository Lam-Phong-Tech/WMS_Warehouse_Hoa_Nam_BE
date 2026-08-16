<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyEvent extends Model
{
    public const EVENT_RECEIVE = 'RECEIVE';

    public const EVENT_TEMP_ONLY = 'TEMP_ONLY';

    public const EVENT_INSPECT = 'INSPECT';

    public const EVENT_REPAIR = 'REPAIR';

    public const EVENT_RETURN = 'RETURN';

    public const EVENT_CLOSE = 'CLOSE';

    protected $table = 'warranty_event';

    protected $primaryKey = 'warranty_event_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warranty_event_id',
        'warranty_case_id',
        'event_type',
        'from_status',
        'to_status',
        'note',
        'created_by',
        'event_at',
        'created_at',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(WarrantyCase::class, 'warranty_case_id', 'warranty_case_id');
    }
}
