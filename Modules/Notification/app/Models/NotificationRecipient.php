<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;
use Modules\Notification\Support\PreservesNotificationTimestampOffset;

class NotificationRecipient extends Model
{
    use HasUuids;
    use PreservesNotificationTimestampOffset;

    public $timestamps = false;

    protected $table = 'notification_recipients';

    protected $fillable = ['notification_id', 'user_id', 'read_at', 'created_at'];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
