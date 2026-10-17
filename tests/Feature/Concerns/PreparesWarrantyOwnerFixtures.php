<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\WarrantyWarehouseScope;

trait PreparesWarrantyOwnerFixtures
{
    private function bindWarrantyOwnerFixture(string $caseId, string $actorId, string $warehouseId): void
    {
        DB::table(WarrantyWarehouseScope::TABLE)->insert([
            'warranty_case_id' => $caseId,
            'warehouse_id' => $warehouseId,
            'actor_id' => $actorId,
            'auth_session_id' => null,
            'source' => 'EXPLICIT_REQUEST',
            'assigned_at' => now()->utc(),
            'evidence' => null,
        ]);
    }
}
