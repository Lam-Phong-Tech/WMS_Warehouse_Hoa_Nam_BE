<?php

namespace Modules\AuditLog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'action', 'object_type', 'object_id', 'reason',
        'request_id', 'idempotency_key', 'result', 'error_code',
        'module', 'ip_address', 'before_data', 'after_data', 'created_at',
    ];

    protected $appends = ['description'];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'created_at' => 'datetime',
    ];

    /** app_user tương ứng actor_id — nullable vì hành động hệ thống/job không có actor. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'user_id');
    }

    /**
     * Mô tả người-đọc-được cho UI khi caller không truyền `reason` — a.k.a mọi
     * call site cũ (130+ chỗ) không có concept "description" riêng, chỉ có
     * action + object_type + object_id. Ưu tiên `reason` thật (DenyAuditRecorder,
     * một số controller có truyền) trước khi tự tổng hợp.
     */
    public function getDescriptionAttribute(): string
    {
        if (! empty($this->reason)) {
            return (string) $this->reason;
        }

        $object = trim(($this->object_type ?? '').($this->object_id ? ' #'.$this->object_id : ''));

        return trim(($this->action ?? '').($object !== '' ? ' '.$object : ''));
    }

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
