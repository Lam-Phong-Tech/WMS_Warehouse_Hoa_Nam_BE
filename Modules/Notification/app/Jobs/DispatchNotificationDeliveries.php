<?php
namespace Modules\Notification\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\NotificationOutbox;
final class DispatchNotificationDeliveries implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public function handle(NotificationOutbox $outbox,FcmHttpSender $sender):void{$outbox->drain($sender);}
}
