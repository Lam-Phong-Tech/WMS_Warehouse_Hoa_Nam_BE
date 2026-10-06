<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\IdempotencyStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\Warehouse;

/** Persisted receipt ownership; never inferred from the item's location or the first allowed warehouse. */
final class WarrantyWarehouseScope
{
    public const TABLE = 'warranty_case_warehouse_owners';

    public const VERSION = 'warranty-owner-v1';

    public const OPEN_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'];

    public static function actor(Request $request): User
    {
        $user = $request->user() ?? auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập để truy cập hồ sơ bảo hành.');
        }

        return $user;
    }

    /** Read APIs keep their existing access-token transport and do not require a work shift. */
    public static function readSelection(Request $request): array
    {
        $data = Validator::make($request->query(), ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
        $id = isset($data['warehouse_id']) ? strtolower($data['warehouse_id']) : null;
        $actor = self::actor($request);
        $scope = $actor->warehouseScopeIds();
        if ($id !== null) {
            if (! $actor->canAccessWarehouse($id)) {
                ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Kho bảo hành nằm ngoài phạm vi được cấp.');
            }
            if (! Warehouse::query()->whereKey($id)->exists()) {
                ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
            }
        }

        return ['warehouse_id' => $id, 'scope_ids' => $scope];
    }

    public static function query(Request $request, string $alias = 'warranty_case'): Builder
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid internal warranty query alias.');
        }
        $query = DB::table($alias === 'warranty_case' ? 'warranty_case' : 'warranty_case as '.$alias);

        return self::apply($query, $request, $alias.'.warranty_case_id');
    }

    /** EXISTS preserves one row per case and does not discard TEMP_ONLY rows with null item/product IDs. */
    public static function apply(Builder $query, Request $request, string $caseColumn): Builder
    {
        $selection = self::readSelection($request);
        $id = $selection['warehouse_id'];
        $scope = $selection['scope_ids'];
        if ($id === null && $scope === null) {
            return $query; // Authorized all-warehouse view includes explicitly-labelled legacy unassigned cases.
        }

        return $query->whereExists(function (Builder $owners) use ($caseColumn, $id, $scope): void {
            $owners->selectRaw('1')->from(self::TABLE.' as warranty_owner')
                ->whereColumn('warranty_owner.warranty_case_id', $caseColumn);
            if ($id !== null) {
                $owners->where('warranty_owner.warehouse_id', $id);
            }
            if ($scope !== null) {
                $owners->whereIn('warranty_owner.warehouse_id', $scope);
            }
        });
    }

    public static function metadata(Request $request): array
    {
        $selection = self::readSelection($request);
        // Coverage flag only, never the IDs/count/PII of unassigned cases outside the caller's scope.
        $unassigned = DB::table('warranty_case as legacy')->whereNotExists(function (Builder $q): void {
            $q->selectRaw('1')->from(self::TABLE.' as owner')
                ->whereColumn('owner.warranty_case_id', 'legacy.warranty_case_id');
        })->exists();

        return [
            'contract' => self::VERSION,
            'warehouse_id' => $selection['warehouse_id'],
            'unassigned_policy' => $selection['warehouse_id'] === null && $selection['scope_ids'] === null ? 'INCLUDED' : 'EXCLUDED',
            'ownership_complete' => ! $unassigned,
        ];
    }

    public static function prepareCreate(Request $request): void
    {
        $request->attributes->set('warranty.create_owner', self::resolveCreate($request));
    }

    public static function creationHash(Request $request): string
    {
        $owner = $request->attributes->get('warranty.create_owner');

        return hash('sha256', IdempotencyStore::requestHash($request).'|'.self::VERSION.'|'.$owner['warehouse_id']);
    }

    /** Lock in the same user -> warehouse order as switch-context/start; verify again before creating a case. */
    public static function lockCreate(Request $request): void
    {
        $expected = $request->attributes->get('warranty.create_owner');
        $locked = self::resolveCreate($request, true);
        if (! is_array($expected) || $expected['warehouse_id'] !== $locked['warehouse_id']
            || $expected['auth_session_id'] !== $locked['auth_session_id']) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Ngữ cảnh kho đã thay đổi; tải lại phiên trước khi tiếp nhận.');
        }
        $request->attributes->set('warranty.locked_owner', $locked);
    }

    public static function bindNew(Request $request, string $caseId): void
    {
        $owner = $request->attributes->get('warranty.locked_owner');
        if (! is_array($owner) || DB::transactionLevel() < 1) {
            throw new \LogicException('Warranty ownership must be captured in the case creation transaction.');
        }
        DB::table(self::TABLE)->insert([
            'warranty_case_id' => $caseId,
            ...$owner,
            'assigned_at' => now()->utc(),
            'evidence' => null,
        ]);
    }

    private static function resolveCreate(Request $request, bool $lock = false): array
    {
        $user = self::actor($request);
        if ($lock) {
            $user = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
        }
        if ($user === null || $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn khả dụng.');
        }
        self::validateOwnerInput($request);
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $explicit = isset($body['warehouse_id']) ? strtolower($body['warehouse_id']) : null;
        $sessions = app(StableAuthSession::class);
        $sessionId = $sessions->id($user);
        if ($sessionId === null && app(RefreshTokenService::class)->currentSessionId() !== null) {
            $sessions->requireId($user); // A revoked refresh family must not fall back to an explicit body.
        }
        $context = app(WarehouseContext::class)->payload($user, $sessionId, 'AUTH_SESSION_REQUIRED');
        $state = $context['warehouse_context']['status'];
        if ($state === 'UNAVAILABLE') {
            $code = $context['warehouse_context']['error_code'];
            ApiError::abort($code === 'WAREHOUSE_ACCESS_DENIED' ? 403 : 409, $code, 'Ngữ cảnh kho không còn hợp lệ.');
        }
        $selected = $context['active_warehouse_id'];
        if ($selected !== null && $explicit !== null && $selected !== $explicit) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'warehouse_id không khớp kho đã xác nhận trong phiên.');
        }
        $id = $selected ?? $explicit;
        if ($id === null) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận kho làm việc hoặc gửi warehouse_id đã được xác nhận trước khi tiếp nhận bảo hành.');
        }
        if (! $user->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Kho bảo hành nằm ngoài phạm vi được cấp.');
        }
        $warehouseQuery = Warehouse::query()->whereKey($id);
        if ($lock) {
            $warehouseQuery->lockForUpdate();
        }
        $warehouse = $warehouseQuery->first();
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
        }
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang tạm dừng hoạt động.');
        }

        return [
            'warehouse_id' => (string) $warehouse->id,
            'source' => $selected !== null ? 'CURRENT_CONTEXT' : 'EXPLICIT_REQUEST',
            'auth_session_id' => $sessionId,
            'actor_id' => (string) $user->user_id,
        ];
    }

    public static function validateOwnerInput(Request $request): void
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $forbidden = array_values(array_intersect(array_keys($body), [
            'warehouse_code', 'warehouse_name', 'warehouse_status', 'warehouse_ownership', 'owner_warehouse_id', 'warehouse_context',
        ]));
        if ($forbidden !== []) {
            ApiError::abort(422, 'WARRANTY_OWNER_READ_ONLY', 'Thông tin sở hữu kho do máy chủ xác định.', ['fields' => $forbidden]);
        }
        Validator::make($body, ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
    }

    /** Guard BEFORE cached/idempotency responses, including events and attachment downloads. */
    public static function assertCase(Request $request, string $id, bool $write = false): void
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành.');
        }
        $actor = self::actor($request);
        $selection = self::readSelection($request);
        $owner = DB::table(self::TABLE)->where('warranty_case_id', $id)->first();
        if ($owner !== null && ! $actor->canAccessWarehouse((string) $owner->warehouse_id)) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Không có quyền truy cập kho sở hữu hồ sơ bảo hành.');
        }
        if ($owner === null && $selection['scope_ids'] !== null) {
            ApiError::abort(403, 'WARRANTY_WAREHOUSE_ACCESS_DENIED', 'Không có quyền truy cập hồ sơ trong phạm vi kho này.');
        }
        if (! DB::table('warranty_case')->where('warranty_case_id', $id)->exists()) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành.');
        }
        if ($selection['warehouse_id'] !== null && ($owner === null || $selection['warehouse_id'] !== (string) $owner->warehouse_id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ trong kho đã lọc.');
        }
        if (! $write) {
            return;
        }
        self::validateOwnerInput($request);
        if ($owner === null) {
            ApiError::abort(409, 'WARRANTY_WAREHOUSE_UNASSIGNED', 'Hồ sơ cũ chưa xác nhận kho sở hữu; cần quản trị đối chiếu trước khi thao tác.');
        }
        $bodyId = $request->input('warehouse_id');
        if ($bodyId !== null && strtolower($bodyId) !== (string) $owner->warehouse_id) {
            ApiError::abort(409, 'WARRANTY_WAREHOUSE_IMMUTABLE', 'Không được thay đổi kho sở hữu bằng thao tác cập nhật hồ sơ.');
        }
        $sessionId = app(StableAuthSession::class)->id($actor);
        if ($sessionId === null && app(RefreshTokenService::class)->currentSessionId() !== null) {
            app(StableAuthSession::class)->requireId($actor);
        }
        if ($sessionId !== null) {
            $context = app(WarehouseContext::class)->payload($actor, $sessionId, 'AUTH_SESSION_REQUIRED');
            if ($context['warehouse_context']['status'] === 'UNAVAILABLE') {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Ngữ cảnh kho không còn hợp lệ; tải lại phiên.');
            }
            if ($context['active_warehouse_id'] !== null && $context['active_warehouse_id'] !== (string) $owner->warehouse_id) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho của hồ sơ không khớp kho đang làm việc.');
            }
        }
        $warehouse = Warehouse::query()->find($owner->warehouse_id);
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho sở hữu hồ sơ không còn hoạt động; chỉ được đọc.');
        }
    }

    public static function cacheContext(Request $request): array
    {
        $selection = self::readSelection($request);
        $revision = DB::table(self::TABLE)->selectRaw('COUNT(*) as total')->selectRaw('MAX(assigned_at) as latest')->first();

        return [self::VERSION, $selection, $revision->total, $revision->latest];
    }

    /** Dashboard builders already receive their validated effective warehouse set, not an HTTP request. */
    public static function dashboardQuery(?array $warehouseIds): Builder
    {
        $query = self::query(new Request);
        if ($warehouseIds !== null) {
            $query->whereExists(function (Builder $owners) use ($warehouseIds): void {
                $owners->selectRaw('1')->from(self::TABLE.' as dashboard_owner')
                    ->whereColumn('dashboard_owner.warranty_case_id', 'warranty_case.warranty_case_id')
                    ->whereIn('dashboard_owner.warehouse_id', $warehouseIds);
            });
        }

        return $query;
    }

    public static function fields(string $caseId): array
    {
        return self::fieldsFor([$caseId])[$caseId] ?? self::unassignedFields();
    }

    public static function fieldsFor(array $ids): array
    {
        $rows = $ids === [] ? collect() : DB::table(self::TABLE.' as owner')
            ->leftJoin('warehouses as warehouse', 'warehouse.id', '=', 'owner.warehouse_id')
            ->whereIn('owner.warranty_case_id', array_values(array_unique($ids)))
            ->get(['owner.warranty_case_id', 'owner.warehouse_id', 'owner.source', 'warehouse.code', 'warehouse.name', 'warehouse.status']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->warranty_case_id] = [
                'warehouse_id' => (string) $row->warehouse_id,
                'warehouse_code' => $row->code,
                'warehouse_name' => $row->name,
                'warehouse_status' => $row->status,
                'warehouse_ownership' => (string) $row->source,
            ];
        }

        return $result;
    }

    public static function enrich(iterable $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row->warranty_case_id;
        }
        $fields = self::fieldsFor($ids);
        foreach ($rows as $row) {
            foreach ($fields[(string) $row->warranty_case_id] ?? self::unassignedFields() as $key => $value) {
                $row->{$key} = $value;
            }
        }
    }

    private static function unassignedFields(): array
    {
        return ['warehouse_id' => null, 'warehouse_code' => null, 'warehouse_name' => null, 'warehouse_status' => null, 'warehouse_ownership' => 'UNASSIGNED'];
    }
}
