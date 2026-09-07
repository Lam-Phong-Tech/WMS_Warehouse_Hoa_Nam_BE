<?php

namespace Modules\Warehouse\Services;

use App\Support\Database\SchemaCache;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * Đăng ký / tái sử dụng BOX cho dòng nhập COMPONENT (stock-neutral).
 *
 * Single source of truth dùng chung cho hai lối vào:
 *  - endpoint POST /inbound-documents/{id}/boxes (CurrentBaselineInboundBoxController)
 *  - luồng scan-driven khi nhân viên quét trực tiếp mã BOX-<SKU>-<seq>
 *    (DiagnosticCurrentBaselineStockService::prepareScanDrivenBoxLine).
 *
 * Caller PHẢI chạy trong DB::transaction và đã khoá document/line liên quan;
 * registrar chỉ lo bản ghi `containers` + guard tái sử dụng.
 */
class InboundBoxRegistrar
{
    /**
     * @return array{box: object, created: bool}
     */
    public function ensure(
        string $documentId,
        string $skuId,
        string $boxCode,
        ?string $actorId,
        ?string $requestId
    ): array {
        $boxCode = strtoupper(trim($boxCode));

        $box = DB::table('containers')->where('container_code', $boxCode)->lockForUpdate()->first();
        if ($box !== null) {
            $this->assertBoxReusable($box, $skuId);
            $this->assertBoxNotUsedByOtherActiveReceipt((string) $box->container_id, $documentId);

            return ['box' => $box, 'created' => false];
        }

        $boxId = (string) Str::uuid();
        DB::table('containers')->insert([
            // Trên Postgres, containers có cột id mirror (không tự set như các bảng
            // khác); trên SQLite bảng này không có cột id.
            ...(SchemaCache::hasColumn('containers', 'id') ? ['id' => $boxId] : []),
            'container_id' => $boxId,
            'container_code' => $boxCode,
            'container_type' => 'BOX',
            'physical_code_id' => null,
            'primary_sku_id' => $skuId,
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
            requestId: $requestId,
            afterData: (array) $box,
        );

        return ['box' => $box, 'created' => true];
    }

    public function assertBoxReusable(object $box, string $skuId): void
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

    public function assertBoxNotUsedByOtherActiveReceipt(string $boxId, string $documentId): void
    {
        $conflict = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.container_id', $boxId)
            ->where('d.id', '<>', $documentId)
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            // BOX chỉ còn bị chiếm khi entry ở phiếu kia vẫn ACTIVE.
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            })
            ->exists();
        if ($conflict) {
            ApiError::abort(409, 'BOX_IN_OTHER_ACTIVE_RECEIPT', 'BOX đang được dùng bởi một phiếu nhập khác chưa kết thúc.');
        }
    }
}
