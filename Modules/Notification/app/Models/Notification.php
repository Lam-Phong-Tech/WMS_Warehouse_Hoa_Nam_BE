<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Notification\Support\PreservesNotificationTimestampOffset;

class Notification extends Model
{
    use HasUuids;
    use PreservesNotificationTimestampOffset;

    public $timestamps = false;

    protected $table = 'notifications';

    protected $fillable = [
        'event_key', 'title', 'body', 'severity', 'source_module',
        'subject_type', 'subject_id', 'actor_id', 'actor_name', 'payload', 'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /** Bảng append-only: mọi thay đổi nội dung phải là notification mới, không sửa lại notification cũ. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('notifications is append-only and cannot be updated.'));
        static::deleting(fn () => throw new \LogicException('notifications is append-only and cannot be deleted.'));
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'user_id');
    }
}
