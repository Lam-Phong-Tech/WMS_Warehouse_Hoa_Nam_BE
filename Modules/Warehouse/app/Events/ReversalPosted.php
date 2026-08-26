<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReversalPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $reversalId,
        public readonly string $docNo,
        public readonly ?string $warehouseId,
        public readonly ?string $actorId,
    ) {}
}
