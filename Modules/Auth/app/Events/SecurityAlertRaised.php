<?php

namespace Modules\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityAlertRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $reasonCode, // LOGIN_THROTTLED|LOGIN_DENIED
        public readonly ?string $userId,
        public readonly string $message,
    ) {}
}
