<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalCode extends Model
{
    use HasUuids;

    protected $table = 'physical_codes';

    protected $primaryKey = 'physical_code_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code_value', 'code_type', 'carrier_type', 'hardware_uid',
        'encoded_payload_version', 'status', 'replaced_by_code_id',
    ];

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_code_id', 'physical_code_id');
    }
}
