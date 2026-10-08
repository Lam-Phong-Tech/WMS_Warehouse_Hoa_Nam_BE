<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;

/** Explicit warehouse selection belongs to a login family, not to a work shift. */
final class WarehouseContext
{
    public const TABLE = 'auth_session_warehouse_contexts';

    /** @return array<string, mixed> */
    public function payload(User $user, ?string $sessionId, string $noSessionCode): array
    {
        if ($sessionId === null || $user->status !== 'ACTIVE') {
            return $this->result(null, 'NO_SESSION', $noSessionCode, []);
        }

        // Choices are NOT a selection. Even a singleton requires confirmation.
        // Return only minimal self-scope metadata, never warehouse contact data.
        $query = Warehouse::query()->where('status', 'ACTIVE');
        $scopes = $user->warehouseScopeIds();
        if ($scopes !== null) {
            $query->whereIn('id', array_values(array_filter($scopes,
                static fn ($id): bool => is_string($id) && Str::isUuid($id))));
        }
        $options = $query->orderBy('code')->get(['id', 'code', 'name', 'status'])
            ->map(static fn (Warehouse $warehouse): array => [
                'warehouse_id' => (string) $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
            ])->all();

        $selected = $this->storedWarehouseId($user, $sessionId);
        $shiftWarehouse = DB::table('mini_app_work_shifts')
            ->where('actor_id', $user->user_id)->where('auth_session_id', $sessionId)->whereNull('ended_at')->value('warehouse_id');
        if ($selected !== null && $shiftWarehouse !== null && $selected !== (string) $shiftWarehouse) {
            return $this->result(null, 'UNAVAILABLE', 'WAREHOUSE_CONTEXT_MISMATCH', $options);
        }
        // A pre-rollout start receipt is already explicit confirmation. Never
        // recover another login's choice, or infer a choice from scopes/options.
        $warehouseId = $selected ?? ($shiftWarehouse === null ? null : (string) $shiftWarehouse);
        if ($warehouseId === null) {
            return $this->result(null, 'REQUIRED', 'WAREHOUSE_CONTEXT_REQUIRED', $options);
        }
        if (! $user->canAccessWarehouse($warehouseId)) {
            return $this->result(null, 'UNAVAILABLE', 'WAREHOUSE_ACCESS_DENIED', $options);
        }
        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null) {
            return $this->result(null, 'UNAVAILABLE', 'WAREHOUSE_NOT_FOUND', $options);
        }
        if ($warehouse->status !== 'ACTIVE') {
            return $this->result(null, 'UNAVAILABLE', 'WAREHOUSE_INACTIVE', $options);
        }

        return $this->result($warehouseId, 'READY', null, $options);
    }

    public function change(User $user, ?string $warehouseId): User
    {
        return DB::transaction(function () use ($user, $warehouseId): User {
            // Same parent lock/order as MiniAppWorkShiftService::start: a switch
            // cannot race a first start into two different warehouse contexts.
            $actor = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
            if ($actor === null || $actor->status !== 'ACTIVE') {
                ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn khả dụng.');
            }
            // Preserve the legacy SYSTEM no-op for access-only clients. It does
            // not select a warehouse, create a refresh family, or enable a shift.
            if ($warehouseId === null && app(RefreshTokenService::class)->currentSessionId() === null) {
                return $actor;
            }
            $sessionId = app(StableAuthSession::class)->requireId($actor);
            $shiftWarehouse = DB::table('mini_app_work_shifts')
                ->where('actor_id', $actor->user_id)->where('auth_session_id', $sessionId)->whereNull('ended_at')->value('warehouse_id');
            if ($shiftWarehouse !== null && (string) $shiftWarehouse !== $warehouseId) {
                ApiError::abort(409, 'SHIFT_WAREHOUSE_CONFLICT', 'Phiên đã bắt đầu ca; không thể đổi hoặc bỏ kho của ca này.');
            }
            if ($warehouseId !== null) {
                if (! $actor->canAccessWarehouse($warehouseId)) {
                    ApiError::abort(403, 'ACCESS_DENIED', 'Ngữ cảnh kho nằm ngoài phạm vi được gán.');
                }
                $warehouse = Warehouse::query()->whereKey($warehouseId)->lockForUpdate()->first();
                if ($warehouse === null) {
                    ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
                }
                if ($warehouse->status !== 'ACTIVE') {
                    ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang tạm dừng hoạt động.');
                }
            }
            $before = $this->storedWarehouseId($actor, $sessionId);
            if ($before === $warehouseId) {
                return $actor;
            }
            if ($warehouseId === null) {
                DB::table(self::TABLE)->where('actor_id', $actor->user_id)
                    ->where('auth_session_id', $sessionId)->delete();
            } else {
                DB::table(self::TABLE)->updateOrInsert(
                    ['auth_session_id' => $sessionId, 'actor_id' => (string) $actor->user_id],
                    ['warehouse_id' => $warehouseId, 'confirmed_at' => now()->utc()->format('Y-m-d H:i:sP')],
                );
            }
            AuditLogger::record(
                action: 'SWITCH_CONTEXT', objectType: 'app_user',
                objectId: (string) $actor->user_id, actorId: (string) $actor->user_id,
                requestId: request()->attributes->get('request_id'),
                beforeData: ['active_warehouse_id' => $before],
                afterData: ['auth_session_id' => $sessionId, 'active_warehouse_id' => $warehouseId],
                result: 'SUCCESS',
            );

            return $actor;
        }, 3);
    }

    public function assertMatches(User $user, string $sessionId, string $warehouseId): void
    {
        $selected = $this->storedWarehouseId($user, $sessionId);
        if ($selected !== null && $selected !== $warehouseId) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho gửi lên không khớp kho đã xác nhận trong phiên. Tải lại thông tin phiên trước khi tiếp tục.');
        }
    }

    private function storedWarehouseId(User $user, string $sessionId): ?string
    {
        $id = DB::table(self::TABLE)->where('actor_id', $user->user_id)
            ->where('auth_session_id', $sessionId)->value('warehouse_id');

        return $id === null ? null : (string) $id;
    }

    private function result(?string $id, string $status, ?string $code, array $options): array
    {
        return [
            'active_warehouse_id' => $id,
            'warehouse_context' => ['status' => $status, 'error_code' => $code],
            'available_warehouses' => $options,
        ];
    }
}
