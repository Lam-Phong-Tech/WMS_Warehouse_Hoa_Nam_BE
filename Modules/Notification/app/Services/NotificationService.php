<?php

namespace Modules\Notification\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Delivery\NotificationDeliveryPolicy;
use Modules\Notification\Delivery\NotificationOutbox;
use Modules\Notification\Events\NotificationPushed;
use Modules\Notification\Mail\ProfileNotificationMail;
use Modules\Notification\Models\Notification;
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
        ?string $dispatchKey = null,
        ?array $deliveryRegistrationIds = null,
        bool $broadcastInApp = true,
    ): ?Notification {
        $deliveryKey = $dispatchKey !== null && Schema::hasColumn('notifications', 'delivery_dispatch_key') ? hash('sha256', $dispatchKey) : null;
        if ($deliveryKey !== null && ($existing = Notification::where('delivery_dispatch_key', $deliveryKey)->first()) !== null) {
            return $existing;
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return null;
        }
        $category = ProfilePreferences::category($eventKey, $sourceModule);
        $inAppIds = [];
        $emailRecipients = [];
        $users = User::query()->whereIn('user_id', $userIds)->get(['user_id', 'email', 'status'])->keyBy('user_id');
        $preferences = ProfilePreferences::readMany($userIds);
        foreach ($userIds as $userId) {
            $channels = $category === null ? ['in_app' => true, 'email' => false]
                : $preferences[$userId]['notifications'][$category];
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

        $inAppIds = $this->rateLimitInApp($inAppIds, $category);
        if ($inAppIds === [] && $emailRecipients === []) {
            return null;
        }

        $coalesceSeconds = max(0, (int) config('notification_delivery.coalesce_seconds', 30));
        $coalesceKey = null;
        if ($deliveryKey === null && $subjectId !== null && $coalesceSeconds > 0) {
            $audience = $inAppIds;
            sort($audience);
            $coalesceKey = 'notification:coalesce:'.sha1(json_encode([
                $eventKey, $sourceModule, $subjectType, $subjectId, $title, $body, $severity, $audience,
            ], JSON_THROW_ON_ERROR));
            try {
                if (! Cache::add($coalesceKey, true, now()->addSeconds($coalesceSeconds))) {
                    return null;
                }
            } catch (Throwable) {
                // Cache is optional for notification safety; fail open and keep the database path.
                $coalesceKey = null;
            }
        }

        // Snapshot tên hiển thị NGAY tại thời điểm tạo thông báo (bảng append-only):
        // nếu sau này actor đổi tên hoặc bị xoá tài khoản, thông báo cũ vẫn giữ
        // đúng tên đã hiển thị cho người nhận lúc đó.
        $actorName = $actorId !== null
            ? User::query()->whereKey($actorId)->value('display_name')
            : null;

        if ($sourceSnapshot === null && $sourceModule === 'warehouse' && $subjectType === 'warranty_case') {
            $payload = NotificationDeliveryPolicy::withWarehouse($eventKey, $subjectId, $payload);
        }
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
        $created = true;
        try {
            $notification = DB::transaction(function () use (
                $eventKey, $title, $body, $inAppIds, $severity, $sourceModule, $subjectType, $subjectId, $payload, $actorId, $actorName, $sourceSnapshot, $deliveryKey, $deliveryRegistrationIds, &$created
            ): Notification {
                if ($deliveryKey !== null && ($existing = Notification::where('delivery_dispatch_key', $deliveryKey)->first()) !== null) {
                    $created = false;

                    return $existing;
                }
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
                    ...($deliveryKey === null ? [] : ['delivery_dispatch_key' => $deliveryKey]),
                ]);

                if ($sourceSnapshot !== []) {
                    DB::table('notification_source_snapshots')->insert(['notification_id' => $notification->id, ...$sourceSnapshot]);
                }

                if ($inAppIds !== []) {
                    $createdAt = now();
                    DB::table('notification_recipients')->insert(array_map(
                        static fn (string $userId): array => [
                            'id' => (string) Str::uuid(),
                            'notification_id' => $notification->id,
                            'user_id' => $userId,
                            'created_at' => $createdAt,
                        ],
                        $inAppIds,
                    ));
                }

                app(NotificationOutbox::class)->enqueue($notification, $deliveryRegistrationIds);

                // Broadcast and callers use the precision/offset actually persisted by the DB.
                return $notification->refresh();
            });
        } catch (UniqueConstraintViolationException $error) {
            if ($deliveryKey === null || ($existing = Notification::where('delivery_dispatch_key', $deliveryKey)->first()) === null) {
                throw $error;
            }

            return $existing;
        }
        if (! $created) {
            return $notification;
        }

        // Bắn broadcast NGOÀI transaction ghi DB: nếu broadcaster lỗi (Reverb down),
        // notification vẫn đã được ghi nhận bền vững và sẽ thấy được qua GET /notifications.
        if ($broadcastInApp && $inAppIds !== []) {
            foreach (array_chunk($inAppIds, 100) as $recipientChunk) {
                DB::afterCommit(function () use ($notification, $recipientChunk): void {
                    try {
                        event(new NotificationPushed($notification, $recipientChunk));
                    } catch (Throwable $error) {
                        Log::warning('Notification realtime deferred', [
                            'notification_id' => (string) $notification->id,
                            'recipient_count' => count($recipientChunk),
                            'exception' => $error::class,
                        ]);
                    }
                });
            }
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

    /**
     * Cap optional in-app notifications per user/minute. Account and security
     * events bypass the cap so security warnings are never dropped.
     *
     * @param  array<int,string>  $userIds
     * @return array<int,string>
     */
    private function rateLimitInApp(array $userIds, ?string $category): array
    {
        $limit = (int) config('notification_delivery.max_in_app_per_minute', 60);
        if ($limit <= 0 || $category === 'account' || $userIds === []) {
            return $userIds;
        }

        $minute = now()->format('YmdHi');
        $allowed = [];
        foreach ($userIds as $userId) {
            try {
                $key = 'notification:rate:'.sha1($userId).':'.$minute;
                Cache::add($key, 0, now()->addMinutes(2));
                $count = (int) Cache::increment($key);
                if ($count <= $limit) {
                    $allowed[] = $userId;
                }
            } catch (Throwable) {
                // Notification delivery must not fail because the optional cache is down.
                $allowed[] = $userId;
            }
        }

        return array_values(array_unique($allowed));
    }
}
