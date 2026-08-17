<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureInboundComponentBox
{
    public function handle(Request $request, Closure $next): Response
    {
        $documentId = (string) $request->route('id');
        $lineId = trim((string) $request->input('line_id', ''));

        // Let the controller's canonical validator own the missing-line error shape.
        if ($documentId === '' || $lineId === '') {
            return $next($request);
        }

        $line = DB::table('stock_document_lines')
            ->where('id', $lineId)
            ->where('stock_document_id', $documentId)
            ->first();
        if ($line === null) {
            return $next($request);
        }

        $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
        if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
            return $next($request);
        }

        $containerId = trim((string) $request->input('container_id', ''));
        if ($containerId === '') {
            ApiError::abort(
                422,
                'COMPONENT_BOX_REQUIRED',
                'Dòng nhập COMPONENT bắt buộc phải gắn BOX trước khi scan.',
                ['line_id' => $lineId]
            );
        }

        $box = DB::table('containers')->where('container_id', $containerId)->first();
        if ($box === null) {
            ApiError::abort(422, 'COMPONENT_BOX_NOT_FOUND', 'Không tìm thấy BOX được gửi trong container_id.', [
                'container_id' => $containerId,
                'line_id' => $lineId,
            ]);
        }
        if ((string) $box->container_type !== 'BOX') {
            ApiError::abort(422, 'COMPONENT_BOX_REQUIRED', 'container_id của COMPONENT phải trỏ tới container loại BOX.', [
                'container_id' => $containerId,
                'container_type' => (string) $box->container_type,
            ]);
        }
        if ((string) $box->primary_sku_id !== (string) $line->product_id) {
            ApiError::abort(422, 'BOX_SKU_MISMATCH', 'Một BOX chỉ được nhận đúng SKU COMPONENT đã đăng ký.', [
                'container_id' => $containerId,
                'box_sku_id' => (string) ($box->primary_sku_id ?? ''),
                'line_sku_id' => (string) $line->product_id,
            ]);
        }
        if ($box->parent_container_id !== null || (string) $box->status !== 'EMPTY') {
            ApiError::abort(409, 'BOX_NOT_RECEIVABLE', 'BOX đã được lưu kho, đã Post Receipt hoặc không còn ở trạng thái nhận hàng.', [
                'container_id' => $containerId,
                'status' => (string) $box->status,
                'parent_container_id' => $box->parent_container_id,
            ]);
        }

        $usedByOtherActiveReceipt = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.container_id', $containerId)
            ->where('d.id', '<>', $documentId)
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            // Entry đã gỡ (ERD bảng 28 status=REMOVED) không còn chiếm BOX.
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '<>', 'REMOVED');
            })
            ->exists();
        if ($usedByOtherActiveReceipt) {
            ApiError::abort(409, 'BOX_IN_OTHER_ACTIVE_RECEIPT', 'BOX đang được dùng bởi một phiếu nhập khác chưa kết thúc.', [
                'container_id' => $containerId,
            ]);
        }

        return $next($request);
    }
}
