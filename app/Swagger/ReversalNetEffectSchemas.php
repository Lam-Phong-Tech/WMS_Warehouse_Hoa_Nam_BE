<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * WWHN-240 — Catalog schema cho ngữ nghĩa gross / reversal / net effect.
 *
 * LÝ DO TỒN TẠI (DEV240-P1-02C): trước WWHN-240, hai contract quan trọng nhất của
 * luồng đảo giao dịch chỉ có `description` bằng văn xuôi, không có `content`:
 *
 * - `GET /api/v1/reports/inventory-xnt` chỉ khai `response 200 description: 'XNT'`,
 *   nên không client nào suy được cột nào là gross, cột nào đã trừ reversal.
 * - `GET /api/v1/stock-movements/{id}/trace-source` nằm ngoài danh sách scan của
 *   `config/l5-swagger.php`, nên annotation thật KHÔNG bao giờ được publish và
 *   `RouteSyncProcessor` sinh ra một skeleton chung chung thay cho contract.
 *
 * Khai một lần ở đây, controller chỉ `$ref` tới, nên contract không thể lệch khỏi
 * runtime mà không sửa cùng một chỗ.
 *
 * Nguồn nghiệp vụ: SRS/FRD v1.12 §5.5 (BR-REV-01..12, đặc biệt BR-REV-08/BR-REV-11),
 * §13.10 RPT-XNT-01 và RPT-MOV-01, BR-RPT-01..07, DEC-REV-001 / WWHN-26.
 */
#[OA\Schema(
    schema: 'XntReportLine',
    title: 'Dòng báo cáo Nhập-Xuất-Tồn (RPT-XNT-01)',
    description: <<<'TXT'
    Ba tầng cột theo BR-RPT-01..07 ("hiển thị gross/net"):

    - GROSS  (`*_gross`): lượng đã Post trong kỳ, KHÔNG trừ reversal. Không bao giờ ẩn lịch sử (BR-REV-11).
    - REVERSAL (`*_reversal`): phần của cột gross tương ứng đã bị đảo, quy về CÙNG CHIỀU DƯƠNG với cột gross.
    - NET (`*_net`): `gross - reversal`, là con số nghiệp vụ dùng đối soát kỳ.

    Việc phân loại một REVERSAL vào cột Nhập hay cột Xuất căn cứ `movement_type` của
    giao dịch GỐC trong correction chain, không căn cứ dấu của `qty_delta` (đảo một
    ISSUE và đảo một COMPONENT_ISSUE đều cho `qty_delta` dương).

    Bất biến: `closing == opening + receipt_net - issue_net - component_issue_net + reversal_unlinked_net`
    và cũng `== opening + receipt - issue - component_issue + reversal_net`.
    TXT,
    type: 'object',
    required: [
        'warehouse_code', 'product_code', 'product_name', 'stock_status', 'opening',
        'receipt', 'receipt_gross', 'receipt_reversal', 'receipt_net',
        'issue', 'issue_gross', 'issue_reversal', 'issue_net',
        'component_issue', 'component_issue_gross', 'component_issue_reversal', 'component_issue_net',
        'reversal_unlinked_net', 'reversal_net', 'closing',
    ],
    properties: [
        new OA\Property(property: 'warehouse_code', type: 'string', example: 'KHO-HN-01'),
        new OA\Property(property: 'product_code', type: 'string', example: 'DCH-DRILL-DJZ10A-220V-V1'),
        new OA\Property(property: 'product_name', type: 'string', example: 'Máy khoan DJZ10A 220V'),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'], example: 'AVAILABLE'),
        new OA\Property(property: 'opening', type: 'number', format: 'float', description: 'NET — tồn đầu kỳ, đã gồm mọi reversal Post trước from_date.', example: 100),
        new OA\Property(property: 'receipt', type: 'number', format: 'float', description: 'GROSS — bí danh tương thích của receipt_gross.', example: 30),
        new OA\Property(property: 'receipt_gross', type: 'number', format: 'float', description: 'GROSS — tổng RECEIPT đã Post trong kỳ.', example: 30),
        new OA\Property(property: 'receipt_reversal', type: 'number', format: 'float', description: 'REVERSAL — lượng RECEIPT đã bị đảo, chiều dương.', example: 4),
        new OA\Property(property: 'receipt_net', type: 'number', format: 'float', description: 'NET — receipt_gross - receipt_reversal.', example: 26),
        new OA\Property(property: 'issue', type: 'number', format: 'float', description: 'GROSS — bí danh tương thích của issue_gross.', example: 20),
        new OA\Property(property: 'issue_gross', type: 'number', format: 'float', description: 'GROSS — tổng ISSUE đã Post trong kỳ.', example: 20),
        new OA\Property(property: 'issue_reversal', type: 'number', format: 'float', description: 'REVERSAL — lượng ISSUE đã bị đảo, chiều dương.', example: 6),
        new OA\Property(property: 'issue_net', type: 'number', format: 'float', description: 'NET — issue_gross - issue_reversal.', example: 14),
        new OA\Property(property: 'component_issue', type: 'number', format: 'float', description: 'GROSS — bí danh tương thích của component_issue_gross.', example: 5),
        new OA\Property(property: 'component_issue_gross', type: 'number', format: 'float', example: 5),
        new OA\Property(property: 'component_issue_reversal', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'component_issue_net', type: 'number', format: 'float', example: 5),
        new OA\Property(property: 'reversal_unlinked_net', type: 'number', format: 'float', description: 'REVERSAL — qty_delta của reversal không truy được movement gốc (dữ liệu migrate/OPENING). Vẫn vào closing để báo cáo khớp ledger.', example: 0),
        new OA\Property(property: 'reversal_net', type: 'number', format: 'float', description: 'REVERSAL — tổng qty_delta CÓ DẤU của mọi REVERSAL trong kỳ = -receipt_reversal + issue_reversal + component_issue_reversal + reversal_unlinked_net.', example: 2),
        new OA\Property(property: 'closing', type: 'number', format: 'float', description: 'NET — tồn cuối kỳ.', example: 117),
    ]
)]
#[OA\Schema(
    schema: 'XntReport',
    title: 'Báo cáo Nhập-Xuất-Tồn (RPT-XNT-01)',
    description: 'Kỳ inclusive theo Asia/Ho_Chi_Minh. `data_scope`/`scope_warehouse_ids` cho biết payload đã bị cắt theo warehouse data-scope nào.',
    type: 'object',
    required: ['from_date', 'to_date', 'formula', 'formula_net', 'column_semantics', 'lines'],
    properties: [
        new OA\Property(property: 'from_date', type: 'string', format: 'date', example: '2026-08-01'),
        new OA\Property(property: 'to_date', type: 'string', format: 'date', example: '2026-08-18'),
        new OA\Property(property: 'formula', type: 'string', description: 'Công thức theo cột GROSS, giữ nguyên từ baseline trước WWHN-240.', example: 'Closing = Opening + Receipt - Issue - Component Issue + Reversal'),
        new OA\Property(property: 'formula_net', type: 'string', description: 'Công thức theo cột NET; tương đương công thức gross.', example: 'Closing = Opening + Receipt Net - Issue Net - Component Issue Net + Unlinked Reversal Net; Receipt Net = Receipt Gross - Receipt Reversal; Issue Net = Issue Gross - Issue Reversal; Component Issue Net = Component Issue Gross - Component Issue Reversal'),
        new OA\Property(property: 'column_semantics', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Bản đồ tên cột → nhãn GROSS / REVERSAL / NET kèm định nghĩa.'),
        new OA\Property(property: 'data_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED'], nullable: true, example: 'WAREHOUSE_SCOPED'),
        new OA\Property(property: 'scope_warehouse_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/XntReportLine')),
        new OA\Property(property: 'pagination', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'current_page', type: 'integer', example: 1),
            new OA\Property(property: 'per_page', type: 'integer', example: 50),
            new OA\Property(property: 'total', type: 'integer', example: 128),
            new OA\Property(property: 'last_page', type: 'integer', example: 3),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'CorrectionChainNode',
    title: 'Một mắt xích trong correction chain',
    description: 'Movement gốc và toàn bộ Reversal append-only tham chiếu nó. BR-REV-01: movement gốc bất biến, không bao giờ bị ẩn hay sửa.',
    type: 'object',
    required: ['id', 'movement_type', 'role', 'depth', 'is_subject', 'occurred_at', 'qty_delta'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'movement_no', type: 'string', nullable: true, example: 'MV-20260818-A1B2C3D4'),
        new OA\Property(property: 'movement_type', type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'], example: 'RECEIPT'),
        new OA\Property(property: 'role', type: 'string', enum: ['ORIGINAL', 'REVERSAL'], description: 'ORIGINAL = gốc của chain (depth 0); REVERSAL = movement đối ứng append-only.', example: 'ORIGINAL'),
        new OA\Property(property: 'depth', type: 'integer', description: 'Khoảng cách tới movement gốc; 0 là gốc.', example: 0),
        new OA\Property(property: 'is_subject', type: 'boolean', description: 'true đúng tại movement được truy vết trong URL.', example: true),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'warehouse_code', type: 'string', nullable: true),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'product_code', type: 'string', nullable: true),
        new OA\Property(property: 'stock_status', type: 'string', nullable: true),
        new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', type: 'number', format: 'float', nullable: true, description: 'Số lượng tuyệt đối của bút toán.'),
        new OA\Property(property: 'qty_delta', type: 'number', format: 'float', description: 'Biến động CÓ DẤU lên tồn. Reversal luôn ngược dấu với movement nó đảo.', example: 10),
        new OA\Property(property: 'qty_before', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'qty_after', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'source_type', type: 'string', nullable: true, example: 'REVERSAL_DOCUMENT'),
        new OA\Property(property: 'source_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'stock_document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'original_movement_id', type: 'string', format: 'uuid', nullable: true, description: 'Cha trực tiếp trong chain. BR-REV-08: reversal tiếp theo trỏ vào movement gần nhất, nên giá trị này có thể là một REVERSAL khác.'),
        new OA\Property(property: 'correction_chain_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'actor', type: 'string', nullable: true, description: 'display_name của người thực hiện; null nếu movement do job hệ thống sinh.'),
        new OA\Property(property: 'reversal_document_no', type: 'string', nullable: true, example: 'REV-20260818103000-XY7Q2M'),
        new OA\Property(property: 'reversal_status', type: 'string', nullable: true, enum: ['DRAFT', 'CONFIRMED', 'POSTED', 'CANCELLED']),
        new OA\Property(property: 'reason_code', type: 'string', nullable: true, example: 'CORRECTION'),
        new OA\Property(property: 'reason_text', type: 'string', nullable: true, example: 'Giao nhầm Item'),
    ]
)]
#[OA\Schema(
    schema: 'CorrectionChain',
    title: 'Correction chain đầy đủ (BR-REV-11)',
    type: 'object',
    required: ['root_movement_id', 'node_count', 'reversal_count', 'nodes'],
    properties: [
        new OA\Property(property: 'correction_chain_id', type: 'string', format: 'uuid', nullable: true, description: 'null khi movement chưa từng bị đảo.'),
        new OA\Property(property: 'root_movement_id', type: 'string', format: 'uuid', description: 'Movement gốc của chain; bằng chính movement đang tra khi nó chưa bị đảo.'),
        new OA\Property(property: 'node_count', type: 'integer', example: 3),
        new OA\Property(property: 'reversal_count', type: 'integer', example: 2),
        new OA\Property(property: 'max_depth', type: 'integer', example: 2),
        new OA\Property(property: 'truncated', type: 'boolean', description: 'true nếu chain sâu hơn trần an toàn 32 mắt xích và đã bị cắt.', example: false),
        new OA\Property(property: 'nodes', type: 'array', items: new OA\Items(ref: '#/components/schemas/CorrectionChainNode'), description: 'Sắp xếp tăng dần theo occurred_at rồi id.'),
    ]
)]
#[OA\Schema(
    schema: 'ReversalNetEffect',
    title: 'Net effect của correction chain',
    description: <<<'TXT'
    Tính hoàn toàn từ ledger `stock_movements` (DEC-INV-001: ledger là nguồn tồn chuẩn),
    nên luôn khớp với báo cáo XNT và với /reports/movement-trace.

    - `net_qty_delta` = tổng qty_delta CÓ DẤU của toàn chain (tác động ròng còn lại lên tồn).
    - `reversed_qty`  = |qty_delta gốc - net_qty_delta| (một reversal-của-reversal sẽ LÀM GIẢM con số này).
    - `remaining_reversible_qty` = |qty_delta gốc| - reversed_qty. Đây là số HIỂN THỊ; guard
      khi Post Reversal vẫn revalidate lại trong transaction theo BR-REV-05/BR-REV-09.
    TXT,
    type: 'object',
    required: ['basis', 'original_movement_id', 'original_movement_type', 'original_qty', 'original_qty_delta', 'reversed_qty', 'remaining_reversible_qty', 'net_qty_delta', 'fully_reversed', 'reversal_count'],
    properties: [
        new OA\Property(property: 'basis', type: 'string', enum: ['LEDGER'], description: 'Nguồn tính net effect. LEDGER = stock_movements append-only.', example: 'LEDGER'),
        new OA\Property(property: 'original_movement_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'original_movement_type', type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'], example: 'RECEIPT'),
        new OA\Property(property: 'original_qty', type: 'number', format: 'float', description: 'Trị tuyệt đối của qty_delta gốc.', example: 10),
        new OA\Property(property: 'original_qty_delta', type: 'number', format: 'float', example: 10),
        new OA\Property(property: 'reversed_qty', type: 'number', format: 'float', example: 3),
        new OA\Property(property: 'remaining_reversible_qty', type: 'number', format: 'float', example: 7),
        new OA\Property(property: 'net_qty_delta', type: 'number', format: 'float', example: 7),
        new OA\Property(property: 'fully_reversed', type: 'boolean', example: false),
        new OA\Property(property: 'reversal_count', type: 'integer', example: 1),
        new OA\Property(property: 'subject_reversed_qty', type: 'number', format: 'float', description: 'Lượng đã đảo tính riêng cho movement đang tra (không phải cho gốc chain).', example: 3),
    ]
)]
#[OA\Schema(
    schema: 'StockMovementTraceSource',
    title: 'Trace-source của một movement (RPT-MOV-01 / BR-REV-11)',
    description: 'Trả movement gốc, TOÀN BỘ reversal chain và net effect. Các khoá `original_movement`/`reversal_movement` giữ lại nguyên hình dạng cũ để client hiện hành không vỡ; consumer mới đọc `correction_chain` + `net_effect`.',
    type: 'object',
    required: ['movement', 'correction_chain', 'net_effect'],
    properties: [
        new OA\Property(property: 'movement', type: 'object', additionalProperties: true, description: 'Movement đang tra, kèm product/warehouse/creator.'),
        new OA\Property(property: 'document', type: 'object', additionalProperties: true, nullable: true, description: 'Chứng từ nguồn (stock_documents) nếu movement sinh từ Post phiếu.'),
        new OA\Property(property: 'status_history', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true, description: 'Lịch sử chuyển trạng thái của chứng từ nguồn.'),
        new OA\Property(property: 'original_movement', type: 'object', additionalProperties: true, nullable: true, description: 'Cha TRỰC TIẾP; null khi movement này không phải reversal.'),
        new OA\Property(property: 'root_movement', type: 'object', additionalProperties: true, nullable: true, description: 'Gốc của chain sau khi đi ngược hết BR-REV-08.'),
        new OA\Property(property: 'reversal_movement', type: 'object', additionalProperties: true, nullable: true, description: 'DEPRECATED — chỉ là phần tử đầu của reversal_movements; giữ cho client cũ.'),
        new OA\Property(property: 'reversal_movements', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), description: 'Mọi reversal trực tiếp của movement đang tra.'),
        new OA\Property(property: 'correction_chain', ref: '#/components/schemas/CorrectionChain'),
        new OA\Property(property: 'net_effect', ref: '#/components/schemas/ReversalNetEffect'),
    ]
)]
class ReversalNetEffectSchemas {}
