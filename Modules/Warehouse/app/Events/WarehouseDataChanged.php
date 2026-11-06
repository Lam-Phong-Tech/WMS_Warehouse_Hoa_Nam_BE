<?php

namespace Modules\Warehouse\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** A refetch hint only. Data permissions and warehouse scope remain on the read APIs. */
final class WarehouseDataChanged implements ShouldBroadcastNow
{
    // Laravel reflects this sole public property into the payload. No socket ID
    // is attached, so the actor's other clients receive the same invalidation.
    public readonly string $domain;

    public function __construct()
    {
        $this->domain = 'warehouse';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('warehouse-data')];
    }

    public function broadcastAs(): string
    {
        return 'warehouse.data.changed';
    }
}
