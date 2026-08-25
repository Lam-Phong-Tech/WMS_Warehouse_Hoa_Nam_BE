<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    use HasUuids;

    public const RECIPIENT_TYPES = ['DISTRIBUTOR', 'AGENCY', 'PROJECT_CUSTOMER', 'RETAIL_CUSTOMER'];

    protected $table = 'agencies';
    protected $primaryKey = 'agency_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'agency_code', 'agency_name', 'recipient_type', 'contact_name', 'phone', 'address', 'status',
    ];
}
