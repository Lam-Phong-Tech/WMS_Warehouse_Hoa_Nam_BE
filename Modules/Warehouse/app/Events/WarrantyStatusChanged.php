<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $caseId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?string $actorId,
    ) {}
}
