<?php

namespace Modules\Notification\Delivery;

use Illuminate\Queue\InteractsWithQueue;

trait NotificationDispatchIdentity
{
    use InteractsWithQueue;

    /**
     * Keep notifications on the existing queue until a dedicated worker is
     * deployed. Set NOTIFICATION_QUEUE=notifications only after that worker
     * consumes the queue.
     */
    public function viaQueue(): string
    {
        return (string) config('queue.notification_queue', 'default');
    }

    protected function notificationDispatchKey(): ?string
    {
        $uuid = isset($this->job) ? $this->job->uuid() : null;

        return is_string($uuid) && $uuid !== '' ? 'notification-listener:'.static::class.':'.$uuid : null;
    }
}
