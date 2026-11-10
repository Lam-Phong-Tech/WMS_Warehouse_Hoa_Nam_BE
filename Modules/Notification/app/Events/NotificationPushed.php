<?php

namespace Modules\Notification\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Modules\Notification\Models\Notification;
use Modules\Notification\Support\NotificationTimestamp;

/**
 * Được bắn (không qua queue lần nữa — Listener sinh ra nó đã chạy trong queued
 * job) cho một nhóm recipient trên các channel riêng `private-user.{userId}`
 * ngay sau khi NotificationService::notify() ghi xong notifications +
 * notification_recipients. Một event nhiều channel = một HTTP publish tới
 * Reverb/Pusher thay vì một publish cho từng người.
 */
class NotificationPushed implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly Notification $notification,
        public readonly array $userIds,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $userId): PrivateChannel => new PrivateChannel('user.'.$userId),
            array_values($this->userIds),
        );
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'event_key' => $this->notification->event_key,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'severity' => $this->notification->severity,
            'source_module' => $this->notification->source_module,
            'subject_type' => $this->notification->subject_type,
            'subject_id' => $this->notification->subject_id,
            'actor_id' => $this->notification->actor_id,
            'actor_name' => $this->notification->actor_name,
            'payload' => $this->notification->payload,
            'created_at' => NotificationTimestamp::read($this->notification->getRawOriginal('created_at')),
        ];
    }
}
