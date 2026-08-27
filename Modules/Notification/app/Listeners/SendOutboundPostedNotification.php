<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\OutboundPosted;

class SendOutboundPostedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(OutboundPosted $event): void
    {
        $userIds = $this->audience->byPermission('outbound.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'outbound.posted',
            title: 'Đã hoàn tất xuất kho',
            body: "Phiếu xuất {$event->docNo} đã Post Issue thành công; tồn kho đã được trừ.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: ['document_id' => $event->documentId, 'doc_no' => $event->docNo, 'warehouse_id' => $event->warehouseId],
            actorId: $event->actorId,
        );
    }
}
