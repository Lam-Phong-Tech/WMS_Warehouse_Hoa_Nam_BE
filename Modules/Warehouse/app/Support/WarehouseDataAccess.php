<?php

namespace Modules\Warehouse\Support;

use Modules\Auth\Models\User;

final class WarehouseDataAccess
{
    /** Existing permissions that authorize canonical warehouse read endpoints. */
    private const READ_PERMISSIONS = [
        'warehouse.view', 'sku.view', 'sku.manage', 'inbound.view', 'outbound.view',
        'inventory.view', 'inventory.trace', 'warranty.view', 'warranty.manage',
        'warranty.component_issue', 'putaway.view', 'putaway.manage', 'container.view',
        'item_instance.view', 'import_batch.view', 'catalog.view', 'pv.master.manage', 'agency.view',
        'defect.view', 'label.print', 'packing_label.print', 'outbound.request.import',
        'outbound.import', 'report.view', 'report.import_quality.view',
        'report.inventory_flow.view', 'report.outbound_flow.view', 'report.io_history.view',
        'report.recipient.view', 'report.stock_reconcile.view', 'report.traceability.view',
        'report.packing_label.view', 'report.warranty.view', 'report.export',
    ];

    public static function allows(User $user): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        foreach (self::READ_PERMISSIONS as $permission) {
            // hasPermission includes the canonical active SUPER_ADMIN bypass.
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
