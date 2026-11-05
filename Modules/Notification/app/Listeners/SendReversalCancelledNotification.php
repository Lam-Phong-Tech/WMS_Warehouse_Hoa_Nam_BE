<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\ReversalCancelled;

class SendReversalCancelledNotification implements ShouldQueue
{
    use \Modules\Notification\Delivery\NotificationDispatchIdentity;

    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(ReversalCancelled $event): void
    {
        $userIds = $this->audience->byPermission('inventory.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'reversal.cancelled',
            title: 'Đã hủy phiếu điều chỉnh',
            body: "Reversal {$event->docNo} đã bị hủy.",
            userIds: $userIds,
            severity: 'warning',
            sourceModule: 'warehouse',
            subjectType: 'reversal_document',
            subjectId: $event->reversalId,
            payload: ['reversal_id' => $event->reversalId, 'doc_no' => $event->docNo, 'warehouse_id' => $event->warehouseId],
            actorId: $event->actorId,
            dispatchKey: $this->notificationDispatchKey(),
        );
    }
}
