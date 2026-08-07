<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemInstance extends Model
{
    use HasUuids;

    protected $table = 'item_instances';

    protected $primaryKey = 'item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sku_id', 'physical_code_id', 'serial_number', 'container_id',
        'item_status', 'item_condition', 'import_batch_id', 'version',
    ];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'sku_id', 'sku_id');
    }

    public function physicalCode(): BelongsTo
    {
        return $this->belongsTo(PhysicalCode::class, 'physical_code_id', 'physical_code_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class, 'container_id', 'container_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id', 'import_batch_id');
    }
}
