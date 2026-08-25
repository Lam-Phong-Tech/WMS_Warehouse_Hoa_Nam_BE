<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * GĐ1 quantity-mode COMPONENT materialization.
 *
 * The receipt stays scan-driven: the operator selects a COMPONENT only when a
 * physical box is being received. This endpoint creates/fetches the technical
 * line required by the existing BOX + quantity scanner; it never stores a
 * planned quantity.
 */
class BaM1InboundComponentController extends Controller
{
    public function ensureLine(Request $request, string $id)
    {
        $validated = $request->validate([
            'sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
        ]);
        $actorId = $request->user()?->user_id;

        $result = DB::transaction(function () use ($id, $validated, $actorId): array {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được ghi nhận linh kiện.');
            }

            $sku = DB::table('sku')->where('sku_id', $validated['sku_id'])->lockForUpdate()->first();
            if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU linh kiện không tồn tại hoặc không hoạt động.');
            }
            if (strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Chỉ được chọn SKU linh kiện cho luồng Box + số lượng.');
            }
            if ((bool) $sku->is_serial_tracked) {
                ApiError::abort(422, 'SERIAL_COMPONENT_SCAN_REQUIRED', 'Linh kiện quản lý mã/serial phải quét mã từng đơn vị, không dùng nhập số lượng theo Box.');
            }

            $line = DB::table('stock_document_lines')
                ->where('stock_document_id', $id)
                ->where('product_id', $validated['sku_id'])
                ->orderBy('line_no')
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                $lineId = (string) Str::uuid();
                $lineNo = ((int) DB::table('stock_document_lines')
                    ->where('stock_document_id', $id)
                    ->max('line_no')) + 1;
                DB::table('stock_document_lines')->insert([
                    'id' => $lineId,
                    'stock_document_id' => $id,
                    'line_no' => $lineNo,
                    'product_id' => $validated['sku_id'],
                    'qty_planned' => 0,
                    'qty_actual' => 0,
                    'scanned_qty' => 0,
                    'completion_type' => 'full',
                    'shortage_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $line = DB::table('stock_document_lines')->where('id', $lineId)->first();
                AuditLogger::record(
                    action: 'INBOUND_COMPONENT_LINE_MATERIALIZED',
                    objectType: 'stock_document_line',
                    objectId: $lineId,
                    actorId: $actorId !== null ? (string) $actorId : null,
                    requestId: request()?->attributes->get('request_id'),
                    afterData: [
                        'stock_document_id' => $id,
                        'sku_id' => $validated['sku_id'],
                        'quantity_source' => 'ACTUAL_BOX_COUNT',
                        'qty_planned' => 0,
                    ],
                );
            }

            return [
                'line_id' => (string) $line->id,
                'document_id' => $id,
                'sku_id' => (string) $validated['sku_id'],
                'sku_code' => (string) $sku->sku_code,
                'sku_name' => (string) $sku->sku_name,
                'scanned_qty' => (int) $line->scanned_qty,
                'quantity_source' => 'ACTUAL_BOX_COUNT',
            ];
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Đã sẵn sàng ghi nhận linh kiện thực tế theo Box.',
            'data' => $result,
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
