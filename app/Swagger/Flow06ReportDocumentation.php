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
    // GET /api/v1/inventory/balances/{id}/subledger KHÔNG còn khai stub ở đây
    // (DEV handover QA 2026-08-20, HN-BE-API-005): contract thật (tham chiếu
    // item/container, permission inventory.view) nằm ngay trên
    // InventoryBalanceController::subledger(), file này nay đã nằm trong danh
    // sách scan — giữ stub trùng path sẽ va chạm và đè mất schema thật, cùng
    // lý do với các path /api/v1/reports/* ở ghi chú đầu lớp.

    // GET /api/v1/reversal-documents, /{id} và /{id}/lines KHÔNG còn khai stub ở
    // đây (26/08): contract thật (query status/keyword, data-scope kho...) nay nằm
    // ngay trên ReversalDocumentController::index/show/lines — cùng lý do với các
    // stub khác đã dọn ở trên, giữ lại sẽ va chạm "Unable to merge @OA\Get()" và
    // đè mất schema thật.

    // POST /api/v1/reversal-documents KHÔNG còn khai stub ở đây (DEV handover QA
    // 2026-08-20): contract thật (requestBody reference_document_id/reason/lines...)
    // nằm ngay trên ReversalDocumentController::store, cùng lý do với các path
    // /api/v1/reports/* ở ghi chú đầu lớp — giữ stub sẽ va chạm và đè mất schema thật.
}
