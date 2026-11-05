<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\StockDocumentScanStarted;

class SendStockDocumentScanStartedNotification implements ShouldQueue
{
    use \Modules\Notification\Delivery\NotificationDispatchIdentity;

    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(StockDocumentScanStarted $event): void
    {
        $isReceipt = $event->type === 'receipt';
        $userIds = $this->audience->byPermission($isReceipt ? 'inbound.view' : 'outbound.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: $isReceipt ? 'inbound.scan_started' : 'outbound.scan_started',
            title: 'Đã bắt đầu quét',
            body: ($isReceipt ? "Phiếu nhập {$event->docNo}" : "Phiếu xuất {$event->docNo}").' đã chuyển sang trạng thái Đang quét.',
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: ['document_id' => $event->documentId, 'doc_no' => $event->docNo, 'type' => $event->type, 'warehouse_id' => $event->warehouseId],
            actorId: $event->actorId,
            dispatchKey: $this->notificationDispatchKey(),
        );
    }
}
