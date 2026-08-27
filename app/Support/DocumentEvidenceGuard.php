<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Chứng từ nhập/xuất/hoàn trả" — quy tắc chung quyết định một phiếu có bắt buộc
 * đính kèm chứng từ (document_attachments, status=ACTIVE) trước khi Post hay không,
 * dùng chung giữa gate ở CurrentBaselineStockFlowController::postDocument(),
 * CurrentBaselineReversalService::postReversalDocument() và
 * DocumentAttachmentController (list "cần bổ sung chứng từ").
 *
 * Quy tắc:
 *  - REVERSAL (hoàn trả/điều chỉnh): LUÔN bắt buộc — đây là luồng ghi đè tồn kho thủ
 *    công ngoài scan bình thường nên rủi ro sai lệch/thất thoát cao nhất.
 *  - INBOUND (phiếu nhập): bắt buộc khi không có `source_name` (chứng từ nguồn nhà
 *    cung cấp) — tức "nhập tay" không kèm số chứng từ nào thì phải có ảnh/scan thay
 *    thế. Có source_name thì coi bản thân số chứng từ đó đã là dấu vết đối soát.
 *  - OUTBOUND (phiếu xuất): mọi phiếu xuất đã bắt buộc gắn với một
 *    outbound_request_imports (Excel có source_file_name/source_checksum NOT NULL,
 *    CHECK chk_issue_requires_outbound_request) nên không bị chặn Post thêm ở đây;
 *    endpoint đính kèm vẫn mở để lưu thêm biên bản giao nhận/ảnh nếu cần đối soát.
 */
final class DocumentEvidenceGuard
{
    public const TYPE_INBOUND = 'INBOUND';

    public const TYPE_OUTBOUND = 'OUTBOUND';

    public const TYPE_REVERSAL = 'REVERSAL';

    public static function hasActiveEvidence(string $documentType, string $documentId): bool
    {
        return DB::table('document_attachments')
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    public static function inboundRequiresEvidence(?string $sourceName): bool
    {
        return trim((string) $sourceName) === '';
    }

    /**
     * Đọc chứng từ điều chỉnh theo id, tự dò `reversal_documents` (view/bảng tuỳ
     * driver, xem EnforceReversalWarehouseScope) rồi `reversal_document` (bảng vật
     * lý số ít). Trả về null nếu không tìm thấy ở cả hai.
     */
    public static function reversalRow(string $id): ?object
    {
        if (self::readable('reversal_documents')) {
            $row = DB::table('reversal_documents')->where('id', $id)->first();
            if ($row !== null) {
                return $row;
            }
        }

        if (self::readable('reversal_document')) {
            $row = DB::table('reversal_document')->where('reversal_document_id', $id)->first();
            if ($row !== null) {
                $row->id = $row->reversal_document_id;

                return $row;
            }
        }

        return null;
    }

    private static function readable(string $table): bool
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        return $schema->hasTable($table) || $schema->hasView($table);
    }
}
