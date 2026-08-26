<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\StockShortageDetected;

class SendStockShortageNotification implements ShouldQueue
{
    /**
     * Không dùng $afterCommit: sự kiện này phát khi WarehouseException::insufficientStock()
     * ném lỗi làm transaction Post Issue/Post Receipt ROLLBACK — không có commit nào để chờ.
     */
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(StockShortageDetected $event): void
    {
        $userIds = array_unique(array_merge(
            $this->audience->byPermission('inbound.post', $event->warehouseId, [$event->actorId]),
            $this->audience->byPermission('outbound.post', $event->warehouseId, [$event->actorId]),
        ));

        $this->notifications->notify(
            eventKey: 'stock.shortage_detected',
            title: 'Tồn kho không đủ',
            body: "Thao tác trên phiếu {$event->documentId} thất bại do tồn kho không đủ cho sản phẩm {$event->productCode}.",
            userIds: $userIds,
            severity: 'warning',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: ['document_id' => $event->documentId, 'warehouse_id' => $event->warehouseId, 'product_code' => $event->productCode],
        );
    }
}
