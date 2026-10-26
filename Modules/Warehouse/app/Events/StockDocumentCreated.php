<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Notification\Support\EventSourceSnapshot;

class StockDocumentCreated
{
    use Dispatchable, SerializesModels;

    public array $sourceSnapshot = [];

    public function __construct(
        public readonly string $documentId,
        public readonly string $docNo,
        public readonly string $type,
        public readonly ?string $warehouseId,
        public readonly ?string $actorId,
    ) {
        $this->sourceSnapshot = EventSourceSnapshot::capture($actorId, $warehouseId);
    }
}
