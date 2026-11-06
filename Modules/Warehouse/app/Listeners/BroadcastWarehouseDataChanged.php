<?php

namespace Modules\Warehouse\Listeners;

use App\Events\CacheNamespaceInvalidated;
use Illuminate\Support\Facades\Log;
use Modules\Warehouse\Events\WarehouseDataChanged;
use Throwable;

use function Illuminate\Support\defer;

final class BroadcastWarehouseDataChanged
{
    public function handle(CacheNamespaceInvalidated $event): void
    {
        if ($event->namespace !== 'stock:version') {
            return;
        }

        // One hint per request/async job, after every committed cache bump. Keep
        // it even when a later operation rolls back or the response/job fails.
        defer(function (): void {
            try {
                event(new WarehouseDataChanged);
            } catch (Throwable $error) {
                Log::warning('Warehouse realtime unavailable', ['exception' => $error::class]);
            }
        }, 'warehouse-data-changed', always: true);
    }
}
