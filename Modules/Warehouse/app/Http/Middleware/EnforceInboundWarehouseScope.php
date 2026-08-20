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
 * WWHN-289 (DEV289-P1-04) — data scope cho luồng nhập kho.
 *
 * SRS §8.1 "Enforcement standard" yêu cầu MỌI endpoint được bảo vệ phải kiểm tra
 * "exact x-permission + data scope" trước khi service ghi dữ liệu, và §8.2 quy định
 * 403 DATA_SCOPE_DENIED. Luồng xuất đã có EnforceOutboundWarehouseScope, luồng đọc
 * tồn đã có EnforceInventoryWarehouseScope, nhưng toàn bộ endpoint nhập kho
 * (create/update/scan/unscan/post-receipt/reverse/box/scan-entries) trước đây chỉ
 * kiểm tra permission chức năng. Hệ quả: user bị giới hạn `scope_ref` sang kho khác
 * vẫn tạo được stock effect (Post Receipt = movement + balance) trên kho ngoài phạm vi.
 *
 * EnsureCanonicalInboundWarehouse KHÔNG thay thế được guard này: nó chỉ ép phiếu nhập
 * dùng đúng kho canonical đang ACTIVE (invariant MVP), không hề đọc scope của actor.
 *
 * Middleware này là fail-closed và stateless: SUPER_ADMIN / role không gắn scope_ref
 * (warehouseScopeIds() === null) đi qua nguyên trạng.
 */
class EnforceInboundWarehouseScope
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

        foreach (['dst_warehouse_id', 'warehouse_id'] as $field) {
            if ($request->filled($field)
                && ! in_array((string) $request->input($field), $warehouseIds, true)) {
                return $this->denied($request, 'Kho yêu cầu nằm ngoài data scope hiện hành.');
            }
        }

        $documentId = $this->documentId($request);
        if ($documentId !== null) {
            $warehouseId = DB::table('stock_documents')
                ->where('id', $documentId)
                ->where('doc_type', 'receipt')
                ->value('dst_warehouse_id');
            if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
                return $this->denied($request, 'Phiếu nhập nằm ngoài warehouse data scope hiện hành.');
            }
        }

        return $next($request);
    }

    private function documentId(Request $request): ?string
    {
        foreach (['id', 'documentId'] as $parameter) {
            $value = $request->route($parameter);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function denied(Request $request, string $message): JsonResponse
    {
        // WWHN-165 / QA1-AUD-002: denied action audit (best-effort, ADR-AUDIT-002).
        DenyAuditRecorder::dataScopeDenied($request, $message);

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
