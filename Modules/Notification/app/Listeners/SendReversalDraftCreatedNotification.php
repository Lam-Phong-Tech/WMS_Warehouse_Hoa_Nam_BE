<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\ReversalDraftCreated;

class SendReversalDraftCreatedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(ReversalDraftCreated $event): void
    {
        $userIds = $this->audience->byPermission('inventory.view', $event->warehouseId, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'reversal.created',
            title: 'Đã tạo phiếu điều chỉnh (reversal)',
            body: "Reversal {$event->docNo} đang chờ xác nhận hàng vật lý quay lại kho.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'reversal_document',
            subjectId: $event->reversalId,
            payload: ['reversal_id' => $event->reversalId, 'doc_no' => $event->docNo, 'warehouse_id' => $event->warehouseId],
        );
    }
}
