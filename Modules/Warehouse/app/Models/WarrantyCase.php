<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyCase extends Model
{
    use SoftDeletes;

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_INSPECTING = 'INSPECTING';

    public const STATUS_WAITING_CHECK = 'WAITING_CHECK';

    public const STATUS_TEMP_ONLY = 'TEMP_ONLY';

    public const STATUS_REPAIRED = 'REPAIRED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'warranty_case';

    protected $primaryKey = 'warranty_case_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warranty_case_code',
        'inventory_serial_id',
        'defect_id',
        'customer_name',
        'customer_phone',
        'status',
        'condition_status',
        'description',
        'created_by',
        'version',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(WarrantyEvent::class, 'warranty_case_id', 'warranty_case_id');
    }
}
