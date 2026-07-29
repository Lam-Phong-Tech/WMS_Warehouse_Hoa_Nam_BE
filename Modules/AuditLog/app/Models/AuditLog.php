<?php

namespace Modules\AuditLog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'action', 'object_type', 'object_id', 'reason',
        'request_id', 'before_data', 'after_data', 'created_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('audit_log là append-only, không được update.');
        });

        static::deleting(function () {
            throw new \LogicException('audit_log là append-only, không được xoá.');
        });
    }
}
