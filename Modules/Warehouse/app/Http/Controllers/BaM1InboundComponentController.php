<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * GĐ1 COMPONENT materialization for an actual Box receipt.
 *
 * This never captures an expected quantity. For serial-tracked components the
 * operator scans each physical code; for non-serial components the operator
 * enters the quantity physically counted in the Box.
 */
class BaM1InboundComponentController extends Controller
{
    use CachesReadPayload;

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
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Chỉ được chọn SKU linh kiện cho luồng nhận theo Box.');
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
                // completion_type/shortage_reason đã bị gỡ khỏi schema (migration
                // 2026_08_10_000620) — không được ghi vào.
                DB::table('stock_document_lines')->insert([
                    'id' => $lineId,
                    'stock_document_id' => $id,
                    'line_no' => $lineNo,
                    'product_id' => $validated['sku_id'],
                    'qty_planned' => 0,
                    'qty_actual' => 0,
                    'scanned_qty' => 0,
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
                        'quantity_source' => (bool) $sku->is_serial_tracked
                            ? 'ACTUAL_CODE_SCAN'
                            : 'ACTUAL_BOX_COUNT',
                        'qty_planned' => 0,
                    ],
                );
                // Thêm dòng COMPONENT là stock-neutral nhưng đổi danh sách dòng của
                // phiếu nhập mà read cache đang phục vụ.
                DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
            }

            return [
                'line_id' => (string) $line->id,
                'document_id' => $id,
                'sku_id' => (string) $validated['sku_id'],
                'sku_code' => (string) $sku->sku_code,
                'sku_name' => (string) $sku->sku_name,
                'is_serial_tracked' => (bool) $sku->is_serial_tracked,
                'scanned_qty' => (int) $line->scanned_qty,
                'quantity_source' => (bool) $sku->is_serial_tracked
                    ? 'ACTUAL_CODE_SCAN'
                    : 'ACTUAL_BOX_COUNT',
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
