<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyCaseCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $caseId,
        public readonly string $caseCode,
        public readonly ?string $actorId,
    ) {}
}
