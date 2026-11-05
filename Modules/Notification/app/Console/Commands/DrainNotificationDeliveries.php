<?php
namespace Modules\Notification\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\NotificationOutbox;
final class DrainNotificationDeliveries extends Command
{
    protected $signature='notification-delivery:drain {--limit=50}';
    protected $description='Drain committed delivery outbox with scoped leases, no business mutation.';
    public function handle(NotificationOutbox $outbox,FcmHttpSender $sender):int
    {
        if(!Schema::hasTable('notification_deliveries')){$this->line('Notification delivery schema not ready.');return self::SUCCESS;}
        $count=$outbox->drain($sender,(int)$this->option('limit'));
        $this->line('Notification provider attempts: '.$count);return self::SUCCESS;
    }
}
