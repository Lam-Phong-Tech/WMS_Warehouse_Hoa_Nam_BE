<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\ReversalPosted;

class SendReversalPostedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(ReversalPosted $event): void
    {
        $userIds = $this->audience->byPermission('inventory.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'reversal.posted',
            title: 'Đã Post điều chỉnh tồn kho',
            body: "Reversal {$event->docNo} đã Post; movement điều chỉnh đã ghi.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'reversal_document',
            subjectId: $event->reversalId,
            payload: ['reversal_id' => $event->reversalId, 'doc_no' => $event->docNo, 'warehouse_id' => $event->warehouseId],
        );
    }
}
