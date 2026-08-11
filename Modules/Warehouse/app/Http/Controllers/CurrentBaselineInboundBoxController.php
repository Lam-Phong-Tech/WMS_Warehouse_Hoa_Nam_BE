<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineInboundBoxController extends Controller
{
    use ApiResponse;

    public function store(Request $request, string $id)
    {
        $payload = $request->validate([
            'line_id' => ['required', 'uuid'],
            'box_code' => ['required', 'string', 'max:100'],
        ]);
        $actorId = $this->actorId();
        $requestId = $request->attributes->get('request_id');

        $result = DB::transaction(function () use ($payload, $id, $actorId, $requestId): array {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được đăng ký BOX.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy dòng nhập kho.');
            }

            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'BOX putaway chỉ áp dụng cho SKU COMPONENT.');
            }
            if (strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU COMPONENT phải ACTIVE.');
            }

            $boxCode = strtoupper(trim((string) $payload['box_code']));
            $box = DB::table('containers')->where('container_code', $boxCode)->lockForUpdate()->first();
            if ($box !== null) {
                $this->assertBoxReusable($box, (string) $line->product_id);
                $this->assertBoxNotUsedByOtherActiveReceipt((string) $box->container_id, $id);

                return $this->boxResponse($box, false);
            }

            $boxId = (string) Str::uuid();
            DB::table('containers')->insert([
                'container_id' => $boxId,
                'container_code' => $boxCode,
                'container_type' => 'BOX',
                'physical_code_id' => null,
                'primary_sku_id' => $line->product_id,
                'parent_container_id' => null,
                'zone_code' => null,
                'location_hint' => null,
                'status' => 'EMPTY',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $box = DB::table('containers')->where('container_id', $boxId)->first();

            AuditLogger::record(
                action: 'CREATE_RECEIPT_BOX',
                objectType: 'container',
                objectId: $boxId,
                actorId: $actorId,
                requestId: is_string($requestId) ? $requestId : null,
                afterData: (array) $box,
            );

            return $this->boxResponse($box, true);
        }, 3);

        return $result['created']
            ? $this->respondCreated($result, 'Đã đăng ký BOX cho dòng nhập COMPONENT.')
            : $this->respondSuccess($result, 'BOX đã tồn tại và hợp lệ.');
    }

    private function assertBoxReusable(object $box, string $skuId): void
    {
        if ((string) $box->container_type !== 'BOX') {
            ApiError::abort(422, 'BOX_CODE_CONFLICT', 'Mã đã tồn tại nhưng không phải BOX.');
        }
        if ((string) $box->primary_sku_id !== $skuId) {
            ApiError::abort(422, 'BOX_SKU_MISMATCH', 'Một BOX chỉ được chứa một SKU COMPONENT.');
        }
        if ($box->parent_container_id !== null || (string) $box->status !== 'EMPTY') {
            ApiError::abort(409, 'BOX_NOT_RECEIVABLE', 'BOX đã được lưu kho, đã Post Receipt hoặc không còn ở trạng thái nhận hàng.');
        }
    }

    private function assertBoxNotUsedByOtherActiveReceipt(string $boxId, string $documentId): void
    {
        $conflict = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.container_id', $boxId)
            ->where('d.id', '<>', $documentId)
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            ->exists();
        if ($conflict) {
            ApiError::abort(409, 'BOX_IN_OTHER_ACTIVE_RECEIPT', 'BOX đang được dùng bởi một phiếu nhập khác chưa kết thúc.');
        }
    }

    private function boxResponse(object $box, bool $created): array
    {
        return [
            'container_id' => (string) $box->container_id,
            'container_type' => (string) $box->container_type,
            'box_code' => (string) $box->container_code,
            'sku_id' => (string) $box->primary_sku_id,
            'status' => (string) $box->status,
            'version' => (int) $box->version,
            'created' => $created,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
