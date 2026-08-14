<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Flow 06 · Báo cáo vận hành.
 *
 * Source of truth: signed stock_movements ledger. inventory_balances chỉ là
 * projection và mọi báo cáo tồn đều trả reconciliation evidence.
 *
 * Cải tiến v2:
 *  - LIKE tương thích SQLite + PostgreSQL (thay ilike)
 *  - by_agency đặt top-level response (khớp FE contract)
 *  - Actor names join app_user
 *  - Server-side pagination (page/per_page)
 *  - PDF export
 *  - Import/Match bổ sung filter baseline
 *  - Warranty date params dùng from_date/to_date nhất quán (fallback from/to)
 */
class OperationalReportController extends Controller
{
    use ApiResponse;

    private const STOCK_STATUSES = ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'];

    private const MOVEMENT_TYPES = ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'];

    private const EXPORT_ROW_LIMIT = 10000;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    // ── Hub ──────────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/hub', operationId: 'report_hub', summary: 'Danh mục báo cáo vận hành Flow 06', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Danh mục 8 báo cáo MVP')])]
    public function hub()
    {
        return $this->respondSuccess([
            ['key' => 'inventory-balance', 'name' => 'Tồn kho hiện tại'],
            ['key' => 'inventory-xnt', 'name' => 'Nhập-Xuất-Tồn'],
            ['key' => 'document-history', 'name' => 'Lịch sử phiếu nhập/xuất'],
            ['key' => 'movement-trace', 'name' => 'Movement/Trace'],
            ['key' => 'import-match', 'name' => 'Excel Import/Match'],
            ['key' => 'label-log', 'name' => 'Packing Label'],
            ['key' => 'warranty-defects', 'name' => 'Warranty/Component'],
            ['key' => 'outbound-by-agency', 'name' => 'Xuất theo nơi nhận'],
        ]);
    }

    // ── Inventory Balance ────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-balance', operationId: 'report_inventory_balance', summary: 'Báo cáo tồn kho hiện tại kèm đối soát ledger', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Tồn kho hiện tại')])]
    public function inventoryBalance(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildInventoryBalance($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-balance/export-excel', operationId: 'report_inventory_balance_export', summary: 'Xuất Excel/PDF tồn kho hiện tại', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF tồn kho')])]
    public function inventoryBalanceExport(Request $request)
    {
        $report = $this->buildInventoryBalance($request);
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['stock_status'],
            $row['on_hand_qty'], $row['available_qty'], $row['reserved_qty'], $row['ledger_qty'],
            $row['variance'], $row['reconciliation_status'], $row['updated_at'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Tồn kho hiện tại', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Tồn kho', 'Khả dụng', 'Đã giữ', 'Ledger', 'Chênh lệch', 'Đối soát', 'Cập nhật'], $rows, 'bao-cao-ton-kho-hien-tai', 'inventory-balance', $request);
        }

        return $this->exportRows($request, 'Ton kho', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Tồn kho', 'Khả dụng', 'Đã giữ', 'Ledger', 'Chênh lệch', 'Đối soát', 'Cập nhật'], $rows, 'bao-cao-ton-kho-hien-tai.xlsx', 'inventory-balance');
    }

    // ── XNT ──────────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-xnt', operationId: 'report_inventory_xnt_current', summary: 'Báo cáo Nhập-Xuất-Tồn theo authoritative ledger', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'XNT')])]
    public function inventoryXnt(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt/export-excel', operationId: 'report_xnt_export', summary: 'Xuất Excel/PDF nhập xuất tồn', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function inventoryXntExport(Request $request)
    {
        return $this->exportXnt($request, 'bao-cao-xnt');
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced', operationId: 'report_xnt_enhanced', summary: 'Báo cáo nhập xuất tồn chi tiết', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'XNT chi tiết')])]
    public function inventoryXntEnhanced(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced/export-excel', operationId: 'report_xnt_enhanced_export', summary: 'Xuất Excel/PDF nhập xuất tồn chi tiết', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function inventoryXntEnhancedExport(Request $request)
    {
        return $this->exportXnt($request, 'xnt-chitiet');
    }

    // ── Document History ─────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/document-history', operationId: 'report_document_history', summary: 'Lịch sử phiếu nhập/xuất', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound', 'all'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Lịch sử phiếu')])]
    public function documentHistory(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildDocumentHistory($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/document-history/export-excel', operationId: 'report_document_history_export', summary: 'Xuất Excel/PDF lịch sử phiếu', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function documentHistoryExport(Request $request)
    {
        $report = $this->buildDocumentHistory($request);
        $rows = array_map(fn (array $row): array => [
            $row['document_no'], $row['doc_type'], $row['warehouse_code'], $row['counterpart'],
            $row['document_date'], $row['status'], $row['posted_at'], $row['cancelled_at'],
            $row['created_by_name'], $row['posted_by_name'], $row['note'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Lịch sử phiếu', ['Mã phiếu', 'Loại', 'Kho', 'Đối tác/Nơi nhận', 'Ngày phiếu', 'Trạng thái', 'Posted at', 'Cancelled at', 'Người tạo', 'Người post', 'Ghi chú'], $rows, "lich-su-phieu-{$report['period']['from_date']}-{$report['period']['to_date']}", 'document-history', $request);
        }

        return $this->exportRows($request, 'Lich su phieu', ['Mã phiếu', 'Loại', 'Kho', 'Đối tác/Nơi nhận', 'Ngày phiếu', 'Trạng thái', 'Posted at', 'Cancelled at', 'Người tạo', 'Người post', 'Ghi chú'], $rows, "lich-su-phieu-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'document-history');
    }

    // ── Import Match ─────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/import-match', operationId: 'report_import_match', summary: 'Đối soát import toa yêu cầu xuất', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['UPLOADED', 'VALIDATED', 'REJECTED', 'CONSUMED'])), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'error_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['has_errors', 'no_errors'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Đối soát import')])]
    public function importMatch(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildImportMatch($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/import-match/export-excel', operationId: 'report_import_match_export', summary: 'Xuất Excel/PDF đối soát import', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function importMatchExport(Request $request)
    {
        $report = $this->buildImportMatch($request);
        $rows = array_map(fn (array $row): array => [
            $row['import_no'], $row['source_file_name'], $row['status'], $row['total_rows'], $row['valid_rows'],
            $row['error_rows'], $row['pass_rate'], $row['matched_qty'], $row['uploaded_at'],
            $row['uploaded_by_name'], $row['validated_at'], $row['consumed_at'], $row['consumed_by_name'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Đối soát toa', ['Import', 'File nguồn', 'Trạng thái', 'Tổng dòng', 'Hợp lệ', 'Lỗi', 'Pass rate (%)', 'Matched qty', 'Uploaded at', 'Người upload', 'Validated at', 'Consumed at', 'Người consume'], $rows, "doi-soat-toa-{$report['period']['from_date']}-{$report['period']['to_date']}", 'import-match', $request);
        }

        return $this->exportRows($request, 'Import Match', ['Import', 'File nguồn', 'Trạng thái', 'Tổng dòng', 'Hợp lệ', 'Lỗi', 'Pass rate (%)', 'Matched qty', 'Uploaded at', 'Người upload', 'Validated at', 'Consumed at', 'Người consume'], $rows, "doi-soat-toa-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'import-match');
    }

    // ── Label Log ────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/label-log', operationId: 'report_label_log', summary: 'Nhật ký nhãn Packing Label', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Nhật ký nhãn')])]
    public function labelLog(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildLabelLog($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/label-log/export-excel', operationId: 'report_label_log_export', summary: 'Xuất Excel/PDF nhật ký nhãn', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function labelLogExport(Request $request)
    {
        $report = $this->buildLabelLog($request);
        $rows = array_map(fn (array $row): array => [
            $row['outbound_no'], $row['outbound_date'], $row['recipient'], $row['label_count'], $row['item_count'],
            $row['sku_count'], $row['sku_summary'], $row['status'], $row['total_attempts'], $row['failed_count'], $row['reprint_count'], $row['created_at'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Nhật ký nhãn', ['Phiếu xuất', 'Ngày phiếu', 'Nơi nhận', 'Số nhãn', 'Số item', 'Số SKU', 'Tóm tắt SKU', 'Trạng thái', 'Tổng lần in', 'Lỗi in', 'In lại', 'Tạo lúc'], $rows, "nhat-ky-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}", 'label-log', $request);
        }

        return $this->exportRows($request, 'Packing Label', ['Phiếu xuất', 'Ngày phiếu', 'Nơi nhận', 'Số nhãn', 'Số item', 'Số SKU', 'Tóm tắt SKU', 'Trạng thái', 'Tổng lần in', 'Lỗi in', 'In lại', 'Tạo lúc'], $rows, "packing-label-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'label-log');
    }

    // ── Outbound by Agency ───────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency', operationId: 'report_outbound_by_agency', summary: 'Báo cáo xuất theo nơi nhận', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Xuất theo nơi nhận')])]
    public function outboundByAgency(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildOutboundByAgency($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency/export-excel', operationId: 'report_outbound_by_agency_export', summary: 'Xuất Excel/PDF theo nơi nhận', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function outboundByAgencyExport(Request $request)
    {
        $report = $this->buildOutboundByAgency($request);
        $rows = array_map(fn (array $row): array => [
            $row['agency_code'], $row['agency_name'], $row['product_code'], $row['product_name'], $row['outbound_no'],
            $row['document_date'], $row['posted_at'], $row['required_qty'], $row['scanned_qty'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Xuất theo nơi nhận', ['Mã nơi nhận', 'Nơi nhận', 'Mã SKU', 'Tên SKU', 'Phiếu xuất', 'Ngày phiếu', 'Posted at', 'Yêu cầu', 'Đã xuất'], $rows, "xuat-theo-noi-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}", 'outbound-by-agency', $request);
        }

        return $this->exportRows($request, 'Xuat noi nhan', ['Mã nơi nhận', 'Nơi nhận', 'Mã SKU', 'Tên SKU', 'Phiếu xuất', 'Ngày phiếu', 'Posted at', 'Yêu cầu', 'Đã xuất'], $rows, "xuat-theo-noi-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'outbound-by-agency');
    }

    // ── Movement Trace ───────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/movement-trace', operationId: 'report_movement_trace', summary: 'Truy vết giao dịch kho', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Movement trace')])]
    public function movementTrace(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildMovementTrace($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/movement-trace/export-excel', operationId: 'report_movement_trace_export', summary: 'Xuất Excel/PDF movement trace', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function movementTraceExport(Request $request)
    {
        $report = $this->buildMovementTrace($request);
        $rows = array_map(fn (array $row): array => [
            $row['occurred_at'], $row['movement_type'], $row['warehouse_code'], $row['product_code'], $row['product_name'],
            $row['stock_status'], $row['qty_delta'], $row['item_id'], $row['container_id'], $row['physical_code'],
            $row['source_type'], $row['source_id'], $row['original_movement_id'], $row['correction_chain_id'],
        ], $report['rows']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Movement Trace', ['Thời gian', 'Movement', 'Kho', 'Mã SKU', 'Tên SKU', 'Stock status', 'Qty delta', 'Item', 'Container', 'Physical code', 'Source type', 'Source id', 'Original movement', 'Correction chain'], $rows, "movement-trace-{$report['period']['from_date']}-{$report['period']['to_date']}", 'movement-trace', $request);
        }

        return $this->exportRows($request, 'Movement Trace', ['Thời gian', 'Movement', 'Kho', 'Mã SKU', 'Tên SKU', 'Stock status', 'Qty delta', 'Item', 'Container', 'Physical code', 'Source type', 'Source id', 'Original movement', 'Correction chain'], $rows, "movement-trace-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'movement-trace');
    }

    // ── Reconciliation ───────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-reconciliation', operationId: 'report_inventory_reconciliation', summary: 'Đối soát tồn kho với sổ giao dịch', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'only_mismatch', in: 'query', schema: new OA\Schema(type: 'boolean'))], responses: [new OA\Response(response: 200, description: 'Kết quả đối soát')])]
    public function inventoryReconciliation(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'only_mismatch' => ['nullable', 'boolean'],
        ]);
        $report = $this->buildInventoryBalance($request);
        $rows = collect($report['rows']);
        if ($request->boolean('only_mismatch')) {
            $rows = $rows->where('reconciliation_status', 'MISMATCH')->values();
        }

        return $this->respondSuccess([
            'formula' => 'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
            'dimension' => ['warehouse_id', 'product_id', 'stock_status'],
            'summary' => [
                'rows_checked' => $rows->count(),
                'mismatch_count' => $rows->where('reconciliation_status', 'MISMATCH')->count(),
                'status' => $rows->where('reconciliation_status', 'MISMATCH')->isEmpty() ? 'PASS' : 'FAIL',
            ],
            'rows' => $rows->all(),
            'mismatches' => $rows->where('reconciliation_status', 'MISMATCH')->values()->all(),
        ]);
    }

    // ── Dashboard Quick ──────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/dashboard-quick', operationId: 'report_dashboard_quick', summary: 'Số liệu nhanh cho dashboard', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Dashboard KPIs')])]
    public function dashboardQuick(Request $request)
    {
        [$from, $to] = $this->period($request);
        $movement = DB::table('stock_movements')
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->get(['movement_type', 'qty_delta']);

        $sum = fn (string $type): float => (float) $movement->where('movement_type', $type)->sum('qty_delta');
        $reconciliation = $this->buildInventoryBalance(new Request(['only_mismatch' => true]));
        $warranty = DB::table('warranty_case')
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $this->respondSuccess([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'available_qty' => (float) DB::table('inventory_balances')->where('stock_status', 'AVAILABLE')->sum('available_qty'),
            'receipt_qty' => max(0, $sum('RECEIPT')),
            'issue_qty' => abs(min(0, $sum('ISSUE'))),
            'component_issue_qty' => abs(min(0, $sum('COMPONENT_ISSUE'))),
            'reversal_net' => $sum('REVERSAL'),
            'warranty_cases_by_status' => $warranty,
            'reconciliation_warnings' => collect($reconciliation['rows'])->where('reconciliation_status', 'MISMATCH')->values()->all(),
        ]);
    }

    // ── Warranty Defects ─────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/warranty-defects', operationId: 'report_warranty_defects', summary: 'Báo cáo bảo hành & linh kiện', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Báo cáo bảo hành')])]
    public function warrantyDefects(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildWarrantyReport($request, includePii: false), $request));
    }

    #[OA\Get(path: '/api/v1/reports/warranty-defects/export-excel', operationId: 'report_warranty_defects_export', summary: 'Xuất Excel/PDF báo cáo bảo hành', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']))], responses: [new OA\Response(response: 200, description: 'File Excel/PDF')])]
    public function warrantyDefectsExport(Request $request)
    {
        $includePii = (bool) $request->user()?->hasPermission('warranty.pii.export');
        $report = $this->buildWarrantyReport($request, includePii: $includePii);

        AuditLogger::record(
            action: 'WARRANTY_REPORT_EXPORTED',
            objectType: 'warranty_report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'pii_included' => $includePii,
                'row_count' => count($report['rows']),
                'period' => $report['period'],
            ],
        );

        $header = ['Case', 'Nhận diện', 'SKU/Model', 'Bệnh / lỗi', 'Linh kiện đã xuất', 'Trạng thái', 'Ngày nhận', 'Ngày cập nhật'];
        if ($includePii) {
            $header = array_merge($header, ['Khách hàng', 'SĐT', 'Địa chỉ']);
        }
        $rows = array_map(function (array $row) use ($includePii): array {
            $line = [
                $this->excelSafe($row['case_no']), $row['identification_status'], $this->excelSafe($row['model']),
                $this->excelSafe($row['defect']), $this->excelSafe($row['components_issued']),
                $row['status'], $row['received_at'], $row['updated_at'],
            ];
            if ($includePii) {
                $line = array_merge($line, [
                    $this->excelSafe($row['customer_name'] ?? ''),
                    $this->excelSafe($row['customer_phone'] ?? ''),
                    $this->excelSafe($row['customer_address'] ?? ''),
                ]);
            }

            return $line;
        }, $report['rows']);

        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]);
        }

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Bảo hành', $header, $rows, "bao-cao-bao-hanh-{$report['period']['from_date']}-den-{$report['period']['to_date']}", 'warranty-defects', $request);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bao hanh');
        $sheet->fromArray($header, null, 'A1');
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, "A{$rowIndex}");
            $rowIndex++;
        }
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $writer = new Xlsx($spreadsheet);
        $fileName = "bao-cao-bao-hanh-{$report['period']['from_date']}-den-{$report['period']['to_date']}.xlsx";

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ══════════════════════════════════════════════════════════
    //  PRIVATE BUILDERS
    // ══════════════════════════════════════════════════════════

    private function buildInventoryBalance(Request $request): array
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);

        $balanceQuery = DB::table('inventory_balances as b')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->select('b.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name');
        $movementQuery = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name')
            ->selectRaw('SUM(m.qty_delta) AS ledger_qty')
            ->selectRaw('MAX(m.occurred_at) AS movement_updated_at')
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code', 'p.code', 'p.name');

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $filter) {
            if ($request->filled($filter)) {
                $balanceQuery->where("b.{$filter}", $request->input($filter));
                $movementQuery->where("m.{$filter}", $request->input($filter));
            }
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            $balanceQuery->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$keyword]);
            });
            $movementQuery->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$keyword]);
            });
        }

        $balances = $balanceQuery->get()->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $movements = $movementQuery->get()->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $keys = $balances->keys()->merge($movements->keys())->unique()->values();

        $rows = $keys->map(function (string $key) use ($balances, $movements): array {
            $balance = $balances->get($key);
            $movement = $movements->get($key);
            $balanceExists = $balance !== null;
            $onHand = (float) ($balance->on_hand_qty ?? $balance->quantity ?? 0);
            $ledger = (float) ($movement->ledger_qty ?? 0);
            $variance = bcsub((string) $onHand, (string) $ledger, 3);
            $isMismatch = bccomp($variance, '0', 3) !== 0;
            $warehouseId = (string) ($balance->warehouse_id ?? $movement->warehouse_id ?? '');
            $productId = (string) ($balance->product_id ?? $movement->product_id ?? '');
            $status = (string) ($balance->stock_status ?? $movement->stock_status ?? 'AVAILABLE');

            $mismatchReason = null;
            if ($isMismatch) {
                $mismatchReason = match (true) {
                    ! $balanceExists => 'BALANCE_MISSING_FOR_LEDGER',
                    $movement === null => 'LEDGER_MISSING_FOR_BALANCE',
                    default => 'QUANTITY_MISMATCH',
                };
            }

            return [
                'id' => $key,
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) ($balance->warehouse_code ?? $movement->warehouse_code ?? ''),
                'product_id' => $productId,
                'product_code' => (string) ($balance->product_code ?? $movement->product_code ?? ''),
                'product_name' => (string) ($balance->product_name ?? $movement->product_name ?? ''),
                'stock_status' => $status,
                'on_hand_qty' => $onHand,
                'available_qty' => (float) ($balance->available_qty ?? ($status === 'AVAILABLE' ? $onHand : 0)),
                'reserved_qty' => (float) ($balance->reserved_qty ?? 0),
                'ledger_qty' => $ledger,
                'expected_qty' => $ledger,
                'current_on_hand_qty' => $onHand,
                'variance' => (float) $variance,
                'delta_qty' => (float) $variance,
                'balance_exists' => $balanceExists,
                'mismatch_reason' => $mismatchReason,
                'reconciliation_status' => $isMismatch ? 'MISMATCH' : 'MATCH',
                'updated_at' => (string) ($balance->updated_at ?? $movement->movement_updated_at ?? ''),
            ];
        })->sortBy(['warehouse_code', 'product_code', 'stock_status'])->values();

        return [
            'data_as_of' => now()->toIso8601String(),
            'summary' => [
                'total_rows' => $rows->count(),
                'total_on_hand' => $rows->sum('on_hand_qty'),
                'total_available' => $rows->sum('available_qty'),
                'mismatch_count' => $rows->where('reconciliation_status', 'MISMATCH')->count(),
            ],
            'rows' => $rows->all(),
        ];
    }

    private function buildXnt(Request $request): array
    {
        [$from, $to] = $this->period($request, true);
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";

        $query = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.occurred_at', '<=', $toTs)
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name')
            ->selectRaw("SUM(CASE WHEN m.occurred_at < ? OR (m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'OPENING') THEN m.qty_delta ELSE 0 END) AS opening", [$fromTs, $fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'RECEIPT' THEN m.qty_delta ELSE 0 END) AS receipt", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'COMPONENT_ISSUE' THEN -m.qty_delta ELSE 0 END) AS component_issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'REVERSAL' THEN m.qty_delta ELSE 0 END) AS reversal_net", [$fromTs, $toTs])
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code', 'p.code', 'p.name');
        if ($request->filled('warehouse_id')) {
            $query->where('m.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('m.product_id', $request->input('product_id'));
        }
        if ($request->filled('keyword')) {
            $kw = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$kw]);
            });
        }

        $lines = $query->get()->map(function ($row): array {
            $opening = (float) $row->opening;
            $receipt = (float) $row->receipt;
            $issue = (float) $row->issue;
            $component = (float) $row->component_issue;
            $reversal = (float) $row->reversal_net;

            return [
                'warehouse_code' => (string) $row->warehouse_code,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'stock_status' => (string) $row->stock_status,
                'opening' => $opening,
                'receipt' => $receipt,
                'issue' => $issue,
                'component_issue' => $component,
                'reversal_net' => $reversal,
                'closing' => $opening + $receipt - $issue - $component + $reversal,
            ];
        })->sortBy(['warehouse_code', 'product_code', 'stock_status'])->values()->all();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'formula' => 'Closing = Opening + Receipt - Issue - Component Issue + Reversal',
            'lines' => $lines,
        ];
    }

    private function exportXnt(Request $request, string $prefix)
    {
        $report = $this->buildXnt($request);
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['stock_status'], $row['opening'],
            $row['receipt'], $row['issue'], $row['component_issue'], $row['reversal_net'], $row['closing'],
        ], $report['lines']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Nhập-Xuất-Tồn', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ', 'Nhập', 'Xuất', 'Xuất linh kiện', 'Hoàn đảo', 'Cuối kỳ'], $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}", 'inventory-xnt', $request);
        }

        return $this->exportRows($request, 'XNT', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ', 'Nhập', 'Xuất', 'Xuất linh kiện', 'Hoàn đảo', 'Cuối kỳ'], $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}.xlsx", 'inventory-xnt');
    }

    private function buildDocumentHistory(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'doc_type' => ['nullable', 'in:inbound,outbound,all'],
            'status' => ['nullable', 'in:DRAFT,SCANNING,POSTED,CANCELLED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'product_id' => ['nullable', 'uuid'],
        ]);
        $docType = $validated['doc_type'] ?? 'all';
        $query = DB::table('stock_documents as sd')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'sd.src_warehouse_id')
            ->leftJoin('warehouses as dw', 'dw.id', '=', 'sd.dst_warehouse_id')
            ->leftJoin('agencies as a', 'a.agency_id', '=', 'sd.agency_id')
            ->leftJoin('app_user as cu', 'cu.user_id', '=', 'sd.created_by')
            ->leftJoin('app_user as pu', 'pu.user_id', '=', 'sd.posted_by')
            ->whereIn('sd.doc_type', ['receipt', 'issue'])
            ->whereBetween('sd.doc_date', [$from, $to])
            ->select('sd.*', 'sw.code as src_warehouse_code', 'dw.code as dst_warehouse_code', 'a.agency_name',
                'cu.display_name as creator_name', 'pu.display_name as poster_name');
        if ($docType !== 'all') {
            $query->where('sd.doc_type', $docType === 'inbound' ? 'receipt' : 'issue');
        }
        if (! empty($validated['status'])) {
            $query->where('sd.status', $validated['status']);
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(sd.doc_no) LIKE ?', [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(sd.source_name, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(sd.recipient_name, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_name, '')) LIKE ?", [$keyword]);
            });
        }
        if (! empty($validated['product_id'])) {
            $query->whereExists(function ($q) use ($validated): void {
                $q->selectRaw('1')->from('stock_document_lines as sdl')
                    ->whereColumn('sdl.stock_document_id', 'sd.id')
                    ->where('sdl.product_id', $validated['product_id']);
            });
        }
        $documents = $query->orderByDesc('sd.doc_date')->orderByDesc('sd.created_at')->get();
        $rows = $documents->map(fn ($d): array => [
            'id' => (string) $d->id,
            'document_no' => (string) $d->doc_no,
            'doc_type' => $d->doc_type === 'receipt' ? 'INBOUND' : 'OUTBOUND',
            'warehouse_code' => (string) ($d->doc_type === 'receipt' ? $d->dst_warehouse_code : $d->src_warehouse_code),
            'counterpart' => (string) ($d->doc_type === 'receipt' ? ($d->source_name ?? '') : ($d->agency_name ?: ($d->recipient_name ?? ''))),
            'document_date' => (string) $d->doc_date,
            'status' => strtoupper((string) $d->status),
            'posted_at' => $d->posted_at ?? null,
            'cancelled_at' => $d->cancelled_at ?? null,
            'created_by_name' => $d->creator_name ?? null,
            'posted_by_name' => $d->poster_name ?? null,
            'note' => $d->note ?? null,
        ])->all();
        $statuses = collect($rows)->countBy('status');

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'doc_type_filter' => $docType,
            'summary' => [
                'total' => count($rows), 'draft' => (int) ($statuses['DRAFT'] ?? 0), 'scanning' => (int) ($statuses['SCANNING'] ?? 0),
                'posted' => (int) ($statuses['POSTED'] ?? 0), 'cancelled' => (int) ($statuses['CANCELLED'] ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    private function buildImportMatch(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'status' => ['nullable', 'in:UPLOADED,VALIDATED,REJECTED,CONSUMED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'batch_id' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
            'sku_code' => ['nullable', 'string', 'max:100'],
            'error_status' => ['nullable', 'in:has_errors,no_errors'],
        ]);
        $query = DB::table('outbound_request_imports as ori')
            ->leftJoin('app_user as uu', 'uu.user_id', '=', 'ori.created_by')
            ->leftJoin('app_user as cu', 'cu.user_id', '=', 'ori.consumed_by')
            ->whereBetween('ori.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('ori.*', 'uu.display_name as uploader_name', 'cu.display_name as consumer_name');
        if (! empty($validated['status'])) {
            $query->where('ori.status', $validated['status']);
        }
        if (! empty($validated['keyword'])) {
            $kw = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw("LOWER(COALESCE(ori.import_no, '')) LIKE ?", [$kw])
                    ->orWhereRaw("LOWER(COALESCE(ori.source_file_name, '')) LIKE ?", [$kw]);
            });
        }
        if (! empty($validated['error_status'])) {
            if ($validated['error_status'] === 'has_errors') {
                $query->where('ori.error_rows', '>', 0);
            } else {
                $query->where(function ($q): void {
                    $q->where('ori.error_rows', '=', 0)->orWhereNull('ori.error_rows');
                });
            }
        }
        $imports = $query->orderByDesc('ori.created_at')->get();
        $importIds = $imports->pluck('id')->all();
        $matched = collect();
        if ($importIds !== []) {
            $matched = DB::table('outbound_requests as r')
                ->join('stock_documents as sd', 'sd.outbound_request_id', '=', 'r.id')
                ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'sd.id')
                ->whereIn('r.import_id', $importIds)
                ->where('sd.status', 'POSTED')
                ->groupBy('r.import_id')
                ->select('r.import_id')->selectRaw('SUM(l.scanned_qty) AS matched_qty')->pluck('matched_qty', 'r.import_id');
        }
        $rows = $imports->map(function ($i) use ($matched): array {
            $total = (int) ($i->total_rows ?? 0);
            $valid = (int) ($i->valid_rows ?? 0);

            return [
                'id' => (string) $i->id,
                'import_no' => (string) ($i->import_no ?? $i->id),
                'source_file_name' => (string) ($i->source_file_name ?? ''),
                'status' => (string) $i->status,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'error_rows' => (int) ($i->error_rows ?? 0),
                'pass_rate' => $total > 0 ? round($valid * 100 / $total, 2) : 0,
                'matched_qty' => (float) ($matched[(string) $i->id] ?? 0),
                'uploaded_at' => (string) $i->created_at,
                'uploaded_by_name' => $i->uploader_name ?? null,
                'validated_at' => $i->validated_at ?? null,
                'consumed_at' => $i->consumed_at ?? ((string) $i->status === 'CONSUMED' ? ($i->updated_at ?? null) : null),
                'consumed_by_name' => $i->consumer_name ?? null,
            ];
        })->all();
        $collection = collect($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_batches' => count($rows),
                'consumed' => $collection->where('status', 'CONSUMED')->count(),
                'rejected' => $collection->where('status', 'REJECTED')->count(),
                'avg_pass_rate' => count($rows) > 0 ? round((float) $collection->avg('pass_rate'), 2) : 0,
                'total_matched_qty' => (float) $collection->sum('matched_qty'),
            ],
            'rows' => $rows,
        ];
    }

    private function buildLabelLog(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'status' => ['nullable', 'in:PENDING_PRINT,PRINTED,PRINT_FAILED'],
            'printer_id' => ['nullable', 'uuid'],
        ]);
        $attempts = DB::table('label_print_events')
            ->select('label_snapshot_id')
            ->selectRaw('COUNT(*) AS total_attempts')
            ->selectRaw("SUM(CASE WHEN status = 'PRINT_FAILED' THEN 1 ELSE 0 END) AS failed_count")
            ->groupBy('label_snapshot_id');
        $query = DB::table('label_snapshots as ls')
            ->leftJoin('products as p', 'p.id', '=', 'ls.sku_id')
            ->leftJoin('outbound_item_matches as oim', 'oim.id', '=', 'ls.outbound_item_match_id')
            ->leftJoin('stock_document_lines as l', 'l.id', '=', 'oim.outbound_line_id')
            ->leftJoin('stock_documents as sd', 'sd.id', '=', 'l.stock_document_id')
            ->leftJoin('agencies as ag', 'ag.agency_id', '=', 'sd.agency_id')
            ->leftJoinSub($attempts, 'pa', 'pa.label_snapshot_id', '=', 'ls.id')
            ->whereBetween('ls.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('ls.id', 'ls.item_id', 'ls.sku_id', 'ls.status', 'ls.created_at',
                'p.code as sku_code', 'sd.id as stock_document_id', 'sd.doc_no as outbound_no',
                'sd.doc_date as outbound_date',
                DB::raw("COALESCE(ag.agency_name, sd.recipient_name) as recipient"),
                'pa.total_attempts', 'pa.failed_count');
        if (! empty($validated['status'])) {
            $query->where('ls.status', $validated['status']);
        }
        if (! empty($validated['printer_id'])) {
            $printerId = $validated['printer_id'];
            $query->whereExists(function ($q) use ($printerId): void {
                $q->selectRaw('1')->from('label_print_events as lpe')
                    ->whereColumn('lpe.label_snapshot_id', 'ls.id')
                    ->where('lpe.printer_config_id', $printerId);
            });
        }
        $snapshots = $query->orderByDesc('ls.created_at')->get();

        $grouped = $snapshots->groupBy(fn ($s) => $s->stock_document_id !== null ? (string) $s->stock_document_id : 'UNL_'.$s->id);
        $rows = $grouped->map(function (Collection $group): array {
            $first = $group->first();
            $linked = $first->stock_document_id !== null;
            $attemptTotal = $group->sum(fn ($s) => (int) ($s->total_attempts ?? 0));
            $failedTotal = $group->sum(fn ($s) => (int) ($s->failed_count ?? 0));
            $reprintTotal = $group->sum(fn ($s) => max(0, (int) ($s->total_attempts ?? 0) - 1));
            $skuCodes = $group->pluck('sku_code')->filter()->unique()->values();
            $statuses = $group->pluck('status')->unique();
            $aggStatus = $statuses->contains('PRINT_FAILED') ? 'PRINT_FAILED'
                : ($statuses->contains('PENDING_PRINT') ? 'PENDING_PRINT' : 'PRINTED');

            return [
                'id' => $linked ? (string) $first->stock_document_id : (string) $first->id,
                'outbound_no' => $first->outbound_no,
                'outbound_date' => $first->outbound_date,
                'recipient' => $first->recipient,
                'label_count' => $group->count(),
                'item_count' => $group->pluck('item_id')->filter()->unique()->count(),
                'sku_count' => $skuCodes->count(),
                'sku_summary' => $skuCodes->implode(', '),
                'status' => $aggStatus,
                'total_attempts' => $attemptTotal,
                'failed_count' => $failedTotal,
                'reprint_count' => $reprintTotal,
                'created_at' => (string) $group->min('created_at'),
            ];
        })->values()->all();
        $collection = collect($rows);
        $total = count($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_labels' => $total,
                'printed' => $collection->where('status', 'PRINTED')->count(),
                'pending' => $collection->where('status', 'PENDING_PRINT')->count(),
                'failed' => $collection->where('status', 'PRINT_FAILED')->count(),
                'reprinted' => $collection->where('reprint_count', '>', 0)->count(),
                'failure_rate' => $total > 0 ? round($collection->where('status', 'PRINT_FAILED')->count() * 100 / $total, 2) : 0,
                'reprint_rate' => $total > 0 ? round($collection->where('reprint_count', '>', 0)->count() * 100 / $total, 2) : 0,
            ],
            'rows' => $rows,
        ];
    }

    private function buildOutboundByAgency(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'agency_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $query = DB::table('stock_documents as sd')
            ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'sd.id')
            ->leftJoin('agencies as a', 'a.agency_id', '=', 'sd.agency_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('sd.doc_type', 'issue')->where('sd.status', 'POSTED')
            ->whereBetween('sd.doc_date', [$from, $to])
            ->select('sd.id', 'sd.doc_no', 'sd.doc_date', 'sd.posted_at', 'sd.agency_id', 'sd.recipient_name', 'a.agency_code', 'a.agency_name', 'p.code as product_code', 'p.name as product_name', 'l.product_id', 'l.qty_planned', 'l.scanned_qty');
        if (! empty($validated['agency_id'])) {
            $query->where('sd.agency_id', $validated['agency_id']);
        }
        if (! empty($validated['product_id'])) {
            $query->where('l.product_id', $validated['product_id']);
        }
        if (! empty($validated['keyword'])) {
            $kw = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$kw])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_name, '')) LIKE ?", [$kw])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_code, '')) LIKE ?", [$kw]);
            });
        }
        $rows = $query->orderByDesc('sd.doc_date')->get()->map(fn ($r): array => [
            'agency_code' => (string) ($r->agency_code ?? 'DIRECT'),
            'agency_name' => (string) ($r->agency_name ?? $r->recipient_name ?? 'Người nhận trực tiếp'),
            'product_code' => (string) ($r->product_code ?? ''),
            'product_name' => (string) ($r->product_name ?? ''),
            'outbound_no' => (string) $r->doc_no,
            'document_date' => (string) $r->doc_date,
            'posted_at' => (string) $r->posted_at,
            'required_qty' => (float) $r->qty_planned,
            'scanned_qty' => (float) $r->scanned_qty,
            '_document_id' => (string) $r->id,
        ])->all();
        $collection = collect($rows);
        $byAgency = $collection->groupBy(fn ($r) => $r['agency_code'].'|'.$r['agency_name'])->map(function (Collection $items): array {
            $first = $items->first();

            return [
                'agency_code' => $first['agency_code'],
                'agency_name' => $first['agency_name'],
                'document_count' => $items->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $items->sum('scanned_qty'),
                'sku_count' => $items->pluck('product_code')->unique()->count(),
            ];
        })->values()->all();
        $publicRows = $collection->map(function (array $row): array {
            unset($row['_document_id']);

            return $row;
        })->all();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_agencies' => count($byAgency),
                'total_documents' => $collection->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $collection->sum('scanned_qty'),
            ],
            'by_agency' => $byAgency,
            'rows' => $publicRows,
        ];
    }

    private function buildMovementTrace(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'], 'item_id' => ['nullable', 'uuid'],
            'movement_type' => ['nullable', 'in:'.implode(',', self::MOVEMENT_TYPES)], 'code_value' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:80'], 'source_id' => ['nullable', 'uuid'],
        ]);
        $query = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->whereBetween('m.occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('m.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name');
        foreach (['warehouse_id', 'product_id', 'item_id', 'movement_type', 'source_type', 'source_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("m.{$filter}", $validated[$filter]);
            }
        }
        if (! empty($validated['code_value'])) {
            $objectIds = DB::table('physical_codes')->where('code_value', $validated['code_value'])->pluck('assigned_object_id')->filter()->all();
            if ($objectIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($objectIds): void {
                    $q->whereIn('m.item_id', $objectIds)->orWhereIn('m.container_id', $objectIds);
                });
            }
        }
        $movements = $query->orderByDesc('m.occurred_at')->orderByDesc('m.id')->get();
        $objectIds = $movements->pluck('item_id')->merge($movements->pluck('container_id'))->filter()->unique()->values()->all();
        $codeMap = $objectIds === [] ? collect() : DB::table('physical_codes')->whereIn('assigned_object_id', $objectIds)->where('status', 'ACTIVE')->orderBy('created_at')->get(['assigned_object_id', 'code_value'])->groupBy('assigned_object_id')->map(fn ($rows) => (string) $rows->first()->code_value);
        $rows = $movements->map(fn ($m): array => [
            'id' => (string) $m->id,
            'occurred_at' => (string) $m->occurred_at,
            'movement_type' => (string) $m->movement_type,
            'warehouse_id' => (string) $m->warehouse_id,
            'warehouse_code' => (string) ($m->warehouse_code ?? ''),
            'product_id' => (string) $m->product_id,
            'product_code' => (string) ($m->product_code ?? ''),
            'product_name' => (string) ($m->product_name ?? ''),
            'stock_status' => (string) $m->stock_status,
            'qty_delta' => (float) $m->qty_delta,
            'item_id' => $m->item_id ?? null,
            'container_id' => $m->container_id ?? null,
            'physical_code' => (string) ($codeMap[(string) ($m->item_id ?? $m->container_id ?? '')] ?? ''),
            'source_type' => (string) ($m->source_type ?? ''),
            'source_id' => $m->source_id ?? null,
            'original_movement_id' => $m->original_movement_id ?? null,
            'correction_chain_id' => $m->correction_chain_id ?? null,
        ])->all();
        $collection = collect($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'formula' => 'net_effect = SUM(qty_delta); reversal/correction append-only, không sửa movement gốc',
            'summary' => [
                'total_movements' => count($rows),
                'net_effect' => (float) $collection->sum('qty_delta'),
                'reversal_count' => $collection->where('movement_type', 'REVERSAL')->count(),
            ],
            'rows' => $rows,
        ];
    }

    private function buildWarrantyReport(Request $request, bool $includePii): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'defect_id' => ['nullable', 'uuid'],
            'identification_status' => ['nullable', 'in:IDENTIFIED,TEMP_ONLY'],
        ]);

        $query = DB::table('warranty_case as wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->leftJoin('defect_catalogs as d', 'd.defect_id', '=', 'wc.defect_id')
            ->whereBetween('wc.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        foreach (['status', 'defect_id', 'identification_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("wc.{$filter}", $validated[$filter]);
            }
        }
        $cases = $query->orderByDesc('wc.created_at')->get([
            'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status',
            'wc.status', 'wc.created_at', 'wc.updated_at', 'wc.temporary_item_code',
            'wc.customer_name', 'wc.customer_phone', 'wc.customer_address',
            's.sku_code', 'd.defect_code', 'd.defect_name',
        ]);

        $issuedByCase = DB::table('component_issue_documents as cid')
            ->join('component_issue_lines as cil', 'cil.component_issue_document_id', '=', 'cid.id')
            ->join('sku as cs', 'cs.sku_id', '=', 'cil.sku_id')
            ->where('cid.status', 'POSTED')
            ->whereIn('cid.warranty_case_id', $cases->pluck('warranty_case_id')->all())
            ->groupBy('cid.warranty_case_id', 'cs.sku_code')
            ->selectRaw('cid.warranty_case_id, cs.sku_code, SUM(cil.scanned_qty) AS issued_qty')
            ->get()
            ->groupBy('warranty_case_id');

        $activeStatuses = ['RECEIVED', 'INSPECTING', 'WAITING_CHECK', 'REPAIRED'];
        $rows = $cases->map(function (object $case) use ($issuedByCase, $includePii): array {
            $issued = collect($issuedByCase->get((string) $case->warranty_case_id) ?? [])
                ->map(fn (object $item): string => "{$item->sku_code} × ".(int) $item->issued_qty)
                ->implode('; ');
            $model = $case->identification_status === 'TEMP_ONLY' && $case->sku_code === null
                ? 'UNKNOWN_MODEL'
                : ($case->sku_code ?? 'UNKNOWN_MODEL');
            $row = [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'case_no' => (string) $case->warranty_case_code,
                'identification_status' => (string) $case->identification_status,
                'temporary_item_code' => $case->temporary_item_code,
                'model' => $model,
                'defect' => $case->defect_code !== null
                    ? trim("{$case->defect_code} · {$case->defect_name}")
                    : 'Chưa phân loại',
                'components_issued' => $issued !== '' ? $issued : 'Chưa xuất',
                'status' => (string) $case->status,
                'received_at' => (string) $case->created_at,
                'updated_at' => (string) ($case->updated_at ?? $case->created_at),
            ];
            if ($includePii) {
                $row['customer_name'] = $case->customer_name;
                $row['customer_phone'] = $case->customer_phone;
                $row['customer_address'] = $case->customer_address;
            }

            return $row;
        })->values();

        $byDefect = $cases
            ->groupBy(fn (object $case): string => $case->defect_code !== null
                ? trim("{$case->defect_code} · {$case->defect_name}")
                : 'Chưa phân loại')
            ->map(function ($group, string $defect) use ($activeStatuses): array {
                return [
                    'defect' => $defect,
                    'total' => $group->count(),
                    'processing' => $group->whereIn('status', $activeStatuses)->count(),
                    'returned' => $group->where('status', 'RETURNED')->count(),
                    'cancelled' => $group->where('status', 'CANCELLED')->count(),
                    'temp_only' => $group->where('identification_status', 'TEMP_ONLY')->count(),
                ];
            })->values();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total' => $cases->count(),
                'processing' => $cases->whereIn('status', $activeStatuses)->count(),
                'returned' => $cases->where('status', 'RETURNED')->count(),
                'cancelled' => $cases->where('status', 'CANCELLED')->count(),
                'temp_only' => $cases->where('identification_status', 'TEMP_ONLY')->count(),
            ],
            'rows' => $rows->all(),
            'by_defect' => $byDefect->all(),
            'pii_included' => $includePii,
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  UTILITIES
    // ══════════════════════════════════════════════════════════

    private function period(Request $request, bool $required = false): array
    {
        $rules = [
            'from_date' => [$required ? 'required' : 'nullable', 'date'],
            'to_date' => [$required ? 'required' : 'nullable', 'date', 'after_or_equal:from_date'],
        ];
        $validated = $request->validate($rules);
        $from = (string) ($validated['from_date'] ?? $request->input('from') ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? $request->input('to') ?? now()->toDateString());

        return [$from, $to];
    }

    private function paginateReport(array $report, Request $request, string $rowsKey = 'rows'): array
    {
        if (! $request->filled('per_page')) {
            return $report;
        }
        $perPage = min(max(1, (int) $request->input('per_page', self::DEFAULT_PER_PAGE)), self::MAX_PER_PAGE);
        $page = max(1, (int) $request->input('page', 1));
        $allRows = collect($report[$rowsKey]);
        $total = $allRows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $report[$rowsKey] = $allRows->forPage($page, $perPage)->values()->all();
        $report['pagination'] = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];

        return $report;
    }

    private function dimensionKey(mixed $warehouseId, mixed $productId, mixed $status): string
    {
        return (string) $warehouseId.'|'.(string) $productId.'|'.(string) $status;
    }

    private function exportRows(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName, string $reportKey)
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(
                422,
                'REPORT_EXPORT_ROW_LIMIT_EXCEEDED',
                'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.',
                ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]
            );
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, "A{$rowIndex}");
            $rowIndex++;
        }
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'row_count' => count($rows), 'filters' => $request->query()],
        );
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(string $title, array $headers, array $rows, string $filePrefix, string $reportKey, Request $request)
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]);
        }

        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'format' => 'pdf', 'row_count' => count($rows), 'filters' => $request->query()],
        );

        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #333;padding:4px 6px;text-align:left}th{background:#e0e0e0;font-weight:bold}h1{font-size:16px;margin:0 0 5px}p{margin:2px 0;font-size:9px;color:#666}</style></head><body>';
        $html .= '<h1>'.htmlspecialchars($title).'</h1>';
        $html .= '<p>Xuất lúc: '.now()->format('d/m/Y H:i:s').' · Tổng dòng: '.count($rows).'</p>';
        $html .= '<table><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.htmlspecialchars((string) $h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.htmlspecialchars((string) ($cell ?? '')).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', count($headers) > 8 ? 'landscape' : 'portrait');
        $dompdf->render();
        $fileName = "{$filePrefix}.pdf";

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    private function excelSafe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
