<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * OpenAPI-only catalog for Flow 06 and adjacent trace/reversal endpoints.
 * Runtime handlers remain in Modules/Warehouse; this class is scanned by L5 Swagger.
 */
class Flow06ReportDocumentation
{
    #[OA\Get(path: '/api/v1/inventory/balances/{id}/subledger', operationId: 'flow06_inventory_balance_subledger', summary: 'Chi tiết subledger item và container của một số dư tồn', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Subledger tồn kho')])]
    public function inventoryBalanceSubledger(): void {}

    #[OA\Get(path: '/api/v1/reports/hub', operationId: 'flow06_report_hub', summary: 'Danh mục tám báo cáo vận hành MVP', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Danh mục báo cáo')])]
    public function reportHub(): void {}

    #[OA\Get(path: '/api/v1/reports/inventory-balance', operationId: 'flow06_inventory_balance', summary: 'Báo cáo tồn kho hiện tại kèm đối soát ledger', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Báo cáo tồn kho')])]
    public function inventoryBalance(): void {}

    #[OA\Get(path: '/api/v1/reports/inventory-balance/export-excel', operationId: 'flow06_inventory_balance_export', summary: 'Xuất Excel báo cáo tồn kho hiện tại', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function inventoryBalanceExport(): void {}

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced', operationId: 'flow06_inventory_xnt_enhanced', summary: 'Báo cáo Nhập-Xuất-Tồn chi tiết theo movement type', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Báo cáo XNT chi tiết')])]
    public function inventoryXntEnhanced(): void {}

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced/export-excel', operationId: 'flow06_inventory_xnt_enhanced_export', summary: 'Xuất Excel báo cáo Nhập-Xuất-Tồn chi tiết', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function inventoryXntEnhancedExport(): void {}

    #[OA\Get(path: '/api/v1/reports/document-history', operationId: 'flow06_document_history', summary: 'Lịch sử phiếu nhập và phiếu xuất theo trạng thái', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Lịch sử phiếu')])]
    public function documentHistory(): void {}

    #[OA\Get(path: '/api/v1/reports/document-history/export-excel', operationId: 'flow06_document_history_export', summary: 'Xuất Excel lịch sử phiếu nhập và phiếu xuất', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function documentHistoryExport(): void {}

    #[OA\Get(path: '/api/v1/reports/movement-trace', operationId: 'flow06_movement_trace', summary: 'Truy vết movement, reversal và correction chain', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Movement trace')])]
    public function movementTrace(): void {}

    #[OA\Get(path: '/api/v1/reports/movement-trace/export-excel', operationId: 'flow06_movement_trace_export', summary: 'Xuất Excel movement trace và correction chain', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function movementTraceExport(): void {}

    #[OA\Get(path: '/api/v1/reports/import-match', operationId: 'flow06_import_match', summary: 'Đối soát batch Excel yêu cầu xuất và kết quả match', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Đối soát import')])]
    public function importMatch(): void {}

    #[OA\Get(path: '/api/v1/reports/import-match/export-excel', operationId: 'flow06_import_match_export', summary: 'Xuất Excel đối soát import và match', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function importMatchExport(): void {}

    #[OA\Get(path: '/api/v1/reports/label-log', operationId: 'flow06_label_log', summary: 'Nhật ký in nhãn, lỗi in và in lại', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Nhật ký nhãn')])]
    public function labelLog(): void {}

    #[OA\Get(path: '/api/v1/reports/label-log/export-excel', operationId: 'flow06_label_log_export', summary: 'Xuất Excel nhật ký in nhãn và in lại', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function labelLogExport(): void {}

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency', operationId: 'flow06_outbound_by_agency', summary: 'Báo cáo xuất kho theo nơi nhận', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Xuất theo nơi nhận')])]
    public function outboundByAgency(): void {}

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency/export-excel', operationId: 'flow06_outbound_by_agency_export', summary: 'Xuất Excel báo cáo xuất kho theo nơi nhận', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function outboundByAgencyExport(): void {}

    #[OA\Get(path: '/api/v1/reversal-documents', operationId: 'flow06_reversal_list', summary: 'Danh sách phiếu reversal và correction', security: [['bearerAuth' => []]], tags: ['07. Inventory'], responses: [new OA\Response(response: 200, description: 'Danh sách reversal')])]
    public function reversalList(): void {}

    #[OA\Post(path: '/api/v1/reversal-documents', operationId: 'flow06_reversal_create', summary: 'Tạo reversal append-only cho movement đã post', security: [['bearerAuth' => []]], tags: ['07. Inventory'], responses: [new OA\Response(response: 201, description: 'Đã tạo reversal')])]
    public function reversalCreate(): void {}

    #[OA\Get(path: '/api/v1/reversal-documents/{id}', operationId: 'flow06_reversal_detail', summary: 'Chi tiết phiếu reversal và correction chain', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Chi tiết reversal')])]
    public function reversalDetail(): void {}

    #[OA\Get(path: '/api/v1/reversal-documents/{id}/lines', operationId: 'flow06_reversal_lines', summary: 'Danh sách dòng movement được reversal', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dòng reversal')])]
    public function reversalLines(): void {}
}
