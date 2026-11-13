<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Delivery\FcmHttpSender;
use Modules\Notification\Delivery\NotificationOutbox;
use Throwable;

final class DispatchNotificationDeliveries implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue((string) config('queue.notification_delivery_queue', 'notification-delivery'));
    }

    public function uniqueId(): string
    {
        return 'notification-delivery:drain:v1';
    }

    public function handle(NotificationOutbox $outbox, FcmHttpSender $sender): void
    {
        $outbox->drain($sender);
        if (($next = $outbox->nextWakeAt()) !== null) {
            try {
                $outbox->wake($next);
            } catch (Throwable $error) {
                Log::warning('Notification delivery continuation deferred', ['exception' => $error::class]);
            }
        }
    }
}
