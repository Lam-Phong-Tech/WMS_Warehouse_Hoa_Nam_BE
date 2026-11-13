<?php

namespace Modules\Notification\Support;

use Illuminate\Database\Query\Builder;

/** Warehouse history is independent of optional toast/push delivery. */
final class NotificationHistoryPolicy
{
    private const MODULES = ['warehouse', 'inventory', 'warranty', 'component'];

    private const FAMILIES = ['inbound.', 'outbound.', 'stock.', 'reversal.', 'warranty.'];

    private const ACCOUNT_FAMILIES = ['security.', 'user.', 'role.'];

    public static function isWarehouse(string $eventKey, string $sourceModule): bool
    {
        foreach (self::ACCOUNT_FAMILIES as $family) {
            if (str_starts_with($eventKey, $family)) {
                return false;
            }
        }
        foreach (self::FAMILIES as $family) {
            if (str_starts_with($eventKey, $family)) {
                return true;
            }
        }

        return in_array($sourceModule, self::MODULES, true);
    }

    public static function excludeWarehouse(Builder $query, string $notificationAlias = 'n'): void
    {
        $query->whereNot(function (Builder $warehouse) use ($notificationAlias): void {
            $warehouse->where(function (Builder $scope) use ($notificationAlias): void {
                $scope->whereIn($notificationAlias.'.source_module', self::MODULES);
                foreach (self::FAMILIES as $family) {
                    $scope->orWhere($notificationAlias.'.event_key', 'like', $family.'%');
                }
            });
            foreach (self::ACCOUNT_FAMILIES as $family) {
                $warehouse->where($notificationAlias.'.event_key', 'not like', $family.'%');
            }
        });
    }
}
