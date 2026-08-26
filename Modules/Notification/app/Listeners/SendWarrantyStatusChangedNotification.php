<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\WarrantyStatusChanged;

class SendWarrantyStatusChangedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(WarrantyStatusChanged $event): void
    {
        // Case bảo hành không gắn theo warehouse tồn kho — audience là toàn bộ
        // người có quyền xem bảo hành, không lọc theo warehouseScopeIds().
        $userIds = $this->audience->byPermission('warranty.view', null, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'warranty.status_changed',
            title: 'Case bảo hành đổi trạng thái',
            body: "Case bảo hành chuyển từ {$event->fromStatus} sang {$event->toStatus}.",
            userIds: $userIds,
            severity: $event->toStatus === 'CANCELLED' ? 'warning' : 'info',
            sourceModule: 'warehouse',
            subjectType: 'warranty_case',
            subjectId: $event->caseId,
            payload: ['case_id' => $event->caseId, 'from_status' => $event->fromStatus, 'to_status' => $event->toStatus],
        );
    }
}
