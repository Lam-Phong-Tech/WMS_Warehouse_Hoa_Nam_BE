<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Auth\Events\UserAccountChanged;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;

class SendUserAccountChangedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(UserAccountChanged $event): void
    {
        // Người quản trị IAM + chính chủ tài khoản bị thay đổi (nếu khác actor).
        $adminIds = $this->audience->byPermission('iam.user.manage', null, [$event->actorId]);
        $userIds = array_unique(array_merge($adminIds, [$event->userId]));

        $this->notifications->notify(
            eventKey: 'user.account_changed',
            title: $event->action === 'CREATE' ? 'Tài khoản mới được tạo' : 'Tài khoản của bạn đã được cập nhật',
            body: $event->action === 'CREATE'
                ? 'Một tài khoản người dùng mới vừa được tạo trong hệ thống.'
                : 'Thông tin tài khoản của bạn vừa được quản trị viên cập nhật.',
            userIds: $userIds,
            severity: 'info',
            sourceModule: 'auth',
            subjectType: 'user',
            subjectId: $event->userId,
            payload: ['user_id' => $event->userId, 'action' => $event->action],
            actorId: $event->actorId,
        );
    }
}
