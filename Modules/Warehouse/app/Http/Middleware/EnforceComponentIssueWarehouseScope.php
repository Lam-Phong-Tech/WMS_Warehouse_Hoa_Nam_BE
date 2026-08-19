<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-234 F-05 — data scope cho luồng Component Issue (Flow 04 warranty).
 *
 * SRS §8.1 "Enforcement standard | All protected endpoints | Exact x-permission +
 * data scope; default deny" áp cho MỌI endpoint đổi tồn, giống inbound/outbound/
 * reversal. `component_issue_documents` (ERD bảng 48) có cột `warehouse_id` riêng
 * (không suy ra từ warranty_case, vì case không neo kho), nhưng route
 * `component-issue-documents/*` và bí danh `warranty-component-issues/*` trước đây
 * chỉ có `permission:warranty.component_issue` — actor bị giới hạn `scope_ref` sang
 * kho khác vẫn Post được Component Issue (stock effect COMPONENT DECREASE) trên kho
 * ngoài phạm vi.
 *
 * Không khai thác được dưới MVP một kho (REQ-SRS-001: mọi role gắn scope_ref đều trỏ
 * về đúng một kho ACTIVE), nhưng đây là lỗ hổng "Enforcement standard" thật khi mở
 * rộng multi-warehouse — vá tại nguồn thay vì disposition N/A.
 *
 * Fail-closed và stateless: SUPER_ADMIN / role không gắn scope_ref
 * (warehouseScopeIds() === null) đi qua nguyên trạng, giống mọi middleware
 * Enforce*WarehouseScope khác trong module này.
 */
class EnforceComponentIssueWarehouseScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if (! $user instanceof User) {
            // auth:api trên route tự sinh 401 envelope chuẩn.
            return $next($request);
        }

        $warehouseIds = $user->warehouseScopeIds();
        if ($warehouseIds === null) {
            return $next($request);
        }

        if ($request->filled('warehouse_id')
            && ! in_array((string) $request->input('warehouse_id'), $warehouseIds, true)) {
            return $this->denied($request, 'Kho yêu cầu nằm ngoài data scope hiện hành.');
        }

        $documentId = $this->routeId($request);
        if ($documentId !== null) {
            $warehouseId = DB::table('component_issue_documents')->where('id', $documentId)->value('warehouse_id');
            if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
                return $this->denied($request, 'Component Issue nằm ngoài warehouse data scope hiện hành.');
            }
        }

        return $next($request);
    }

    private function routeId(Request $request): ?string
    {
        $value = $request->route('id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function denied(Request $request, string $message): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'DATA_SCOPE_DENIED',
            'errors' => [],
            'error' => [
                'code' => 'DATA_SCOPE_DENIED',
                'details' => [],
            ],
            'meta' => [
                'request_id' => is_string($requestId) ? $requestId : null,
                'timestamp' => now()->toIso8601String(),
            ],
        ], 403);
    }
}
