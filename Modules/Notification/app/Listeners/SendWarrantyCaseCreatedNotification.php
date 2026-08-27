<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Events\WarrantyCaseCreated;

class SendWarrantyCaseCreatedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(WarrantyCaseCreated $event): void
    {
        // Case bảo hành không gắn theo warehouse tồn kho — cùng cách audience của
        // SendWarrantyStatusChangedNotification.
        $userIds = $this->audience->byPermission('warranty.view', null, [$event->actorId]);

        $this->notifications->notify(
            eventKey: 'warranty.case_created',
            title: 'Đã tiếp nhận case bảo hành mới',
            body: "Case bảo hành {$event->caseCode} vừa được tiếp nhận.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'warehouse',
            subjectType: 'warranty_case',
            subjectId: $event->caseId,
            payload: ['case_id' => $event->caseId, 'case_code' => $event->caseCode],
            actorId: $event->actorId,
        );
    }
}
