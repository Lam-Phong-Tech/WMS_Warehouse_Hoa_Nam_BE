<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\InboundSkuInfoPending;

class SendInboundSkuInfoPendingNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(InboundSkuInfoPending $event): void
    {
        // Gửi cho người BỔ SUNG ĐƯỢC: route PATCH /inbound-documents/{id}/lines/
        // {lineId}/sku gác quyền `inbound.scan`. Trừ chính người quét ở Mini App
        // vì họ vừa gây ra việc này và không có màn bổ sung trong app.
        $userIds = $this->audience->byPermission('inbound.scan', $event->warehouseId, [$event->actorId]);

        $codes = array_slice($event->skuCodes, 0, 5);
        $suffix = count($event->skuCodes) > count($codes) ? '…' : '';

        $this->notifications->notify(
            eventKey: 'inbound.sku_info_pending',
            title: 'Phiếu nhập chờ bổ sung thông tin SKU',
            body: "Phiếu nhập {$event->docNo} có SKU mới chưa đủ thông tin (".implode(', ', $codes).$suffix.'). '
                .'Bổ sung tên và đơn vị tính rồi mới Ghi nhận nhập kho được.',
            userIds: $userIds,
            severity: 'warning',
            sourceModule: 'warehouse',
            subjectType: 'stock_document',
            subjectId: $event->documentId,
            payload: [
                'document_id' => $event->documentId,
                'doc_no' => $event->docNo,
                'type' => 'receipt',
                'warehouse_id' => $event->warehouseId,
                'sku_codes' => $event->skuCodes,
                'source' => 'MINI_APP',
            ],
            actorId: $event->actorId,
        );
    }
}
