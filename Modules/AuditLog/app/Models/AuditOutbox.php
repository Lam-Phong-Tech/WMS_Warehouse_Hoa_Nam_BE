<?php

namespace Modules\AuditLog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditOutbox extends Model
{
    use HasUuids;

    protected $table = 'audit_outbox';
    protected $primaryKey = 'audit_outbox_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'payload', 'attempts', 'last_error', 'available_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
