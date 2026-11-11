<?php

namespace Modules\Notification\Support;

use Modules\Auth\Models\User;
use Modules\Notification\Models\Notification;

/**
 * Re-evaluate notification content access against CURRENT RBAC.
 *
 * A notification recipient row is historical: the audience was resolved when the
 * event happened. That must not become a permanent permission to read the payload
 * after the user loses a role or warehouse scope.
 */
final class NotificationContentAccess
{
    public const DENIED_TITLE = 'Thông báo không còn quyền truy cập';

    public const DENIED_BODY = 'Bạn không còn quyền xem nội dung thông báo này.';

    public static function allows(User $user, ?Notification $notification): bool
    {
        if ($notification === null || $user->status !== 'ACTIVE') {
            return false;
        }

        $permissions = self::requiredPermissions((string) $notification->event_key);
        if ($permissions === []) {
            // Account/role/security notifications are owned by the recipient.
            return true;
        }

        $payload = is_array($notification->payload) ? $notification->payload : [];
        $warehouseId = is_string($payload['warehouse_id'] ?? null) && $payload['warehouse_id'] !== ''
            ? $payload['warehouse_id']
            : null;

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                continue;
            }
            if ($warehouseId !== null && ! $user->canAccessWarehouse($warehouseId)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    public static function requiredPermissions(string $eventKey): array
    {
        $event = strtolower($eventKey);

        if ($event === 'inbound.sku_info_pending') {
            return ['inbound.scan'];
        }
        if ($event === 'stock.shortage_detected') {
            return ['inbound.post', 'outbound.post'];
        }
        if (str_starts_with($event, 'inbound.')) {
            return ['inbound.view'];
        }
        if (str_starts_with($event, 'outbound.')) {
            return ['outbound.view'];
        }
        if (str_starts_with($event, 'reversal.')) {
            return ['inventory.view'];
        }
        if (str_starts_with($event, 'warranty.')) {
            return ['warranty.view'];
        }
        if (str_starts_with($event, 'stock.')) {
            return ['inventory.view'];
        }

        // user./role./security. and custom notifications are recipient-scoped.
        return [];
    }
}
