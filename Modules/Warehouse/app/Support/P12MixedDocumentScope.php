<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

/** Server-owned scope; never infer a document's warehouse from this context. */
final class P12MixedDocumentScope
{
    public static function resolve(Request $request, ?string $requestedWarehouse): array
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'DOCUMENTS_PERMISSION_DENIED', 'Tài khoản không có quyền đọc chứng từ.');
        }
        $request->setUserResolver(fn () => $actor);
        $allowed = [
            'INBOUND' => $actor->hasPermission('inbound.view'),
            'OUTBOUND' => $actor->hasPermission('outbound.view'),
            'WARRANTY' => $actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage'),
        ];
        if (! in_array(true, $allowed, true)) {
            ApiError::abort(403, 'DOCUMENTS_PERMISSION_DENIED', 'Không có nguồn chứng từ được phép đọc.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $warehouseId = $context['active_warehouse_id'] ?? null;
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ! is_string($warehouseId)) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận kho làm việc trước khi đọc chứng từ.');
        }
        if ($requestedWarehouse !== null && $requestedWarehouse !== $warehouseId) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho yêu cầu không khớp phiên làm việc.');
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
                    ApiError::abort(403, 'DOCUMENTS_ORGANIZATION_MISMATCH', 'Không xác minh được phạm vi tổ chức.');
                }
                $dimensions[$field] = $value;
            }
        }
        // Reuse P09's persisted-owner and privacy scope, only for a permitted source.
        $warranty = $allowed['WARRANTY'] ? P09WarrantyScope::resolve($request) : null;

        return [
            'actor_id' => (string) $actor->user_id, 'actor_version' => (int) $actor->version,
            'auth_session_id' => $session, 'warehouse_id' => $warehouseId,
            'dimensions' => $dimensions, 'allowed' => $allowed, 'warranty' => $warranty,
        ];
    }

    /** Private binding is encrypted into the cursor, never emitted as identity metadata. */
    public static function fingerprint(array $scope, array $filters): string
    {
        return P09WarrantyPolicy::digest([
            'contract' => P12MixedDocumentReadModel::CONTRACT, 'scope' => $scope, 'filters' => $filters,
            'storage_timezone' => config('app.timezone', 'UTC'), 'driver' => DB::getDriverName(),
        ], (string) config('app.key'));
    }
}
