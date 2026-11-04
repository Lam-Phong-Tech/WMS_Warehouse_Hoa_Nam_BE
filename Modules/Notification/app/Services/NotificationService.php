<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Mail\ProfileNotificationMail;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Support\EventSourceSnapshot;
use Throwable;

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
        ?string $actorId = null,
        ?array $sourceSnapshot = null,
    ): ?Notification {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return null;
        }
        $category = ProfilePreferences::category($eventKey, $sourceModule);
        $inAppIds = [];
        $emailRecipients = [];
        $users = User::query()->whereIn('user_id', $userIds)->get(['user_id', 'email', 'status'])->keyBy('user_id');
        foreach ($userIds as $userId) {
            $channels = $category === null ? ['in_app' => true, 'email' => false]
                : ProfilePreferences::read($userId)['notifications'][$category];
            if ($category === 'account' || $channels['in_app']) {
                $inAppIds[] = $userId;
            }
            $recipient = $users->get($userId);
            if ($channels['email'] && $recipient !== null && $recipient->status === 'ACTIVE'
                && filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                $emailRecipients[] = $recipient->email;
            }
        }
        if ($inAppIds === [] && $emailRecipients === []) {
            return null;
        }

        // Snapshot tên hiển thị NGAY tại thời điểm tạo thông báo (bảng append-only):
        // nếu sau này actor đổi tên hoặc bị xoá tài khoản, thông báo cũ vẫn giữ
        // đúng tên đã hiển thị cho người nhận lúc đó.
        $actorName = $actorId !== null
            ? User::query()->whereKey($actorId)->value('display_name')
            : null;

        $sourceSnapshot ??= EventSourceSnapshot::capture($actorId,
            is_string($payload['warehouse_id'] ?? null) ? $payload['warehouse_id'] : null,
            'AT_NOTIFICATION_CREATION_NOT_BUSINESS_EVENT');
        if ($sourceSnapshot !== [] && ($sourceSnapshot['actor_id'] ?? null) !== $actorId) {
            throw new \LogicException('NOTIFICATION_SNAPSHOT_ACTOR_MISMATCH');
        }
        if ($sourceSnapshot !== [] && ($sourceSnapshot['warehouse_id'] ?? null) !== ($payload['warehouse_id'] ?? null)) {
            throw new \LogicException('NOTIFICATION_SNAPSHOT_WAREHOUSE_MISMATCH');
        }
        if ($sourceSnapshot !== []) {
            $actorName = $sourceSnapshot['actor_name'] ?? null;
        }
        $notification = DB::transaction(function () use (
            $eventKey, $title, $body, $inAppIds, $severity, $sourceModule, $subjectType, $subjectId, $payload, $actorId, $actorName, $sourceSnapshot
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
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            if ($sourceSnapshot !== []) {
                DB::table('notification_source_snapshots')->insert(['notification_id' => $notification->id, ...$sourceSnapshot]);
            }

            foreach ($inAppIds as $userId) {
                NotificationRecipient::create([
                    'id' => (string) Str::uuid(),
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            // Broadcast and callers use the precision/offset actually persisted by the DB.
            return $notification->refresh();
        });

        // Bắn broadcast NGOÀI transaction ghi DB: nếu broadcaster lỗi (Reverb down),
        // notification vẫn đã được ghi nhận bền vững và sẽ thấy được qua GET /notifications.
        foreach ($inAppIds as $userId) {
            event(new NotificationPushed($notification, $userId));
        }
        foreach ($emailRecipients as $email) {
            // A transport/queue outage must not turn the committed notification or
            // its originating stock operation into an apparent failed mutation.
            DB::afterCommit(function () use ($email, $title, $body, $notification): void {
                try {
                    Mail::to($email)->queue(new ProfileNotificationMail($title, $body));
                } catch (Throwable $error) {
                    Log::warning('Notification email enqueue failed', [
                        'notification_id' => (string) $notification->id, 'exception' => $error::class,
                    ]);
                }
            });
        }

        return $notification;
    }
}
