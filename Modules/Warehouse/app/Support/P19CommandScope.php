<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

/** P19 authorization only. P03/P09 receipts are never used for component commands. */
final class P19CommandScope
{
    public static function resolve(Request $request): array
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('warranty.component_issue')) {
            ApiError::abort(403, 'COMPONENT_PERMISSION_DENIED', 'Không có quyền xuất linh kiện.');
        }
        $request->setUserResolver(fn () => $actor);
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $warehouseId = $context['active_warehouse_id'] ?? null;
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ! is_string($warehouseId)) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận kho làm việc trước khi tiếp tục.');
        }
        foreach ([$request->query(), $request->isJson() ? $request->json()->all() : $request->request->all()] as $bag) {
            $input = Validator::make($bag, ['warehouse_id' => ['sometimes', 'required', 'uuid']])->validate();
            if (isset($input['warehouse_id']) && strtolower($input['warehouse_id']) !== $warehouseId) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho yêu cầu không khớp phiên đăng nhập.');
            }
            if (array_intersect(array_keys($bag), ['tenant_id', 'org_id', 'organization_id', 'actor_id', 'auth_session_id', 'scan_session_id']) !== []) {
                ApiError::abort(422, 'P19_SCOPE_READ_ONLY', 'Phạm vi và phiên xác thực do máy chủ xác định.');
            }
        }
        if (! $actor->canAccessWarehouse($warehouseId)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho ngoài phạm vi được cấp.');
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
                    ApiError::abort(403, 'P19_ORGANIZATION_MISMATCH', 'Không xác minh được cùng tổ chức với kho.');
                }
                $dimensions[$field] = $value;
            }
        }

        return ['actor_id' => (string) $actor->user_id, 'auth_session_id' => $session,
            'warehouse_id' => $warehouseId, 'dimensions' => $dimensions];
    }

    public static function document(array $scope, string $id, bool $lock = false): object
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất linh kiện.');
        }
        $query = DB::table('component_issue_documents as d')->where('d.id', strtolower($id))
            ->where('d.warehouse_id', $scope['warehouse_id']);
        P09WarrantyScope::isolate($query, 'component_issue_documents', 'd', $scope);
        if ($lock) {
            $query->lockForUpdate();
        }
        $document = $query->first('d.*');
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu trong kho.');
        }
        P09WarrantyScope::requireCase($scope, (string) $document->warranty_case_id, $lock);

        return $document;
    }

    public static function digest(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }
}
