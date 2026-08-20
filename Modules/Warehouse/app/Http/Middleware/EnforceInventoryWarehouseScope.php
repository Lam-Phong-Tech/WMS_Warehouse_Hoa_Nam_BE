<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\DenyAuditRecorder;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-211 defense-in-depth authorization for the six inventory read/trace APIs.
 *
 * Route permission middleware remains authoritative for function permission.
 * This middleware adds warehouse data-scope enforcement that cannot be replaced
 * by FE hide/disable behavior. It also prevents physical-code trace responses
 * from leaking movements belonging to a warehouse outside the effective scope.
 */
class EnforceInventoryWarehouseScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        if (! $this->isTargetPath($path)) {
            return $next($request);
        }

        $user = auth('api')->user();
        if (! $user instanceof User) {
            // auth:api on the route will produce the canonical 401 envelope.
            return $next($request);
        }

        // Trace is an elevated read operation. Existing route middleware checks
        // inventory.trace; require inventory.view as the base read permission too.
        if ($this->isTracePath($path) && ! $user->hasPermission('inventory.view')) {
            return $this->forbidden($request, 'Thiếu quyền inventory.view cho truy vết tồn kho.');
        }

        $warehouseIds = $user->warehouseScopeIds();
        if ($warehouseIds === null) {
            return $next($request);
        }

        if ($request->filled('warehouse_id')) {
            $requestedWarehouseId = (string) $request->input('warehouse_id');
            if (! in_array($requestedWarehouseId, $warehouseIds, true)) {
                return $this->forbidden($request, 'Kho yêu cầu nằm ngoài data scope hiện hành.');
            }
        }

        if (preg_match('#^api/v1/inventory/balances/([^/]+)/subledger$#', $path, $matches) === 1) {
            $warehouseId = DB::table('inventory_balances')->where('id', $matches[1])->value('warehouse_id');
            if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
                return $this->forbidden($request, 'Balance nằm ngoài warehouse data scope hiện hành.');
            }
        }

        if (preg_match('#^api/v1/stock-movements/([^/]+)(?:/trace-source)?$#', $path, $matches) === 1) {
            $reservedSegments = ['reversible', 'trace-by-code'];
            if (! in_array($matches[1], $reservedSegments, true)) {
                $warehouseId = DB::table('stock_movements')->where('id', $matches[1])->value('warehouse_id');
                if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
                    return $this->forbidden($request, 'Movement nằm ngoài warehouse data scope hiện hành.');
                }
            }
        }

        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }

        if ($this->isPhysicalCodeTracePath($path)) {
            return $this->filterPhysicalCodeTrace($request, $response, $warehouseIds);
        }

        if ($path === 'api/v1/stock-movements/reversible') {
            return $this->filterReversibleLookup($response, $warehouseIds);
        }

        return $response;
    }

    /** @param list<string> $warehouseIds */
    private function filterPhysicalCodeTrace(Request $request, JsonResponse $response, array $warehouseIds): Response
    {
        $payload = $response->getData(true);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $movements = is_array($data['movements'] ?? null) ? $data['movements'] : [];

        $scopedMovements = array_values(array_filter(
            $movements,
            fn ($row): bool => is_array($row)
                && isset($row['warehouse_id'])
                && in_array((string) $row['warehouse_id'], $warehouseIds, true),
        ));

        // A restricted user may only resolve a physical object when at least one
        // movement proves that object belongs to an accessible warehouse. This is
        // intentionally fail-closed because physical_codes/item/container rows do
        // not carry a canonical warehouse_id in the current ERD.
        if ($movements !== [] && $scopedMovements === []) {
            return $this->forbidden($request, 'Mã vật lý chỉ có lịch sử ngoài warehouse data scope hiện hành.');
        }

        if ($movements === [] && ! empty($data['resolved_id'])) {
            return $this->forbidden($request, 'Không có movement trong scope để chứng minh quyền truy cập mã vật lý.');
        }

        $payload['data']['movements'] = $scopedMovements;
        $response->setData($payload);

        return $response;
    }

    /**
     * `/stock-movements/reversible` chạy bằng query builder thô nên không được
     * global scope của model bảo vệ. Lọc phản hồi theo warehouse scope để lookup
     * Reversal không lộ movement của kho ngoài phạm vi.
     *
     * @param  list<string>  $warehouseIds
     */
    private function filterReversibleLookup(JsonResponse $response, array $warehouseIds): Response
    {
        $payload = $response->getData(true);
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $payload['data'] = array_values(array_filter(
            $rows,
            fn ($row): bool => is_array($row)
                && isset($row['warehouse_id'])
                && in_array((string) $row['warehouse_id'], $warehouseIds, true),
        ));
        $response->setData($payload);

        return $response;
    }

    private function isTargetPath(string $path): bool
    {
        if (in_array($path, [
            // Annex C canonical queryInventory + legacy alias.
            'api/v1/inventory',
            'api/v1/inventory/balances',
            'api/v1/stock-movements',
            'api/v1/stock-movements/reversible',
            'api/v1/stock-movements/trace-by-code',
        ], true)) {
            return true;
        }

        return preg_match('#^api/v1/inventory/balances/[^/]+/subledger$#', $path) === 1
            // Annex C canonical traceInventoryCode: cùng nghiệp vụ trace-by-code
            // nên phải chịu cùng data-scope guard, không được là đường vòng.
            || preg_match('#^api/v1/inventory/trace/.+$#', $path) === 1
            || preg_match('#^api/v1/stock-movements/[^/]+$#', $path) === 1
            || preg_match('#^api/v1/stock-movements/[^/]+/trace-source$#', $path) === 1;
    }

    private function isTracePath(string $path): bool
    {
        return $path === 'api/v1/stock-movements/trace-by-code'
            || preg_match('#^api/v1/inventory/trace/.+$#', $path) === 1
            || str_ends_with($path, '/trace-source');
    }

    private function isPhysicalCodeTracePath(string $path): bool
    {
        return $path === 'api/v1/stock-movements/trace-by-code'
            || preg_match('#^api/v1/inventory/trace/.+$#', $path) === 1;
    }

    private function forbidden(Request $request, string $message): JsonResponse
    {
        // WWHN-165 / QA1-AUD-002: denied action audit (best-effort, ADR-AUDIT-002).
        // Error code của guard này là ACCESS_DENIED (WWHN-211) — giữ nguyên vocabulary.
        DenyAuditRecorder::dataScopeDenied($request, $message, errorCode: 'ACCESS_DENIED');

        $requestId = $request->attributes->get('request_id');

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'ACCESS_DENIED',
            'errors' => [],
            'error' => [
                'code' => 'ACCESS_DENIED',
                'details' => [],
            ],
            'meta' => [
                'request_id' => is_string($requestId) ? $requestId : null,
                'timestamp' => now()->toIso8601String(),
            ],
        ], 403);
    }
}
