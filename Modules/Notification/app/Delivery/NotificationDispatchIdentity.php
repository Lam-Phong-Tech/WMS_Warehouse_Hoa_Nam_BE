<?php
namespace Modules\Notification\Delivery;
use Illuminate\Queue\InteractsWithQueue;
trait NotificationDispatchIdentity
{
    use InteractsWithQueue;
    protected function notificationDispatchKey(): ?string
    {
        $uuid=isset($this->job)?$this->job->uuid():null;
        return is_string($uuid)&&$uuid!==''?'notification-listener:'.static::class.':'.$uuid:null;
    }
}
