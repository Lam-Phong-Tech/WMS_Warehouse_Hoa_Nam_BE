<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanonicalInboundWarehouse
{
    public const CANONICAL_WAREHOUSE_CODE = 'KHO-TONG-HN';

    public function handle(Request $request, Closure $next): Response
    {
        $warehouseId = trim((string) $request->input('dst_warehouse_id', ''));

        if ($warehouseId === '') {
            $documentId = trim((string) ($request->route('id') ?? ''));
            if ($documentId !== '') {
                $warehouseId = (string) (DB::table('stock_documents')
                    ->where('id', $documentId)
                    ->value('dst_warehouse_id') ?? '');
            }
        }

        // Missing required payload is still handled by the controller validator.
        // This middleware owns only the canonical-warehouse invariant.
        if ($warehouseId === '') {
            return $next($request);
        }

        $warehouse = DB::table('warehouses')
            ->where('warehouse_id', $warehouseId)
            ->first(['warehouse_id', 'warehouse_code', 'warehouse_name', 'status']);

        if ($warehouse === null
            || strtoupper(trim((string) $warehouse->warehouse_code)) !== self::CANONICAL_WAREHOUSE_CODE
            || strtoupper(trim((string) $warehouse->status)) !== 'ACTIVE') {
            ApiError::abort(
                422,
                'INBOUND_WAREHOUSE_NOT_CANONICAL',
                'Phiếu nhập MVP chỉ được phép sử dụng Kho tổng Hòa Nam đang ACTIVE.',
                [
                    'dst_warehouse_id' => $warehouseId,
                    'required_warehouse_code' => self::CANONICAL_WAREHOUSE_CODE,
                ],
            );
        }

        return $next($request);
    }
}
