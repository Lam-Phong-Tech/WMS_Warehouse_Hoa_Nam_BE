<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;

class NotificationService
{
    /**
     * Ghi 1 notification + fan-out notification_recipients rồi phát
     * NotificationPushed cho từng recipient. An toàn khi gọi lặp lại với cùng
     * audience trống (không tạo notification "mồ côi" không ai nhận được).
     *
     * @param  array<int,string>  $userIds
     * @param  array<string,mixed>  $payload
     */
    public function notify(
        string $eventKey,
        string $title,
        string $body,
        array $userIds,
        string $severity = 'info',
        string $sourceModule = 'notification',
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $payload = [],
    ): ?Notification {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return null;
        }

        $notification = DB::transaction(function () use (
            $eventKey, $title, $body, $userIds, $severity, $sourceModule, $subjectType, $subjectId, $payload
        ): Notification {
            $notification = Notification::create([
                'id' => (string) Str::uuid(),
                'event_key' => $eventKey,
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
                'source_module' => $sourceModule,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            foreach ($userIds as $userId) {
                NotificationRecipient::create([
                    'id' => (string) Str::uuid(),
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            return $notification;
        });

        // Bắn broadcast NGOÀI transaction ghi DB: nếu broadcaster lỗi (Reverb down),
        // notification vẫn đã được ghi nhận bền vững và sẽ thấy được qua GET /notifications.
        foreach ($userIds as $userId) {
            event(new NotificationPushed($notification, $userId));
        }

        return $notification;
    }
}
