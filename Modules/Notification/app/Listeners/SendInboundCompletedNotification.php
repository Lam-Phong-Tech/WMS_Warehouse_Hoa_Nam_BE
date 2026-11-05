<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\InboundCompleted;

class SendInboundCompletedNotification implements ShouldQueue
{
    use \Modules\Notification\Delivery\NotificationDispatchIdentity;

    /** Chỉ chạy sau khi transaction Post Receipt đã commit — tránh báo nhầm khi rollback. */
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(InboundCompleted $event): void
    {
        $userIds = $this->audience->byPermission('inbound.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'inbound.completed',
            title: 'Đã hoàn tất nhập kho',
            body: "Phiếu nhập {$event->docNo} đã Post Receipt thành công.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: ['document_id' => $event->documentId, 'doc_no' => $event->docNo, 'warehouse_id' => $event->warehouseId],
            actorId: $event->actorId,
            dispatchKey: $this->notificationDispatchKey(),
        );
    }
}
