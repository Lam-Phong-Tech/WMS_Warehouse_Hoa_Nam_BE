<?php

namespace Modules\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RolePermissionsChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $roleId,
        public readonly string $roleCode,
        public readonly ?string $actorId,
    ) {}
}
