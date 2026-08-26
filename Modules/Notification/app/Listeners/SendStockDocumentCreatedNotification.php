<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\StockDocumentCreated;

class SendStockDocumentCreatedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(StockDocumentCreated $event): void
    {
        $isReceipt = $event->type === 'receipt';
        $userIds = $this->audience->byPermission($isReceipt ? 'inbound.view' : 'outbound.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: $isReceipt ? 'inbound.created' : 'outbound.created',
            title: $isReceipt ? 'Đã tạo phiếu nhập mới' : 'Đã tạo phiếu xuất mới',
            body: ($isReceipt ? "Phiếu nhập {$event->docNo}" : "Phiếu xuất {$event->docNo}").' vừa được tạo ở trạng thái Nháp.',
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: ['document_id' => $event->documentId, 'doc_no' => $event->docNo, 'type' => $event->type, 'warehouse_id' => $event->warehouseId],
        );
    }
}
