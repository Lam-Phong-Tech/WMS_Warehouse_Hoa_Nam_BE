<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use HasUuids;

    protected $table = 'import_batches';

    protected $primaryKey = 'import_batch_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'import_batch_code', 'shipment_container_code', 'supplier_batch_no',
        'import_period', 'supplier_name', 'status',
    ];
}
