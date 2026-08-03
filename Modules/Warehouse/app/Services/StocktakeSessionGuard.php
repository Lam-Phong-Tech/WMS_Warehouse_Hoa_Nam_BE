<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StocktakeSession;

class StocktakeSessionGuard
{
    public function findScoped(string $sessionId, User $user): StocktakeSession
    {
        $session = StocktakeSession::query()->find($sessionId);
        if (! $session) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy stocktake session.');
        }

        $this->assertWarehouseScope($user, (string) $session->warehouse_id);

        return $session;
    }

    public function lockScoped(string $sessionId, User $user): StocktakeSession
    {
        $session = StocktakeSession::query()->where('session_id', $sessionId)->lockForUpdate()->first();
        if (! $session) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy stocktake session.');
        }

        $this->assertWarehouseScope($user, (string) $session->warehouse_id);

        return $session;
    }

    public function assertWarehouseScope(User $user, string $warehouseId): void
    {
        if (! $user->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'WAREHOUSE_SCOPE_DENIED', 'Kho kiểm kê nằm ngoài data scope của người dùng.');
        }
    }

    public function assertVersion(StocktakeSession $session, int $expectedVersion): void
    {
        if ((int) $session->session_version !== $expectedVersion) {
            ApiError::abort(409, 'SESSION_VERSION_CONFLICT', 'session_version đã cũ; tải lại session trước khi thao tác.', [
                'expected_version' => $expectedVersion,
                'current_version' => (int) $session->session_version,
            ]);
        }
    }

    /** @param array<int, string> $allowed */
    public function assertStatus(StocktakeSession $session, array $allowed, string $action): void
    {
        if (! in_array($session->status, $allowed, true)) {
            ApiError::abort(409, 'STATE_CONFLICT', "Không thể {$action} khi session ở trạng thái {$session->status}.", [
                'allowed_statuses' => $allowed,
                'current_status' => $session->status,
            ]);
        }
    }
}
