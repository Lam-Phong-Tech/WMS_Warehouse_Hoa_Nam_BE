<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Auth\Events\SecurityAlertRaised;
use Modules\Notification\Services\NotificationAudienceResolver;
use Modules\Notification\Services\NotificationService;

class SendSecurityAlertNotification implements ShouldQueue
{
    use \Modules\Notification\Delivery\NotificationDispatchIdentity;

    /**
     * Không $afterCommit: LOGIN_THROTTLED/LOGIN_DENIED không nằm trong một
     * DB::transaction nghiệp vụ nào để chờ commit — audit log ghi độc lập.
     */
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationAudienceResolver $audience,
    ) {}

    public function handle(SecurityAlertRaised $event): void
    {
        // Cảnh báo bảo mật đi tới quản trị IAM, không đi tới chính user đang bị
        // từ chối đăng nhập (họ có thể chính là kẻ đang dò mật khẩu).
        $userIds = $this->audience->byPermission('iam.user.manage');

        $this->notifications->notify(
            eventKey: 'security.alert_raised',
            title: 'Cảnh báo bảo mật đăng nhập',
            body: $event->message,
            userIds: $userIds,
            severity: 'warning',
            sourceModule: 'auth',
            subjectType: 'user',
            subjectId: $event->userId,
            payload: ['reason_code' => $event->reasonCode, 'user_id' => $event->userId],
            dispatchKey: $this->notificationDispatchKey(),
        );
    }
}
