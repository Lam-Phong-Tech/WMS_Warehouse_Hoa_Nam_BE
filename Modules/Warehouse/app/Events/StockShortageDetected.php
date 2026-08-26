<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockShortageDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly ?string $warehouseId,
        public readonly string $productCode,
        public readonly ?string $actorId,
    ) {}
}
