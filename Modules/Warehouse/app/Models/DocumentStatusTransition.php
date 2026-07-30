<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Config tham khảo cho state machine — không phải nguồn enforcement (xem StockDocumentService). */
class DocumentStatusTransition extends Model
{
    use HasUuids;

    protected $table = 'document_status_transitions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['doc_type', 'from_status', 'to_status', 'action', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
