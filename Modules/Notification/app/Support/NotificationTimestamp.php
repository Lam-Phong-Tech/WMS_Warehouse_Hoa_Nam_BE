<?php

namespace Modules\Notification\Support;

use App\Support\DocumentTimestamp;
use DateTimeInterface;

/** Notifications use timestamptz: never bind an offset-free local wall clock. */
final class NotificationTimestamp
{
    public const CONTRACT = 'notification-timestamps-v1';

    public static function write(DateTimeInterface $value): string
    {
        return DocumentTimestamp::instant($value)->format('Y-m-d H:i:s.uP');
    }

    public static function read(mixed $value): ?string
    {
        return DocumentTimestamp::utc($value, DocumentTimestamp::LEGACY_TIMEZONE);
    }
}
