<?php

namespace Modules\Notification\Support;

use App\Support\DocumentTimestamp;

trait PreservesNotificationTimestampOffset
{
    public function fromDateTime($value)
    {
        // Covers Eloquent create/setAttribute; query-builder updates bind separately.
        return empty($value) ? $value : NotificationTimestamp::write(
            DocumentTimestamp::instant($value, DocumentTimestamp::LEGACY_TIMEZONE),
        );
    }
}
