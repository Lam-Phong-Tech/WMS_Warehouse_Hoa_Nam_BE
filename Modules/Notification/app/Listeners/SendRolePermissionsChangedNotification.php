<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Auth\Events\RolePermissionsChanged;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;

class SendRolePermissionsChangedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(RolePermissionsChanged $event): void
    {
        // Người quản trị role + mọi user đang giữ role vừa đổi quyền (cần biết
        // ngay vì cache permission của họ đã bị bump — hiệu lực từ request kế tiếp).
        $adminIds = $this->audience->byPermission('iam.role.manage', null, [$event->actorId]);
        $holderIds = $this->audience->byRoleId($event->roleId, [$event->actorId]);
        $userIds = array_unique(array_merge($adminIds, $holderIds));

        $this->notifications->notify(
            eventKey: 'role.permissions_changed',
            title: 'Phân quyền vai trò đã thay đổi',
            body: "Danh sách quyền của vai trò {$event->roleCode} vừa được cập nhật.",
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'auth',
            subjectType: 'role',
            subjectId: $event->roleId,
            payload: ['role_id' => $event->roleId, 'role_code' => $event->roleCode],
        );
    }
}
