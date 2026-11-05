<?php
namespace Modules\Notification\Console\Commands;
use Illuminate\Console\Command;
use Modules\Notification\Delivery\PruneNotificationDeliveryState;
class PruneNotificationDeliveryStateCommand extends Command
{
 protected $signature='notification-delivery:prune {--limit=250}';
 protected $description='Erase expired FCM tokens and prune 30-day transport audits; preserve P13 and installation fences.';
 public function handle(PruneNotificationDeliveryState $pruner):int{$limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT);if($limit===false||$limit<1||$limit>1000){$this->error('NOTIFICATION_PRUNE_LIMIT_INVALID');return self::FAILURE;}$this->line(json_encode($pruner->prune($limit),JSON_THROW_ON_ERROR));return self::SUCCESS;}
}
