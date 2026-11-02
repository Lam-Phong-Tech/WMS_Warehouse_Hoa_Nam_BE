<?php

namespace Modules\Auth\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Wake all open clients of this account; each verifies its own session with /auth/me. */
final class AuthSessionReplaced implements ShouldBroadcastNow
{
    public function __construct(public readonly string $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'auth.session-replaced';
    }

    public function broadcastWith(): array
    {
        return ['code' => 'AUTH_SESSION_REPLACED'];
    }
}
