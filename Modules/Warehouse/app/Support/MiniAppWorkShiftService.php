<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\P14ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\Warehouse;
use stdClass;

/** Start receipts remain immutable; END does not revoke their authentication family. */
final class MiniAppWorkShiftService
{
    public const PERMISSIONS = 'inbound.view|outbound.view|warranty.view|warranty.manage|sku.view|physical_code.assign_rfid';

    public function __construct(private readonly StableAuthSession $sessions) {}

    public function current(User $user): ?array
    {
        $this->assertActor($user);
        $sessionId = $this->sessions->requireId($user);
        $shift = DB::table('mini_app_work_shifts')->where('actor_id', $user->user_id)
            ->where('auth_session_id', $sessionId)->whereNull('ended_at')->first();
        if ($shift === null) {
            return null;
        }
        app(WarehouseContext::class)->assertMatches($user, $sessionId, (string) $shift->warehouse_id);
        $this->assertWarehouse($user, (string) $shift->warehouse_id);

        return $this->payload($shift);
    }

    /** @return array{data: array, created: bool} */
    public function start(User $user, string $warehouseId, string $key): array
    {
        return DB::transaction(function () use ($user, $warehouseId, $key): array {
            $actor = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
            if ($actor === null) {
                ApiError::abort(401, 'UNAUTHENTICATED', 'Tài khoản không còn khả dụng.');
            }
            $this->assertActor($actor);
            $sessionId = $this->sessions->requireId($actor);
            $previousKey = DB::table('mini_app_work_shift_keys')
                ->where('auth_session_id', $sessionId)->where('idempotency_key', $key)->first();
            if ($previousKey !== null && $previousKey->warehouse_id !== $warehouseId) {
                ApiError::abort(409, 'IDEMPOTENCY_KEY_REUSED', 'Yêu cầu này đã được dùng cho kho khác.');
            }
            if ($previousKey !== null) {
                app(WarehouseContext::class)->assertMatches($actor, $sessionId, $warehouseId);
                $this->assertWarehouse($actor, $warehouseId, true);
                // Retrying an old start never creates a new shift, even after END.
                $previous = DB::table('mini_app_work_shifts')->where('shift_id', $previousKey->shift_id)
                    ->where('actor_id', $actor->user_id)->where('auth_session_id', $sessionId)->first();
                if ($previous === null) {
                    ApiError::abort(409, 'SHIFT_START_RECEIPT_INVALID', 'Không đối chiếu được receipt bắt đầu ca.');
                }

                return ['data' => $this->payload($previous), 'created' => false];
            }
            $shift = DB::table('mini_app_work_shifts')->where('auth_session_id', $sessionId)->whereNull('ended_at')->first();
            if ($shift !== null && $shift->warehouse_id !== $warehouseId) {
                ApiError::abort(409, 'SHIFT_WAREHOUSE_CONFLICT', 'Phiên hiện tại đã bắt đầu ca tại kho khác.');
            }
            app(WarehouseContext::class)->assertMatches($actor, $sessionId, $warehouseId);
            $this->assertWarehouse($actor, $warehouseId, true);
            $created = $shift === null;
            if ($created) {
                if (! config('p14.shift_enabled') && DB::table('mini_app_work_shifts')->where('auth_session_id', $sessionId)->exists()) {
                    ApiError::abort(409, 'SHIFT_LIFECYCLE_NOT_ENABLED', 'Vòng đời ca tiếp theo chưa được kích hoạt.');
                }
                $shift = (object) [
                    'shift_id' => (string) Str::uuid(), 'actor_id' => (string) $actor->user_id,
                    'auth_session_id' => $sessionId, 'warehouse_id' => $warehouseId,
                    'started_at' => now()->utc()->format('Y-m-d H:i:sP'), 'ended_at' => null, 'version' => 1,
                    'tracking_contract' => config('p14.shift_enabled') ? P14ShiftService::CONTRACT : null,
                ];
                DB::table('mini_app_work_shifts')->insert((array) $shift);
                AuditLogger::record(
                    action: 'WORK_SHIFT_STARTED', objectType: 'mini_app_work_shift',
                    objectId: $shift->shift_id, actorId: (string) $actor->user_id,
                    requestId: request()->attributes->get('request_id'), afterData: $this->payload($shift),
                );
            }
            DB::table('mini_app_work_shift_keys')->insert([
                'auth_session_id' => $sessionId, 'idempotency_key' => $key,
                'warehouse_id' => $warehouseId, 'shift_id' => $shift->shift_id,
            ]);

            return ['data' => $this->payload($shift), 'created' => $created];
        }, 3);
    }

    private function assertActor(User $user): void
    {
        if ($user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản đang bị khoá hoặc ngừng hoạt động.');
        }
        foreach (explode('|', self::PERMISSIONS) as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }
        ApiError::abort(403, 'ACCESS_DENIED', 'Bạn không có quyền truy cập nghiệp vụ Mini App.');
    }

    private function assertWarehouse(User $user, string $warehouseId, bool $lock = false): void
    {
        if (! $user->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được phân quyền.');
        }
        $query = Warehouse::query()->whereKey($warehouseId);
        $warehouse = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
        }
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang tạm dừng hoạt động.');
        }
    }

    private function payload(stdClass $shift): array
    {
        return [
            'shift_id' => (string) $shift->shift_id, 'actor_id' => (string) $shift->actor_id,
            'auth_session_id' => (string) $shift->auth_session_id, 'warehouse_id' => (string) $shift->warehouse_id,
            'status' => $shift->ended_at === null ? 'ACTIVE' : 'ENDED', 'version' => (int) $shift->version,
            'started_at' => Carbon::parse((string) $shift->started_at, 'UTC')->utc()->toIso8601String(),
            'ended_at' => $shift->ended_at === null ? null : Carbon::parse($shift->ended_at)->utc()->toISOString(),
            'preview_ready' => $shift->ended_at === null,
        ];
    }
}
