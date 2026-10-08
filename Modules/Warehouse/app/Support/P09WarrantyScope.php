<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

final class P09WarrantyScope
{
    public static function resolve(Request $request, bool $write = false): array
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE'
            || ($write ? ! $actor->hasPermission('warranty.manage') : ! ($actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage')))) {
            ApiError::abort(403, 'WARRANTY_PERMISSION_DENIED', 'Không có quyền bảo hành tương ứng.');
        }
        $request->setUserResolver(fn () => $actor);
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $warehouseId = $context['active_warehouse_id'] ?? null;
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ! is_string($warehouseId)) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận kho làm việc trước khi truy cập bảo hành.');
        }
        // Validate both input bags; a body must never hide a conflicting query scope.
        foreach ([$request->query(), $request->isJson() ? $request->json()->all() : $request->request->all()] as $bag) {
            $input = Validator::make($bag, ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
            if (isset($input['warehouse_id']) && strtolower($input['warehouse_id']) !== $warehouseId) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho yêu cầu không khớp kho của phiên đăng nhập.');
            }
            if (array_intersect(array_keys($bag), ['tenant_id', 'org_id', 'organization_id', 'actor_id', 'auth_session_id']) !== []) {
                ApiError::abort(422, 'WARRANTY_SCOPE_READ_ONLY', 'Phạm vi và người thao tác do máy chủ xác định.');
            }
        }
        if (! $actor->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->where('status', 'ACTIVE')->first();
        if ($warehouse === null) {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $value = $actor->getAttribute($field);
            $owner = $warehouse->{$field} ?? null;
            if ($value !== null || $owner !== null) {
                if (! is_string($value) || $value === '' || $value !== $owner) {
                    ApiError::abort(403, 'WARRANTY_ORGANIZATION_MISMATCH', 'Không xác minh được cùng tổ chức với kho.');
                }
                $dimensions[$field] = $value;
            }
        }
        return ['warehouse_id' => $warehouseId, 'actor_id' => (string) $actor->user_id,
            'auth_session_id' => $session, 'dimensions' => $dimensions,
            'pii' => $actor->hasPermission('warranty.pii.view'),
            'documents' => $actor->hasPermission('documents.read'),
            'component' => $actor->hasPermission('warranty.component_issue')];
    }

    public static function isolate(Builder $query, string $table, string $alias, array $scope): Builder
    {
        $columns = Schema::getColumnListing($table);
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            if (in_array($field, $columns, true)) {
                if (empty($scope['dimensions'][$field])) {
                    ApiError::abort(503, 'WARRANTY_TENANCY_UNRESOLVED', 'Chưa xác nhận phạm vi tổ chức của nguồn dữ liệu.');
                }
                $query->where($alias.'.'.$field, $scope['dimensions'][$field]);
            }
        }
        return $query;
    }

    public static function cases(array $scope): Builder
    {
        $query = DB::table('warranty_case as c')->whereExists(function (Builder $owner) use ($scope): void {
            $owner->selectRaw('1')->from(WarrantyWarehouseScope::TABLE.' as o')
                ->whereColumn('o.warranty_case_id', 'c.warranty_case_id')->where('o.warehouse_id', $scope['warehouse_id']);
            self::isolate($owner, WarrantyWarehouseScope::TABLE, 'o', $scope);
        });
        return self::isolate($query, 'warranty_case', 'c', $scope);
    }

    public static function requireCase(array $scope, string $id, bool $lock = false): object
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành.');
        }
        $query = self::cases($scope)->where('c.warranty_case_id', strtolower($id));
        if ($lock) {
            $query->lockForUpdate();
        }
        $case = $query->first('c.*');
        if ($case === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hồ sơ bảo hành trong kho.');
        }
        return $case;
    }

    public static function requireItem(array $scope, string $id, bool $lock = false): object
    {
        // Persisted provenance, never the current user's warehouse or SKU alone.
        if (! Schema::hasColumn('stock_movements', 'item_id')) {
            ApiError::abort(503, 'WARRANTY_ITEM_SCOPE_UNAVAILABLE', 'Nguồn đối chiếu kho của hiện vật chưa khả dụng.');
        }
        $q = DB::table('item_instances as i')->where('i.item_id', $id)->whereExists(function (Builder $m) use ($scope): void {
            $m->selectRaw('1')->from('stock_movements as m')->whereColumn('m.item_id', 'i.item_id')->where('m.warehouse_id', $scope['warehouse_id']);
            self::isolate($m, 'stock_movements', 'm', $scope);
        });
        self::isolate($q, 'item_instances', 'i', $scope);
        if ($lock) {
            $q->lockForUpdate();
        }
        $item = $q->first('i.*');
        if ($item === null) {
            ApiError::abort(404, 'WARRANTY_ITEM_NOT_FOUND', 'Không tìm thấy hiện vật trong phạm vi kho.');
        }
        return $item;
    }

    public static function key(array $scope): array
    {
        return array_intersect_key($scope, array_flip(['warehouse_id', 'actor_id', 'auth_session_id', 'dimensions']));
    }

    public static function metadata(array $scope): array
    {
        return ['contract' => 'mini-app-p09-v1', 'warehouse_id' => $scope['warehouse_id'],
            'scope_policy' => 'PERSISTED_WARRANTY_OWNER_BEFORE_TOTAL', 'timezone' => config('app.timezone'),
            'tenant_isolation' => $scope['dimensions'] === [] ? 'SINGLE_TENANT_DATABASE_REQUIRED' : 'TRUSTED_ACTOR_WAREHOUSE_AND_SOURCE_COLUMNS'];
    }
}
