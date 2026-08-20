<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * OpenAPI-only catalog cho các endpoint trace/reversal liền kề Flow 06.
 *
 * LƯU Ý CONTRACT: các path `/api/v1/reports/*` KHÔNG còn khai ở đây. Trước đây
 * lớp này khai trùng path với OperationalReportController/Aligned*ReportController
 * nhưng không có một query parameter nào; vì `config/l5-swagger.php` chỉ scan
 * `app/Swagger` nên bản stub luôn thắng và Swagger publish toàn bộ báo cáo Flow 06
 * với `parameters: []` — trái §8.4 và Annex C. Nay contract báo cáo nằm ngay trên
 * controller thật (đã được thêm vào danh sách scan) để filter §13.10 luôn khớp code.
 */
class Flow06ReportDocumentation
{
    #[OA\Get(path: '/api/v1/inventory/balances/{id}/subledger', operationId: 'flow06_inventory_balance_subledger', summary: 'Chi tiết subledger item và container của một số dư tồn', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Subledger tồn kho')])]
    public function inventoryBalanceSubledger(): void {}

    #[OA\Get(path: '/api/v1/reversal-documents', operationId: 'flow06_reversal_list', summary: 'Danh sách phiếu reversal và correction', security: [['bearerAuth' => []]], tags: ['07. Inventory'], responses: [new OA\Response(response: 200, description: 'Danh sách reversal')])]
    public function reversalList(): void {}

    // POST /api/v1/reversal-documents KHÔNG còn khai stub ở đây (DEV handover QA
    // 2026-08-20): contract thật (requestBody reference_document_id/reason/lines...)
    // nằm ngay trên ReversalDocumentController::store, cùng lý do với các path
    // /api/v1/reports/* ở ghi chú đầu lớp — giữ stub sẽ va chạm và đè mất schema thật.

    #[OA\Get(path: '/api/v1/reversal-documents/{id}', operationId: 'flow06_reversal_detail', summary: 'Chi tiết phiếu reversal và correction chain', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Chi tiết reversal')])]
    public function reversalDetail(): void {}

    #[OA\Get(path: '/api/v1/reversal-documents/{id}/lines', operationId: 'flow06_reversal_lines', summary: 'Danh sách dòng movement được reversal', security: [['bearerAuth' => []]], tags: ['07. Inventory'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dòng reversal')])]
    public function reversalLines(): void {}
}
