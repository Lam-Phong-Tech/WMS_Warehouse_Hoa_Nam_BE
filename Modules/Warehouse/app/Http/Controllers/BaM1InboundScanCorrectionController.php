<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;

/** GĐ1 correction for scan-driven inbound: active scans are the quantity source. */
class BaM1InboundScanCorrectionController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public function unscan(Request $request, string $id)
    {
        $payload = $request->validate([
            'inbound_scan_entry_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);
        $actorId = $request->user()?->user_id;
        $requestId = $request->attributes->get('request_id');

        $result = DB::transaction(function () use ($id, $payload, $expectedVersion, $actorId, $requestId): array {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được gỡ mã đã quét.');
            }
            if ((int) $document->version !== $expectedVersion) {
                ApiError::abort(412, 'STALE_VERSION', 'Phiếu đã thay đổi. Hãy tải lại dữ liệu trước khi gỡ mã.', [
                    'expected_version' => $expectedVersion,
                    'actual_version' => (int) $document->version,
                ]);
            }

            $entry = DB::table('inbound_scan_entries')
                ->where('id', $payload['inbound_scan_entry_id'])
                ->lockForUpdate()
                ->first();
            if ($entry === null) {
                ApiError::abort(404, 'INBOUND_SCAN_ENTRY_NOT_FOUND', 'Không tìm thấy mã đã quét.');
            }
            $line = DB::table('stock_document_lines')
                ->where('id', $entry->inbound_line_id)
                ->where('stock_document_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'INBOUND_SCAN_ENTRY_NOT_FOUND', 'Mã đã quét không thuộc phiếu nhập này.');
            }
            if ((string) ($entry->status ?? 'ACTIVE') !== 'ACTIVE') {
                ApiError::abort(409, 'SCAN_ENTRY_NOT_ACTIVE', 'Mã này đã được gỡ trước đó.');
            }

            $previous = (int) $line->scanned_qty;
            $newScanned = max(0, $previous - (int) $entry->quantity);
            DB::table('inbound_scan_entries')->where('id', $entry->id)->update([
                'status' => 'REMOVED',
                'removed_at' => now(),
                'removed_by' => $actorId,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
            DB::table('stock_document_lines')->where('id', $line->id)->update([
                // GĐ1 invariant: no expected quantity exists. These compatibility
                // columns always mirror the sum of ACTIVE actual scans.
                'scanned_qty' => $newScanned,
                'qty_planned' => $newScanned,
                'qty_actual' => $newScanned,
                'updated_at' => now(),
            ]);
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $id)->update([
                'version' => $newVersion,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'INBOUND_UNSCAN_ACTUAL',
                objectType: 'inbound_scan_entry',
                objectId: (string) $entry->id,
                actorId: $actorId !== null ? (string) $actorId : null,
                requestId: $requestId,
                reason: $payload['reason'] ?? null,
                beforeData: ['status' => 'ACTIVE', 'actual_qty' => $previous],
                afterData: ['status' => 'REMOVED', 'actual_qty' => $newScanned],
            );
            // Gỡ mã là stock-neutral (không qua mutateBalance) nhưng đổi scanned_qty
            // của phiếu — cột mà read cache phiếu nhập đang phục vụ.
            DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

            return [
                'document_id' => $id,
                'line_id' => (string) $line->id,
                'inbound_scan_entry_id' => (string) $entry->id,
                'item_id' => $entry->item_id !== null ? (string) $entry->item_id : null,
                'status' => 'REMOVED',
                'required_quantity' => $newScanned,
                'scanned_quantity' => $newScanned,
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'version' => $newVersion,
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã gỡ mã khỏi số lượng thực nhận; tồn kho chưa thay đổi.')
            ->header('ETag', '"'.((int) $result['version']).'"');
    }

    private function expectedVersion(Request $request): int
    {
        $raw = trim((string) $request->header('If-Match'));
        $raw = trim($raw, "\" ");
        if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Thiếu If-Match hợp lệ.');
        }
        return (int) $raw;
    }
}
