<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyCase extends Model
{
    use SoftDeletes;

    /**
     * SRS v1.12 §6 + ERD §0.2: đúng 6 trạng thái, DB CHECK
     * chk_erd_warranty_cases_2 enforce. INSPECTING/WAITING_CHECK/REPAIRED/TEMP_ONLY
     * là hằng số legacy đã gỡ — TEMP_ONLY là identification_status, không phải status.
     */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CHECKING = 'CHECKING';

    public const STATUS_REPAIRING = 'REPAIRING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** Chỉ còn để nhận diện dữ liệu legacy khi kiểm tra "case đã đóng". */
    public const STATUS_CLOSED = 'CLOSED';

    protected $table = 'warranty_case';

    protected $primaryKey = 'warranty_case_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'warranty_case_id',
        'warranty_case_code',
        'inventory_serial_id',
        'item_id',
        'product_id',
        'temporary_item_code',
        'identification_status',
        'missing_code_reason',
        'manual_product_description',
        'defect_id',
        'customer_name',
        'customer_phone',
        'status',
        'condition_status',
        'description',
        'created_by',
        'version',
        'closed_at',
        'retention_until',
        'legal_hold_flag',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'retention_until' => 'datetime',
        'legal_hold_flag' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(WarrantyEvent::class, 'warranty_case_id', 'warranty_case_id');
    }
}
