<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-239 (DEV239-P1-03/P1-04) — data scope cho luồng Reversal.
 *
 * SRS v1.12 §8.1 "Enforcement standard | All protected endpoints | Exact
 * x-permission + data scope; default deny" và DEC-REV-001 §3 bất biến 9
 * ("Backend enforce permission và warehouse/data scope") áp cho MỌI endpoint
 * reversal. Trước thay đổi này, bảy route `/reversal-documents*` chỉ có
 * `permission:inventory.view|inventory.reversal.post` mà không có guard scope:
 *
 *   GET    /reversal-documents            (đọc chứng từ đảo của kho ngoài scope)
 *   GET    /reversal-documents/{id}
 *   GET    /reversal-documents/{id}/lines
 *   POST   /reversal-documents            (tạo + Post → stock effect kho ngoài scope)
 *   POST   /reversal-documents/{id}/confirm
 *   POST   /reversal-documents/{id}/post  (điểm duy nhất sinh movement)
 *   POST   /reversal-documents/{id}/cancel
 *
 * Hai route `inbound-documents/{id}/reverse(als)` và
 * `outbound-documents/{id}/reverse(als)` đã có Enforce{Inbound,Outbound}WarehouseScope
 * nên không bị lỗ này; middleware ở đây đóng đúng phần còn lại của cùng một luồng.
 *
 * Fail-closed và stateless: SUPER_ADMIN / role không gắn `scope_ref`
 * (warehouseScopeIds() === null) đi qua nguyên trạng. Danh sách
 * (GET /reversal-documents) được lọc ngay trong query của controller để phân
 * trang vẫn đúng; middleware chịu trách nhiệm phần tham số và truy cập theo id.
 */
class EnforceReversalWarehouseScope
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

        $reversalId = $this->routeId($request);
        if ($reversalId !== null) {
            $warehouseId = $this->reversalWarehouseId($reversalId);
            if ($warehouseId !== null && ! in_array($warehouseId, $warehouseIds, true)) {
                return $this->denied($request, 'Reversal document nằm ngoài warehouse data scope hiện hành.');
            }
        }

        // POST /reversal-documents: kho hiệu lực nằm ở chứng từ/movement gốc.
        $referenceId = $request->input('reference_document_id');
        if (is_string($referenceId) && $referenceId !== '') {
            $warehouseId = $this->referenceWarehouseId($referenceId);
            if ($warehouseId !== null && ! in_array($warehouseId, $warehouseIds, true)) {
                return $this->denied($request, 'Chứng từ gốc nằm ngoài warehouse data scope hiện hành.');
            }
        }

        return $next($request);
    }

    private function routeId(Request $request): ?string
    {
        $value = $request->route('id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `reversal_documents` là bảng vật lý trên PostgreSQL và view trên sqlite
     * (migration 2026_08_10_000791 chỉ chạy trên pgsql). Đọc theo tên đang tồn
     * tại để guard không phụ thuộc driver.
     */
    private function reversalWarehouseId(string $reversalId): ?string
    {
        if ($this->readable('reversal_documents')) {
            $value = DB::table('reversal_documents')->where('id', $reversalId)->value('warehouse_id');
            if ($value !== null) {
                return (string) $value;
            }
        }

        if ($this->readable('reversal_document')) {
            $value = DB::table('reversal_document')
                ->where('reversal_document_id', $reversalId)
                ->value('warehouse_id');
            if ($value !== null) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * reference_document_id của controller nhận stock_document.id, movement.id
     * hoặc source_id; giải bằng đúng thứ tự đó rồi quy về warehouse hiệu lực.
     */
    private function referenceWarehouseId(string $referenceId): ?string
    {
        $document = DB::table('stock_documents')
            ->where('id', $referenceId)
            ->first(['doc_type', 'src_warehouse_id', 'dst_warehouse_id']);
        if ($document !== null) {
            $warehouseId = strtolower((string) $document->doc_type) === 'receipt'
                ? $document->dst_warehouse_id
                : $document->src_warehouse_id;

            return $warehouseId === null ? null : (string) $warehouseId;
        }

        $movementWarehouseId = DB::table('stock_movements')
            ->where(function ($query) use ($referenceId): void {
                $query->where('id', $referenceId)->orWhere('source_id', $referenceId);
            })
            ->whereIn('movement_type', ['RECEIPT', 'ISSUE'])
            ->value('warehouse_id');

        return $movementWarehouseId === null ? null : (string) $movementWarehouseId;
    }

    private function readable(string $table): bool
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        return $schema->hasTable($table) || $schema->hasView($table);
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
