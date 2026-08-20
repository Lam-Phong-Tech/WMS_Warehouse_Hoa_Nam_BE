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
 * SRS §8.1 "Enforcement standard": mọi endpoint được bảo vệ phải kiểm tra
 * "exact x-permission + data scope" trước khi service ghi dữ liệu; §8.2 quy định
 * 403 DATA_SCOPE_DENIED. Permission middleware chỉ chặn chức năng, middleware này
 * chặn dữ liệu ngoài warehouse scope của luồng 03 (phiếu xuất + nhãn của phiếu).
 */
class EnforceOutboundWarehouseScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if (! $user instanceof User) {
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

        $documentId = $this->documentId($request);
        if ($documentId !== null) {
            $warehouseId = DB::table('stock_documents')
                ->where('id', $documentId)
                ->where('doc_type', 'issue')
                ->value('src_warehouse_id');
            if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
                return $this->denied($request, 'Phiếu xuất nằm ngoài warehouse data scope hiện hành.');
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
