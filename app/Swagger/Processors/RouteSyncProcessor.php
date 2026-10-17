<?php

namespace App\Swagger\Processors;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Undefined;

class RouteSyncProcessor
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    private const SUCCESS_ENVELOPE_REF = '#/components/schemas/ApiSuccessEnvelope';

    private const ERROR_ENVELOPE_REF = '#/components/schemas/ApiErrorEnvelope';

    private const ERROR_RESPONSES = [
        400 => 'Yêu cầu không đúng định dạng',
        401 => 'Chưa xác thực',
        403 => 'Không có quyền truy cập',
        404 => 'Không tìm thấy tài nguyên hoặc nằm ngoài phạm vi dữ liệu',
        409 => 'Xung đột trạng thái, nghiệp vụ, idempotency hoặc thao tác đồng thời',
        412 => 'Điều kiện If-Match không đạt',
        422 => 'Dữ liệu không hợp lệ hoặc vi phạm quy tắc nghiệp vụ',
        428 => 'Thiếu điều kiện If-Match bắt buộc',
        500 => 'Lỗi hệ thống ngoài dự kiến; giao dịch đã được rollback',
    ];

    private const TAGS = [
        '00. Platform' => 'Các endpoint kiểm tra sức khỏe hệ thống và cấp nền tảng.',
        '01. Authentication' => 'Xác thực JWT và ngữ cảnh người dùng hiện tại.',
        '02. Users' => 'Quản lý người dùng IAM.',
        '03. Roles' => 'Quản lý vai trò IAM và phân quyền theo vai trò.',
        '04. Permissions' => 'Danh mục quyền IAM.',
        '05. Product' => 'Các endpoint SKU và sản phẩm.',
        '06. Warehouse' => 'Thông tin master một kho Hoa Nam.',
        '07. Inventory' => 'Số dư tồn kho, truy vết biến động và đối soát.',
        '08. Catalog Master' => 'Dữ liệu master tham chiếu của sản phẩm.',
        '09. Stock In (Inbound)' => 'Luồng nhập kho đầy đủ đến bước ghi nhận.',
        '10. Stock Out (Outbound)' => 'Luồng xuất kho gồm import, khớp dữ liệu và ghi nhận đầy đủ.',
        '11. Scan' => 'Các endpoint phân loại mã quét.',
        '12. Dashboard / Report' => 'Báo cáo tồn kho và dữ liệu dashboard.',
        '13. Warranty' => 'Hồ sơ bảo hành và sự kiện bảo hành.',
        '14. Component Issue' => 'Chứng từ xuất linh kiện chỉ dùng cho bảo hành.',
        '15. Audit Log' => 'Bằng chứng audit dạng chỉ ghi thêm.',
        '16. Agency' => 'Các endpoint master bên nhận.',
        '17. Defect Catalog' => 'Danh mục lỗi bảo hành.',
        '18. Physical Inventory' => 'Đối tượng hàng hóa, mã vật lý, lô import và container.',
        '19. Label / Printer' => 'Ảnh chụp nhãn, in nhãn và bằng chứng in lại.',
        '20. System Operation' => 'Dự phòng cho các endpoint quản trị vận hành hệ thống.',
        '21. Public Catalog' => 'Các endpoint danh mục và gợi ý PV chỉ đọc.',
        '22. Mini App' => 'API adapter riêng cho Warehouse Mini App; local staging, scan evidence, duyệt và Post Receipt dùng canonical inbound bên dưới.',
        '24. Putaway' => 'Xếp BOX linh kiện đã nhận vào TRAY; toàn bộ thao tác stock-neutral.',
    ];

    private const ROUTE_SUMMARIES = [
        'post api/v1/auth/recovery-requests' => 'Gửi yêu cầu hỗ trợ khôi phục tài khoản',
        'get api/v1/auth/recovery-requests/operations/{operationid}' => 'Tra cứu biên nhận hỗ trợ bằng bằng chứng riêng',
        'get api/v1/mini-app/catalog/context' => 'Ngữ cảnh kho và quyền đọc danh mục Mini App',
        'get api/v1/mini-app/catalog/items' => 'Danh sách hiện vật theo kho đã xác nhận',
        'get api/v1/mini-app/catalog/items/{id}' => 'Chi tiết hiện vật trong danh mục',
        'get api/v1/mini-app/catalog/items/{id}/timeline' => 'Lịch sử hiện vật trong danh mục',
        'get api/v1/mini-app/catalog/skus' => 'Danh sách SKU theo kho đã xác nhận',
        'get api/v1/mini-app/catalog/skus/{id}' => 'Chi tiết SKU trong danh mục',
        'get api/v1/mini-app/catalog/skus/{id}/gallery' => 'Ảnh sản phẩm của SKU',
        'get api/v1/mini-app/catalog/skus/{id}/stock' => 'Tồn kho của SKU theo vị trí',
        'get api/v1/mini-app/catalog/skus/{id}/timeline' => 'Lịch sử giao dịch của SKU',
        'get api/v1/mini-app/dashboard/summary' => 'KPI Mini App theo quyền và kho sở hữu',
        'get api/v1/mini-app/documents' => 'Danh sách chứng từ trộn theo cursor chung',
        'get api/v1/mini-app/drafts' => 'Danh sách nháp của người dùng và phiên hiện tại',
        'get api/v1/mini-app/drafts/{id}' => 'Đọc nháp theo định danh owner',
        'put api/v1/mini-app/drafts/{id}' => 'Lưu nháp với version và idempotency key',
        'post api/v1/mini-app/drafts/{id}/cancel' => 'Hủy nháp theo version đã xác nhận',
        'get api/v1/mini-app/drafts/{id}/requests/{requestid}' => 'Đối chiếu biên nhận thao tác trên nháp',
        'get api/v1/mini-app/history/records' => 'Danh sách hoạt động lịch sử theo kho',
        'get api/v1/mini-app/history/records/{recordid}' => 'Chi tiết hoạt động lịch sử theo nguồn',
        'get api/v1/mini-app/history/scan-sessions/{sessionid}' => 'Chi tiết phiên quét và tổng toàn phiên',
        'get api/v1/mini-app/history/summary' => 'Tổng hợp lịch sử trên toàn bộ phạm vi được đọc',
        'get api/v1/mini-app/inbound/context' => 'Ngữ cảnh và quyền nhập kho P04',
        'get api/v1/mini-app/inbound/documents/{id}' => 'Đọc chứng từ nhập kho P04',
        'get api/v1/mini-app/inbound/drafts/{id}' => 'Đọc nháp nhập kho của phiên hiện tại',
        'put api/v1/mini-app/inbound/drafts/{id}' => 'Lưu nháp nhập kho P04',
        'post api/v1/mini-app/inbound/drafts/{id}/cancel' => 'Hủy nháp nhập kho P04',
        'post api/v1/mini-app/inbound/drafts/{id}/record' => 'Ghi nhận nháp nhập kho bằng identity gốc',
        'get api/v1/mini-app/inbound/drafts/{id}/requests/{requestid}' => 'Đối chiếu biên nhận nháp nhập kho',
        'post api/v1/mini-app/inbound/drafts/{id}/review' => 'Kiểm tra dữ liệu nháp nhập kho trước ghi nhận',
        'get api/v1/mini-app/inbound/drafts/{id}/scan-session' => 'Đọc danh sách quét đã lưu của nháp nhập kho',
        'post api/v1/mini-app/inbound/drafts/{id}/validate-code' => 'Kiểm tra mã quét theo nháp và phiên nhập kho',
        'get api/v1/mini-app/inbound/suppliers' => 'Danh sách nhà cung cấp được phép dùng khi nhập kho',
        'get api/v1/mini-app/inbound/types' => 'Danh mục loại nhập kho P04',
        'get api/v1/mini-app/items/{itemid}/nfc/confirmation' => 'Đối chiếu biên nhận gắn NFC theo key gốc',
        'post api/v1/mini-app/items/{itemid}/nfc/release' => 'Giải phóng giữ chỗ ghi NFC của chính owner',
        'get api/v1/mini-app/nfc-tags/{physicalcodeid}' => 'Chi tiết thẻ NFC trong phạm vi kho',
        'get api/v1/mini-app/outbound/context' => 'Ngữ cảnh và quyền xuất kho P05',
        'get api/v1/mini-app/outbound/documents/{id}' => 'Đọc chứng từ xuất kho P05',
        'get api/v1/mini-app/outbound/sources' => 'Danh sách toa nguồn xuất kho được phép đọc',
        'get api/v1/mini-app/outbound/sources/{id}' => 'Chi tiết và version toa nguồn xuất kho',
        'get api/v1/mini-app/p03/context' => 'Ngữ cảnh phiên và các thao tác nháp được phép',
        'get api/v1/mini-app/scan-sessions' => 'Danh sách phiên quét với metadata lịch sử persisted',
        'delete api/v1/mini-app/warranty-attachments/{attachmentId}' => 'Xóa logic tệp bảo hành trong phạm vi owner',
        'get api/v1/mini-app/warranty-attachments/{attachmentId}/download' => 'Tải tệp bảo hành sau khi kiểm tra quyền owner',
        'patch api/v1/mini-app/warranty-cases/{id}' => 'Cập nhật hồ sơ bảo hành theo version và receipt',
        'get api/v1/mini-app/warranty-cases/{id}/attachments' => 'Danh sách tệp của hồ sơ bảo hành Mini App',
        'post api/v1/mini-app/warranty-cases/{id}/attachments' => 'Tải tệp lên owner hồ sơ bảo hành Mini App',
        'get api/v1/mini-app/warranty-cases/{id}/component-issues' => 'Danh sách phiếu xuất linh kiện đã POSTED của hồ sơ',
        'get api/v1/mini-app/warranty-requests/{requestid}' => 'Đối chiếu biên nhận lệnh bảo hành của phiên hiện tại',
        'get api/v1/mini-app/component-issue-requests/{requestid}' => 'Đối chiếu biên nhận lệnh xuất linh kiện bằng key gốc',
        'get api/v1/mini-app/inbound-documents/{id}/attachments/requests/{operationkey}' => 'Đối chiếu biên nhận tải tệp của phiếu nhập',
        'get api/v1/mini-app/outbound-documents/{id}/attachments/requests/{operationkey}' => 'Đối chiếu biên nhận tải tệp của phiếu xuất',
        'get api/v1/mini-app/warranty-cases/{id}/attachments/requests/{operationkey}' => 'Đối chiếu biên nhận tải tệp của hồ sơ bảo hành',
        'get api/v1/mini-app/work-shifts/current' => 'Đọc ca làm việc hiện tại của phiên đăng nhập',
        'post api/v1/mini-app/work-shifts/start' => 'Bắt đầu ca làm việc mới theo ý định người dùng',
        'get api/v1/mini-app/work-shifts/{shiftid}/close-check' => 'Kiểm tra blocker phía máy chủ trước kết ca',
        'post api/v1/mini-app/work-shifts/{shiftid}/end' => 'Kết ca bằng check token và trạng thái local CLEAN',
        'get api/v1/mini-app/work-shifts/{shiftid}/requests/{requestid}' => 'Đối chiếu biên nhận thao tác ca bằng key gốc',
        'get api/v1/mini-app/work-shifts/{shiftid}/summary' => 'Tổng hợp hoạt động của ca làm việc',
        // WWHN-249 — Annex C: listPvProducts / getPvProduct là path canonical của
        // App PV; /pv/catalog và /pv/tags là alias lịch sử dùng chung read model.
        'get api/v1/pv/products' => 'Danh mục sản phẩm App PV công khai',
        'get api/v1/pv/products/{skuId}' => 'Chi tiết sản phẩm App PV công khai',
        'get api/v1/pv/catalog' => 'Danh mục PV công khai',
        'get api/v1/pv/industries' => 'Danh sách ngành hàng PV',
        'get api/v1/pv/tags' => 'Danh sách tag PV',
        'get api/v1/pv/recommendations' => 'Gợi ý PV theo tiêu chí lọc',
        'get api/v1/pv-admin/resources/{resourceType}' => 'PV Admin — danh sách master App PV theo resource_type',
        'post api/v1/pv-admin/resources/{resourceType}' => 'PV Admin — tạo master App PV theo resource_type',
        'patch api/v1/pv-admin/resources/{resourceType}/{id}' => 'PV Admin — cập nhật master App PV (bắt buộc If-Match)',
        'post api/v1/inbound-documents/{id}/scan' => 'Quét hàng cho phiếu nhập',
        'post api/v1/inbound-documents/{id}/post-receipt' => 'Ghi nhận nhập kho cho phiếu nhập',
        'post api/v1/inbound-documents/{id}/reverse' => 'Hoàn tác nhập kho cho phiếu nhập',
        'get api/v1/putaway/tasks' => 'Danh sách BOX chờ xếp khay',
        'post api/v1/putaway/tasks' => 'Tạo tác vụ xếp BOX vào TRAY',
        'post api/v1/putaway/tasks/{id}/confirm' => 'Xác nhận xếp BOX vào TRAY',
        'post api/v1/putaway/tasks/{id}/cancel' => 'Huỷ tác vụ xếp BOX vào TRAY',
        'get api/v1/putaway/history' => 'Lịch sử xếp BOX vào TRAY',
        'post api/v1/outbound-documents/{id}/scan' => 'Quét hàng cho phiếu xuất',
        'post api/v1/outbound-documents/{id}/unmatch' => 'Gỡ item đã match khỏi phiếu xuất',
        'post api/v1/outbound-documents/{id}/post-issue' => 'Ghi nhận xuất kho cho phiếu xuất',
        'post api/v1/outbound-documents/{id}/reverse' => 'Hoàn tác xuất kho cho phiếu xuất',
        'get api/v1/inventory/balances' => 'Tồn kho hiện tại theo kho và SKU',
        'get api/v1/stock-movements/{id}/trace-source' => 'Truy vết nguồn phát sinh giao dịch kho',
        'get api/v1/reports/hub' => 'Danh mục 8 báo cáo vận hành Flow 06',
        'get api/v1/reports/inventory-balance' => 'Báo cáo tồn kho hiện tại kèm đối soát ledger',
        'get api/v1/reports/inventory-balance/export-excel' => 'Xuất Excel/PDF tồn kho hiện tại',
        'get api/v1/reports/inventory-xnt' => 'Báo cáo nhập xuất tồn',
        'get api/v1/reports/inventory-xnt/export-excel' => 'Xuất Excel/PDF báo cáo nhập xuất tồn',
        'get api/v1/reports/inventory-xnt-enhanced' => 'Báo cáo nhập xuất tồn chi tiết',
        'get api/v1/reports/inventory-xnt-enhanced/export-excel' => 'Xuất Excel/PDF nhập xuất tồn chi tiết',
        'get api/v1/reports/document-history' => 'Lịch sử phiếu nhập/xuất',
        'get api/v1/reports/document-history/export-excel' => 'Xuất Excel/PDF lịch sử phiếu',
        'get api/v1/reports/movement-trace' => 'Truy vết giao dịch kho (movement trace)',
        'get api/v1/reports/movement-trace/export-excel' => 'Xuất Excel/PDF movement trace',
        'get api/v1/reports/import-match' => 'Đối soát import toa yêu cầu xuất',
        'get api/v1/reports/import-match/export-excel' => 'Xuất Excel/PDF đối soát import toa',
        'get api/v1/reports/label-log' => 'Nhật ký nhãn Packing Label',
        'get api/v1/reports/label-log/export-excel' => 'Xuất Excel/PDF nhật ký nhãn',
        'get api/v1/reports/outbound-by-agency' => 'Báo cáo xuất theo nơi nhận',
        'get api/v1/reports/outbound-by-agency/export-excel' => 'Xuất Excel/PDF xuất theo nơi nhận',
        'get api/v1/reports/inventory-reconciliation' => 'Đối soát tồn kho với sổ giao dịch',
        'get api/v1/reports/dashboard-quick' => 'Số liệu nhanh cho dashboard',
        'get api/v1/reports/warranty-defects' => 'Báo cáo bảo hành & linh kiện',
        'get api/v1/reports/warranty-defects/export-excel' => 'Xuất Excel/PDF báo cáo bảo hành & linh kiện',
        'get api/v1/reports/inventory-ledger' => 'Sổ ledger tồn kho theo Annex C',
        'get api/v1/reports/{report_code}' => 'Lấy báo cáo theo report_code',
        'post api/v1/reports/export' => 'Tạo yêu cầu export báo cáo',
        'post api/v1/containers/{id}/items' => 'Thêm hàng vào container',
        'delete api/v1/containers/{id}/items/{containerItemId}' => 'Xoá hàng khỏi container',
        'get api/v1/item-instances/{id}/trace' => 'Truy vết vòng đời item instance',
        'post api/v1/brands' => 'Tạo thương hiệu mới',
        'post api/v1/product-categories' => 'Tạo danh mục sản phẩm mới',
        'post api/v1/power-sources' => 'Tạo nguồn điện mới',
        'post api/v1/product-models' => 'Tạo model sản phẩm mới',
        'post api/v1/package-variants' => 'Tạo quy cách đóng gói mới',
        'post api/v1/outbound-request-imports/{id}/consume' => 'Tiêu thụ dữ liệu import yêu cầu xuất',
        'get api/v1/outbound-request-imports/template' => 'Tải file mẫu Excel toa yêu cầu xuất',
        'get api/v1/outbound-request-imports/{id}/preview' => 'Preview kết quả validate import yêu cầu xuất',
        'get api/v1/outbound-request-imports/{id}/errors' => 'Danh sách lỗi row/field của import yêu cầu xuất',
        'post api/v1/label-snapshots/{id}/print' => 'In nhãn từ snapshot',
        'post api/v1/label-snapshots/{id}/reprint' => 'In lại nhãn từ snapshot',
        'post api/v1/component-issue-documents/{id}/scan' => 'Quét linh kiện cho phiếu xuất bảo hành',
        'post api/v1/component-issue-documents/{id}/cancel' => 'Huỷ phiếu xuất linh kiện bảo hành',
        'post api/v1/component-issue-documents/{id}/post' => 'Ghi nhận phiếu xuất linh kiện bảo hành',
        // Cặp staging của màn "Xuất linh kiện" phơi ở 4 đường dẫn tương đương (2 tên
        // resource × có/không prefix mini-app). Chỉ path gốc mang annotation OA; 3
        // bí danh còn lại do processor này sinh ra nên phải khai summary ở đây, nếu
        // không sẽ rơi về summary máy sinh và làm SwaggerRouteSyncTest đỏ.
        'post api/v1/component-issue-documents/resolve-code' => 'Tiền kiểm mã linh kiện vừa quét cho màn xuất linh kiện bảo hành',
        'post api/v1/component-issue-documents/record' => 'Xác nhận xuất linh kiện từ danh sách đã quét',
        'post api/v1/warranty-component-issues/resolve-code' => 'Tiền kiểm mã linh kiện vừa quét cho màn xuất linh kiện bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/record' => 'Xác nhận xuất linh kiện từ danh sách đã quét (Annex C)',
        'post api/v1/mini-app/component-issue-documents/resolve-code' => 'Mini App — tiền kiểm mã linh kiện vừa quét cho màn xuất linh kiện bảo hành',
        'post api/v1/mini-app/component-issue-documents/record' => 'Mini App — xác nhận xuất linh kiện từ danh sách đã quét',
        'get api/v1/warranty-cases/{id}/events' => 'Lịch sử sự kiện của hồ sơ bảo hành',
        'post api/v1/warranty-cases/{id}/temp-only' => 'Đánh dấu hồ sơ bảo hành chỉ xử lý tạm',
        'get api/v1/warranty-cases/{id}/attachments' => 'Danh sách tệp đính kèm của hồ sơ bảo hành',
        'post api/v1/warranty-cases/{id}/attachments' => 'Tải lên tệp đính kèm cho hồ sơ bảo hành',
        'get api/v1/warranty-attachments/{attachmentid}/download' => 'Tải xuống tệp đính kèm bảo hành',
        'delete api/v1/warranty-attachments/{attachmentid}' => 'Xoá (logical) tệp đính kèm bảo hành',
        'post api/v1/scan/classify' => 'Phân loại mã quét',
        // Alias theo đúng path Annex C (§8.1). Cùng handler với route hiện hành.
        'post api/v1/inbound-documents/{id}/scans' => 'Quét hàng cho phiếu nhập (Annex C)',
        'post api/v1/inbound-documents/{id}/reversals' => 'Hoàn tác nhập kho cho phiếu nhập (Annex C)',
        'post api/v1/outbound-documents/{id}/scans' => 'Quét hàng cho phiếu xuất (Annex C)',
        'post api/v1/outbound-documents/{id}/reversals' => 'Hoàn tác xuất kho cho phiếu xuất (Annex C)',
        'get api/v1/putaway-tasks' => 'Danh sách BOX chờ xếp khay (Annex C)',
        'post api/v1/putaway-tasks' => 'Tạo tác vụ xếp BOX vào TRAY (Annex C)',
        'post api/v1/putaway-tasks/{id}/confirm' => 'Xác nhận xếp BOX vào TRAY (Annex C)',
        'post api/v1/putaway-tasks/{id}/cancel' => 'Huỷ tác vụ xếp BOX vào TRAY (Annex C)',
        'post api/v1/warranty-cases/{id}/status' => 'Chuyển trạng thái hồ sơ bảo hành (Annex C)',
        'get api/v1/warranty-component-issues' => 'Danh sách phiếu xuất linh kiện bảo hành (Annex C)',
        'post api/v1/warranty-component-issues' => 'Tạo phiếu xuất linh kiện bảo hành (Annex C)',
        'get api/v1/warranty-component-issues/{id}' => 'Chi tiết phiếu xuất linh kiện bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/{id}/scans' => 'Quét linh kiện cho phiếu xuất bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/{id}/unscan' => 'Gỡ dòng linh kiện đã quét khỏi phiếu xuất bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/{id}/lines' => 'Thêm dòng linh kiện vào phiếu xuất bảo hành (Annex C)',
        'patch api/v1/warranty-component-issues/{id}/lines/{lineid}' => 'Sửa số lượng cần xuất của dòng linh kiện (Annex C)',
        'delete api/v1/warranty-component-issues/{id}/lines/{lineid}' => 'Xoá dòng linh kiện chưa quét khỏi phiếu xuất bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/{id}/cancel' => 'Huỷ phiếu xuất linh kiện bảo hành (Annex C)',
        'post api/v1/warranty-component-issues/{id}/post' => 'Ghi nhận phiếu xuất linh kiện bảo hành (Annex C)',
        // Mini App — cùng handler với nhóm canonical ở trên, chỉ khác prefix. Khai
        // summary ở đây là "fix đúng chỗ" mà SwaggerRouteSyncTest nhắc tới, thay cho
        // pin KNOWN_MACHINE_SUMMARY_OPERATIONS bên tests/.
        'get api/v1/mini-app/warranty-component-issues' => 'Mini App — danh sách phiếu xuất linh kiện bảo hành',
        'post api/v1/mini-app/warranty-component-issues' => 'Mini App — tạo phiếu xuất linh kiện bảo hành',
        'get api/v1/mini-app/warranty-component-issues/{id}' => 'Mini App — chi tiết phiếu xuất linh kiện bảo hành',
        'post api/v1/mini-app/warranty-component-issues/{id}/scans' => 'Mini App — quét linh kiện cho phiếu xuất bảo hành',
        'post api/v1/mini-app/warranty-component-issues/{id}/unscan' => 'Mini App — gỡ dòng linh kiện đã quét khỏi phiếu xuất bảo hành',
        'post api/v1/mini-app/warranty-component-issues/{id}/lines' => 'Mini App — thêm dòng linh kiện vào phiếu xuất bảo hành',
        'patch api/v1/mini-app/warranty-component-issues/{id}/lines/{lineid}' => 'Mini App — sửa số lượng cần xuất của dòng linh kiện',
        'delete api/v1/mini-app/warranty-component-issues/{id}/lines/{lineid}' => 'Mini App — xoá dòng linh kiện chưa quét khỏi phiếu xuất bảo hành',
        'post api/v1/mini-app/warranty-component-issues/{id}/cancel' => 'Mini App — huỷ phiếu xuất linh kiện bảo hành',
        'post api/v1/mini-app/warranty-component-issues/{id}/post' => 'Mini App — ghi nhận phiếu xuất linh kiện bảo hành',
        // DocumentAttachmentController phục vụ cả kênh Web lẫn Mini App (routes/mini_app.php).
        // Annotation OA chỉ khai path Web; 7 bí danh mini-app dưới đây do processor này sinh
        // nên phải có summary ở đây, cùng lý do với nhóm component-issue ở trên.
        'get api/v1/mini-app/inbound-documents/{id}/attachments' => 'Mini App — danh sách chứng từ đính kèm của phiếu nhập',
        'post api/v1/mini-app/inbound-documents/{id}/attachments' => 'Mini App — tải lên chứng từ đính kèm cho phiếu nhập (stock-neutral)',
        'get api/v1/mini-app/outbound-documents/{id}/attachments' => 'Mini App — danh sách chứng từ đính kèm của phiếu xuất',
        'post api/v1/mini-app/outbound-documents/{id}/attachments' => 'Mini App — tải lên chứng từ đính kèm cho phiếu xuất (stock-neutral)',
        'get api/v1/mini-app/document-attachments/pending-evidence' => 'Mini App — danh sách phiếu đang thiếu chứng từ bắt buộc',
        'get api/v1/mini-app/document-attachments/{attachmentid}/download' => 'Mini App — tải chứng từ đính kèm',
        'delete api/v1/mini-app/document-attachments/{attachmentid}' => 'Mini App — xoá chứng từ đính kèm (xoá logic)',
        'post api/v1/outbound-request-imports/{id}/confirm' => 'Xác nhận import yêu cầu xuất (Annex C)',
        'post api/v1/outbound-request-imports/manual' => 'Tạo toa yêu cầu xuất nhập tay (Annex C)',
        'get api/v1/inventory' => 'Tồn kho hiện tại (Annex C)',
        'get api/v1/inventory/trace/{code}' => 'Truy vết tồn kho theo mã vật lý (Annex C)',
        'post api/v1/scan-batches/classify' => 'Phân loại lô mã quét (Annex C)',
        'get api/v1/scan/batches' => 'Danh sách lô quét (scan evidence)',
        'get api/v1/scan/events' => 'Danh sách sự kiện quét (scan evidence)',
        'patch api/v1/brands/{id}' => 'Cập nhật thương hiệu',
        'patch api/v1/product-categories/{id}' => 'Cập nhật danh mục sản phẩm',
        'patch api/v1/power-sources/{id}' => 'Cập nhật nguồn điện',
        'patch api/v1/product-models/{id}' => 'Cập nhật model sản phẩm',
        'patch api/v1/package-variants/{id}' => 'Cập nhật quy cách đóng gói',
        'patch api/v1/pv/products/{id}/publish' => 'Publish/gỡ SKU khỏi App PV',
        'put api/v1/roles/{id}/permissions' => 'Thay toàn bộ tập quyền của vai trò',
        'get api/v1/inbound-documents/{id}/scan-entries' => 'Danh sách mã đã quét của phiếu nhập',
        'post api/v1/reversal-documents/{id}/confirm' => 'Xác nhận hàng vật lý quay lại kho cho Reversal',
        'post api/v1/reversal-documents/{id}/post' => 'Post Reversal và ghi movement append-only',
        'post api/v1/reversal-documents/{id}/cancel' => 'Huỷ Reversal khi còn DRAFT',
        'put api/v1/outbound-documents/{id}/items/{itemid}/rfid' => 'Gắn RFID cho item của phiếu xuất',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelid}/preview' => 'Preview Packing Label trước khi hoàn tất',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelid}/ready' => 'Đóng băng Packing Label sẵn sàng in',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelid}/print' => 'In Packing Label của phiếu xuất',
        'post api/v1/outbound-documents/{id}/packing-labels/{labelid}/reprint' => 'In lại Packing Label sau hoàn tất',
        // Annex C listDefects / createDefect / updateDefect + listCatalogMasterEntries.
        'get api/v1/defects' => 'Danh sách bệnh/lỗi bảo hành (Annex C)',
        'post api/v1/defects' => 'Tạo bệnh/lỗi bảo hành (Annex C)',
        'get api/v1/defects/{id}' => 'Chi tiết bệnh/lỗi bảo hành (Annex C)',
        'patch api/v1/defects/{id}' => 'Cập nhật bệnh/lỗi bảo hành (Annex C)',
        'get api/v1/catalog-masters/{mastertype}' => 'Danh sách catalog master theo loại (Annex C)',
        'post api/v1/catalog-masters/{mastertype}' => 'Tạo catalog master theo loại (Annex C)',
        'patch api/v1/catalog-masters/{mastertype}/{id}' => 'Cập nhật catalog master theo loại (Annex C)',
        'get api/v1/skus/import-template' => 'Tải file mẫu import SKU',
        'post api/v1/skus/import-excel' => 'Import SKU từ Excel',
        'post api/v1/skus/{id}/physical-codes' => 'Gán mã vật lý cho SKU',
        'delete api/v1/skus/{id}/physical-codes/{physicalCodeId}' => 'Thu hồi mã vật lý khỏi SKU',
    ];

    private const RESOURCE_LABELS = [
        'agencies' => 'đại lý',
        'brands' => 'thương hiệu',
        'component-issue-documents' => 'phiếu xuất linh kiện bảo hành',
        'containers' => 'container',
        'defect-catalogs' => 'danh mục lỗi bảo hành',
        'document-status-history' => 'lịch sử trạng thái chứng từ',
        'import-batches' => 'lô import',
        'inbound-documents' => 'phiếu nhập',
        'item-instances' => 'item instance',
        'label-print-events' => 'sự kiện in nhãn',
        'label-snapshots' => 'snapshot nhãn',
        'outbound-documents' => 'phiếu xuất',
        'outbound-request-imports' => 'import yêu cầu xuất',
        'outbound-requests' => 'toa yêu cầu xuất',
        'package-variants' => 'quy cách đóng gói',
        'physical-codes' => 'mã vật lý',
        'putaway' => 'tác vụ putaway',
        'power-sources' => 'nguồn điện',
        'printer-configs' => 'cấu hình máy in',
        'product-categories' => 'danh mục sản phẩm',
        'product-masters' => 'product master',
        'product-models' => 'model sản phẩm',
        'products' => 'sản phẩm/SKU',
        'skus' => 'SKU',
        'stock-movements' => 'giao dịch kho',
        'warranty-cases' => 'hồ sơ bảo hành',
        'warranty-attachments' => 'tệp đính kèm bảo hành',
    ];

    private const COLLECTION_NOUNS = [
        'agencies' => 'đại lý',
        'brands' => 'thương hiệu',
        'component-issue-documents' => 'phiếu xuất linh kiện bảo hành',
        'containers' => 'container',
        'defect-catalogs' => 'lỗi bảo hành',
        'document-status-history' => 'lịch sử trạng thái chứng từ',
        'import-batches' => 'lô import',
        'inbound-documents' => 'phiếu nhập',
        'item-instances' => 'item instance',
        'label-print-events' => 'sự kiện in nhãn',
        'label-snapshots' => 'snapshot nhãn',
        'outbound-documents' => 'phiếu xuất',
        'outbound-request-imports' => 'import yêu cầu xuất',
        'outbound-requests' => 'toa yêu cầu xuất',
        'package-variants' => 'quy cách đóng gói',
        'physical-codes' => 'mã vật lý',
        'putaway' => 'tác vụ putaway',
        'power-sources' => 'nguồn điện',
        'printer-configs' => 'cấu hình máy in',
        'product-categories' => 'danh mục sản phẩm',
        'product-masters' => 'product master',
        'product-models' => 'model sản phẩm',
        'products' => 'sản phẩm/SKU',
        'skus' => 'SKU',
        'stock-movements' => 'giao dịch kho',
        'warranty-cases' => 'hồ sơ bảo hành',
    ];

    private const COLLECTION_QUERY_PARAMETERS = [
        'agencies' => ['page', 'per_page', 'keyword', 'status'],
        'brands' => ['page', 'per_page', 'keyword', 'status'],
        'component-issue-documents' => ['page', 'per_page', 'keyword', 'status', 'warranty_case_id', 'warehouse_id'],
        'containers' => ['page', 'per_page', 'keyword', 'container_type', 'status', 'zone_code'],
        'defect-catalogs' => ['page', 'per_page', 'keyword', 'status'],
        'document-status-history' => ['page', 'per_page', 'keyword', 'status', 'stock_document_id'],
        'import-batches' => ['page', 'per_page', 'keyword', 'status'],
        'inbound-documents' => ['page', 'per_page', 'keyword', 'status'],
        'item-instances' => ['page', 'per_page', 'keyword', 'sku_id', 'item_status', 'item_condition', 'container_id'],
        'label-print-events' => ['page', 'per_page', 'label_snapshot_id', 'status'],
        'label-snapshots' => ['page', 'per_page', 'keyword', 'status'],
        'outbound-documents' => ['page', 'per_page', 'keyword', 'status'],
        'outbound-requests' => ['page', 'per_page', 'keyword', 'status'],
        'package-variants' => ['page', 'per_page', 'keyword', 'status'],
        'physical-codes' => ['page', 'per_page', 'keyword', 'code_type', 'carrier_type', 'status'],
        'putaway' => ['search'],
        'power-sources' => ['page', 'per_page', 'keyword', 'status'],
        'printer-configs' => ['page', 'per_page', 'keyword', 'status'],
        'product-categories' => ['page', 'per_page', 'keyword', 'status'],
        'product-masters' => ['page', 'per_page', 'keyword', 'master_type', 'status'],
        'product-models' => ['page', 'per_page', 'keyword', 'status'],
        'products' => ['page', 'per_page', 'keyword', 'status', 'sku_type'],
        'skus' => ['page', 'per_page', 'keyword', 'status', 'sku_type'],
        'stock-movements' => ['page', 'per_page', 'keyword', 'warehouse_id', 'product_id', 'movement_type'],
        'warranty-cases' => ['page', 'per_page', 'keyword', 'status', 'identification_status', 'defect_id'],
    ];

    private const PARAMETER_SCHEMAS = [
        'page' => ['type' => 'integer', 'default' => 1],
        'per_page' => ['type' => 'integer', 'default' => 15],
        'keyword' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'master_type' => ['type' => 'string', 'enum' => ['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER']],
        'sku_type' => ['type' => 'string', 'enum' => ['PRODUCT', 'COMPONENT']],
        'container_type' => ['type' => 'string', 'enum' => ['CARTON', 'BOX', 'TRAY']],
        'warehouse_id' => ['type' => 'string', 'format' => 'uuid'],
        'product_id' => ['type' => 'string', 'format' => 'uuid'],
        'stock_document_id' => ['type' => 'string', 'format' => 'uuid'],
        'sku_id' => ['type' => 'string', 'format' => 'uuid'],
        'container_id' => ['type' => 'string', 'format' => 'uuid'],
        'movement_type' => ['type' => 'string'],
        'code_type' => ['type' => 'string'],
        'carrier_type' => ['type' => 'string'],
        'zone_code' => ['type' => 'string'],
        'item_status' => ['type' => 'string'],
        'item_condition' => ['type' => 'string'],
        'label_snapshot_id' => ['type' => 'string', 'format' => 'uuid'],
        'warranty_case_id' => ['type' => 'string', 'format' => 'uuid'],
        'identification_status' => ['type' => 'string', 'enum' => ['IDENTIFIED', 'TEMP_ONLY']],
        'defect_id' => ['type' => 'string', 'format' => 'uuid'],
    ];

    public function __invoke(Analysis $analysis): void
    {
        $openApi = $analysis->openapi;
        if ($openApi === null) {
            return;
        }

        $routes = $this->apiRoutes();
        $paths = [];

        foreach (Undefined::isDefault($openApi->paths) ? [] : $openApi->paths as $pathItem) {
            if (Undefined::isDefault($pathItem->path)) {
                continue;
            }

            $path = (string) $pathItem->path;
            foreach (self::METHODS as $method) {
                if (Undefined::isDefault($pathItem->{$method})) {
                    continue;
                }

                if (! isset($routes[$this->routeKey($method, $path)])) {
                    $pathItem->{$method} = Undefined::UNDEFINED;
                }
            }

            if ($this->hasOperations($pathItem)) {
                $paths[$path] = $pathItem;
            }
        }

        foreach ($routes as $route) {
            $path = $route['path'];
            $method = $route['method'];

            if (! isset($paths[$path])) {
                $paths[$path] = new OA\PathItem([
                    'path' => $path,
                    '_context' => $this->context(),
                ]);
            }

            if (Undefined::isDefault($paths[$path]->{$method})) {
                $paths[$path]->{$method} = $this->operation($route);
            } else {
                $this->hydrateOperation($paths[$path]->{$method}, $route);
            }
        }

        ksort($paths);
        $openApi->tags = $this->tags();
        $openApi->paths = array_values($paths);
    }

    /** @return array<int, OA\Tag> */
    private function tags(): array
    {
        $tags = [];

        foreach (self::TAGS as $name => $description) {
            $tags[] = new OA\Tag([
                'name' => $name,
                'description' => $description,
                '_context' => $this->context(),
            ]);
        }

        return $tags;
    }

    /**
     * @return array<string, array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if ($method === 'head' || ! in_array($method, self::METHODS, true)) {
                    continue;
                }

                $path = '/'.$route->uri();
                $routes[$this->routeKey($method, $path)] = [
                    'method' => $method,
                    'path' => $path,
                    'uri' => $route->uri(),
                    'action' => $this->routeAction($route),
                    'middleware' => $route->gatherMiddleware(),
                ];
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * @param  array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}  $route
     */
    private function operation(array $route): OA\Operation
    {
        $class = match ($route['method']) {
            'get' => OA\Get::class,
            'post' => OA\Post::class,
            'put' => OA\Put::class,
            'patch' => OA\Patch::class,
            'delete' => OA\Delete::class,
            'options' => OA\Options::class,
            'head' => OA\Head::class,
            'trace' => OA\Trace::class,
        };

        $responses = [
            new OA\Response([
                'response' => 200,
                'description' => 'Thành công',
                'content' => $this->envelopeContent(self::SUCCESS_ENVELOPE_REF),
                '_context' => $this->context(),
            ]),
        ];
        foreach (self::ERROR_RESPONSES as $status => $description) {
            $responses[] = new OA\Response([
                'response' => $status,
                'description' => $description,
                'content' => $this->envelopeContent(self::ERROR_ENVELOPE_REF),
                '_context' => $this->context(),
            ]);
        }

        return new $class([
            'path' => $route['path'],
            'operationId' => $this->operationId($route),
            'summary' => $this->summary($route),
            'tags' => [$this->tag($route['uri'])],
            'security' => $this->requiresAuth($route['middleware']) ? [['bearerAuth' => []]] : [],
            'parameters' => $this->routeParameters($route),
            'requestBody' => $this->requestBody($route['method']),
            'responses' => $responses,
            '_context' => $this->context(),
        ]);
    }

    /**
     * Keep handwritten OpenAPI details such as requestBody/query schemas, while
     * enforcing route-derived grouping/security and baseline error responses.
     *
     * @param  array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}  $route
     */
    private function hydrateOperation(OA\Operation $operation, array $route): void
    {
        $operation->tags = [$this->tag($route['uri'])];
        $operation->security = $this->requiresAuth($route['middleware']) ? [['bearerAuth' => []]] : [];
        $operation->parameters = $this->mergeRouteParameters($operation->parameters, $route);
        if (Undefined::isDefault($operation->requestBody)) {
            $operation->requestBody = $this->requestBody($route['method']);
        }
        $operation->responses = $this->mergeBaselineResponses(
            $operation->responses,
            $this->declaresExhaustiveResponses($operation),
        );
    }

    /**
     * WWHN-295/DEV295-P1-02 — opt-out có kiểm soát của baseline error.
     *
     * Baseline 400/401/403/404/409/412/422/428/500 đúng cho endpoint nghiệp vụ có auth
     * và optimistic locking, nhưng SAI cho endpoint public không dùng token và không
     * đọc If-Match: `GET /api/v1/health` publish 401/403/412/428 khiến monitor và QA
     * phải đoán trạng thái nào là thật. Operation nào tự khai đủ tập response của mình
     * thì đánh dấu `x: ['exhaustive-responses' => true]`; mặc định vẫn là hành vi cũ,
     * nên không endpoint nào mất baseline chỉ vì thay đổi này.
     */
    private function declaresExhaustiveResponses(OA\Operation $operation): bool
    {
        if (Undefined::isDefault($operation->x) || ! is_array($operation->x)) {
            return false;
        }

        return ($operation->x['exhaustive-responses'] ?? false) === true;
    }

    private function requestBody(string $method): OA\RequestBody|string
    {
        if (! in_array($method, ['post', 'put', 'patch'], true)) {
            return Undefined::UNDEFINED;
        }

        return new OA\RequestBody([
            'required' => false,
            'content' => [
                new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => new OA\Schema([
                        'type' => 'object',
                        'additionalProperties' => true,
                        'example' => new \stdClass,
                        '_context' => $this->context(),
                    ]),
                    '_context' => $this->context(),
                ]),
            ],
            '_context' => $this->context(),
        ]);
    }

    /** @return array<int, OA\Parameter> */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return array_map(fn (string $name): OA\Parameter => new OA\Parameter([
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => new OA\Schema([
                'type' => 'string',
                '_context' => $this->context(),
            ]),
            '_context' => $this->context(),
        ]), $matches[1] ?? []);
    }

    /**
     * @param  array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}  $route
     * @return array<int, OA\Parameter>
     */
    private function routeParameters(array $route): array
    {
        return array_merge(
            $this->pathParameters($route['path']),
            $this->contractHeaders($route),
            $this->collectionQueryParameters($route),
        );
    }

    /**
     * Khai báo header contract (Idempotency-Key / If-Match) theo ĐÚNG hành vi
     * runtime của controller, thay vì trông chờ mỗi controller nhớ khai tay.
     *
     * WWHN-309: trước đây chỉ 4/20 operation dùng IdempotencyKeyGuard và 7/24
     * operation kiểm If-Match có khai header trong OpenAPI. FE/QA đọc contract
     * không thấy header bắt buộc nên gặp 428 bất ngờ khi tích hợp. Đọc ngược từ
     * source của action là cách duy nhất giữ contract không lệch runtime.
     *
     * @param  array{method: string, path: string, uri: string, action: string, middleware: array<int, string>}  $route
     * @return array<int, OA\Parameter>
     */
    private function contractHeaders(array $route): array
    {
        $flags = $this->actionContractFlags($route['action']);
        $headers = [];

        if ($flags['idempotency'] !== null) {
            $required = $flags['idempotency'] === 'required';
            $headers[] = new OA\Parameter([
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => $required,
                'description' => $required
                    ? 'Bắt buộc. Chuỗi 8-100 ký tự do client sinh. Gửi lại cùng key trả về kết quả lần đầu (replay); dùng lại key cho payload khác trả 409.'
                    : 'Tuỳ chọn. Nếu gửi thì vẫn được kiểm độ dài 8-100 và đi qua cơ chế replay/conflict.',
                'schema' => new OA\Schema([
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 100,
                    '_context' => $this->context(),
                ]),
                '_context' => $this->context(),
            ]);
        }

        if ($flags['if_match'] !== false) {
            $ifMatchRequired = $flags['if_match'] === 'required';
            $headers[] = new OA\Parameter([
                'name' => 'If-Match',
                'in' => 'header',
                'required' => $ifMatchRequired,
                'description' => $ifMatchRequired
                    ? 'Bắt buộc. Optimistic locking theo cột version của resource. Thiếu header: 428 PRECONDITION_REQUIRED. Sai version: 412 STALE_VERSION.'
                    : 'Tuỳ chọn. Nếu gửi thì được kiểm optimistic locking theo cột version của resource; sai version: 412 STALE_VERSION.',
                'schema' => new OA\Schema([
                    'type' => 'integer',
                    'minimum' => 0,
                    '_context' => $this->context(),
                ]),
                '_context' => $this->context(),
            ]);
        }

        return $headers;
    }

    /**
     * Phân tích tĩnh 1 cấp: thân method của action, cộng thân các private helper
     * cùng class mà action gọi qua $this->...(). Đủ để bắt các pattern hiện hành
     * (assertVersion/assertVersionIfProvided/expectedVersion) mà không cần dựng
     * call graph đầy đủ.
     *
     * @return array{idempotency: ?string, if_match: false|'required'|'optional'}
     */
    private function actionContractFlags(string $action): array
    {
        static $cache = [];

        if (isset($cache[$action])) {
            return $cache[$action];
        }

        $flags = ['idempotency' => null, 'if_match' => false];

        if (! str_contains($action, '@')) {
            return $cache[$action] = $flags;
        }

        [$class, $method] = explode('@', $action, 2);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $cache[$action] = $flags;
        }

        $source = $this->methodSource($class, $method);
        if ($source === null) {
            return $cache[$action] = $flags;
        }

        // WWHN-249 (DEV249-P1-06): quét ĐỆ QUY theo chuỗi `$this->helper()` thay vì
        // chỉ một tầng. PvAdminResourceController::update() gọi updateSimpleMaster()
        // rồi mới tới assertIfMatch(), nên với cách quét một tầng header If-Match
        // bắt buộc của updatePvAdminResource không được khai trong OpenAPI và ma
        // trận endpoint — FE/QA đọc contract sẽ tưởng PATCH không cần If-Match rồi
        // ăn 428 lúc chạy thật.
        //
        // Phải đi theo cả `parent::action()`: adapter mỏng kiểu
        // BaM1OutboundFlowController::postIssue() chỉ `return parent::postIssue(...)`,
        // nên quét-một-lớp đọc ra "không chạm If-Match/Idempotency-Key" trong khi
        // runtime vẫn ném 428 — OpenAPI công bố sai đúng bốn action đổi tồn
        // (cùng lớp lỗi BE266-D01, tái phát ở tầng kế thừa thay vì tầng helper).
        $seen = [$class.'::'.$method => true];
        $pending = [[$class, $source]];
        // Giữ RIÊNG từng thân method bên cạnh bản nối, vì phép kiểm If-Match bên
        // dưới cần biết hai dấu hiệu có nằm trong CÙNG một thân hay không.
        $bodies = [$source];
        while ($pending !== []) {
            [$currentClass, $current] = array_pop($pending);

            $targets = [];
            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $current, $thisCalls);
            foreach (array_unique($thisCalls[1] ?? []) as $helper) {
                $targets[] = [$currentClass, $helper];
            }
            $parentClass = get_parent_class($currentClass);
            if ($parentClass !== false) {
                preg_match_all('/parent::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $current, $parentCalls);
                foreach (array_unique($parentCalls[1] ?? []) as $inherited) {
                    $targets[] = [$parentClass, $inherited];
                }
            }

            foreach ($targets as [$targetClass, $targetMethod]) {
                $key = $targetClass.'::'.$targetMethod;
                if (isset($seen[$key]) || ! method_exists($targetClass, $targetMethod)) {
                    continue;
                }
                $seen[$key] = true;
                $helperSource = $this->methodSource($targetClass, $targetMethod);
                if ($helperSource === null) {
                    continue;
                }
                $source .= "\n".$helperSource;
                $bodies[] = $helperSource;
                $pending[] = [$targetClass, $helperSource];
            }
        }

        if (str_contains($source, 'IdempotencyKeyGuard::require')) {
            $flags['idempotency'] = 'required';
        } elseif (str_contains($source, 'IdempotencyKeyGuard::optional')) {
            $flags['idempotency'] = 'optional';
        }

        if (preg_match("/header\(\s*'If-Match'/", $source) === 1) {
            // WWHN-266 (BE266-D01): phân biệt BẮT BUỘC vs TUỲ CHỌN thay vì chỉ
            // "action có đọc header". Action ném 428 PRECONDITION_REQUIRED khi
            // thiếu header (pattern `expectedVersion()`) là bắt buộc; action chỉ
            // kiểm khi client có gửi (pattern `assertVersionIfProvided()`,
            // `assertVersionMatches()`) mới là tuỳ chọn. Trước đây cả hai đều
            // publish `required: false`: trong 47 operation bắt buộc If-Match lúc
            // chạy, chỉ 6 operation (nhóm inbound khai tay) công bố đúng — 41
            // operation còn lại nói sai, gồm cả bốn action đổi tồn. Đúng lỗi mà
            // WWHN-309 đã sửa cho Idempotency-Key nhưng bỏ sót ở đây.
            //
            // Điều kiện phải là ĐỒNG HIỆN DIỆN TRONG CÙNG MỘT THÂN METHOD, không
            // phải trên bản nối: trait ApiResponse có map `428 =>
            // 'PRECONDITION_REQUIRED'` và mọi action gọi respondSuccess() đều kéo
            // trait đó vào, nên kiểm trên bản nối sẽ báo nhầm mọi endpoint là bắt
            // buộc (đo được: UserController::update() chỉ kiểm version khi client
            // có gửi, nhưng bị xếp nhầm nhóm bắt buộc).
            $flags['if_match'] = 'optional';
            foreach ($bodies as $body) {
                if (preg_match("/header\(\s*'If-Match'/", $body) === 1 && str_contains($body, 'PRECONDITION_REQUIRED')) {
                    $flags['if_match'] = 'required';
                    break;
                }
            }
        }

        return $cache[$action] = $flags;
    }

    private function methodSource(string $class, string $method): ?string
    {
        static $files = [];

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $file = $reflection->getFileName();
        if ($file === false || $reflection->getStartLine() === false) {
            return null;
        }

        $files[$file] ??= file($file) ?: [];
        $lines = array_slice(
            $files[$file],
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );

        return implode('', $lines);
    }

    /**
     * @param  array{method: string, uri: string}  $route
     * @return array<int, OA\Parameter>
     */
    private function collectionQueryParameters(array $route): array
    {
        if ($route['method'] !== 'get' || str_contains($route['uri'], '{')) {
            return [];
        }

        $names = self::COLLECTION_QUERY_PARAMETERS[$this->resourceName($route['uri'])] ?? [];

        return array_map(fn (string $name): OA\Parameter => $this->queryParameter($name), $names);
    }

    private function queryParameter(string $name): OA\Parameter
    {
        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'schema' => new OA\Schema((self::PARAMETER_SCHEMAS[$name] ?? ['type' => 'string']) + [
                '_context' => $this->context(),
            ]),
            '_context' => $this->context(),
        ]);
    }

    private function mergeRouteParameters(mixed $parameters, array $route): array
    {
        $merged = Undefined::isDefault($parameters) ? [] : (array) $parameters;
        $existing = [];

        foreach ($merged as $parameter) {
            if ($parameter instanceof OA\Parameter && ! Undefined::isDefault($parameter->name) && ! Undefined::isDefault($parameter->in)) {
                $existing[$parameter->in.':'.$parameter->name] = true;
            }
        }

        foreach ($this->routeParameters($route) as $parameter) {
            if (! isset($existing[$parameter->in.':'.$parameter->name])) {
                $merged[] = $parameter;
            }
        }

        return $merged;
    }

    private function mergeBaselineResponses(mixed $responses, bool $exhaustive = false): array
    {
        $merged = Undefined::isDefault($responses) ? [] : (array) $responses;
        $existing = [];

        foreach ($merged as $response) {
            if ($response instanceof OA\Response && ! Undefined::isDefault($response->response)) {
                $existing[(string) $response->response] = true;
                // Response viết tay trong controller thường chỉ có description.
                // Bổ sung envelope schema khi CHƯA có content để spec luôn mô tả
                // được hình dạng payload; nếu controller đã khai content riêng
                // thì giữ nguyên, không ghi đè contract chi tiết.
                if (Undefined::isDefault($response->content)) {
                    $status = (string) $response->response;
                    $response->content = $this->envelopeContent(
                        str_starts_with($status, '2') ? self::SUCCESS_ENVELOPE_REF : self::ERROR_ENVELOPE_REF
                    );
                }
            }
        }

        if ($exhaustive) {
            return $merged;
        }

        foreach ([200 => 'Thành công'] + self::ERROR_RESPONSES as $status => $description) {
            if (! isset($existing[(string) $status])) {
                $merged[] = new OA\Response([
                    'response' => $status,
                    'description' => $description,
                    'content' => $this->envelopeContent($status === 200 ? self::SUCCESS_ENVELOPE_REF : self::ERROR_ENVELOPE_REF),
                    '_context' => $this->context(),
                ]);
            }
        }

        return $merged;
    }

    /**
     * Gắn envelope chuẩn vào các baseline response do processor tự sinh.
     *
     * Chỉ áp dụng cho response KHÔNG do controller khai tay: response viết tay
     * đã có content riêng thì mergeBaselineResponses() bỏ qua, nên contract chi
     * tiết của endpoint không bị processor ghi đè.
     *
     * @return array<int, OA\MediaType>
     */
    private function envelopeContent(string $ref): array
    {
        return [
            new OA\MediaType([
                'mediaType' => 'application/json',
                'schema' => new OA\Schema([
                    'ref' => $ref,
                    '_context' => $this->context(),
                ]),
                '_context' => $this->context(),
            ]),
        ];
    }

    private function hasOperations(OA\PathItem $pathItem): bool
    {
        foreach (self::METHODS as $method) {
            if (! Undefined::isDefault($pathItem->{$method})) {
                return true;
            }
        }

        return false;
    }

    private function routeAction(LaravelRoute $route): string
    {
        $action = $route->getActionName();

        return $action === 'Closure' ? $route->uri() : $action;
    }

    /**
     * OpenAPI yêu cầu operationId duy nhất. Gạch ngang trong tên resource phải
     * sinh ra id khác với dấu "/" — nếu không `putaway-tasks` (alias Annex C) và
     * `putaway/tasks` (route hiện hành) sẽ cùng ra `get_api_v1_putaway_tasks`.
     *
     * @param  array{method: string, uri: string, action: string}  $route
     */
    private function operationId(array $route): string
    {
        $base = strtolower($route['method'].'_'.$route['uri']);
        $base = str_replace('-', '__', $base);
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?? $base;

        return trim($base, '_');
    }

    /** @param array{method: string, uri: string, action: string} $route */
    private function summary(array $route): string
    {
        $key = strtolower($route['method'].' '.$route['uri']);
        if (isset(self::ROUTE_SUMMARIES[$key])) {
            return self::ROUTE_SUMMARIES[$key];
        }

        $friendly = $this->resourceSummary($route['method'], $route['uri']);
        if ($friendly !== null) {
            return $friendly;
        }

        $action = str_replace(['\\', '@'], [' ', ' '], $route['action']);
        $action = preg_replace('/\s+/', ' ', $action) ?? $action;

        return strtoupper($route['method']).' /'.$route['uri'].' - '.$action;
    }

    private function resourceSummary(string $method, string $uri): ?string
    {
        $relativeUri = preg_replace('#^api/v1/#', '', $uri) ?? $uri;
        $parts = explode('/', $relativeUri);
        $resource = $parts[0] ?? '';

        if (! isset(self::RESOURCE_LABELS[$resource])) {
            return null;
        }

        $label = self::RESOURCE_LABELS[$resource];
        $collectionNoun = self::COLLECTION_NOUNS[$resource] ?? $label;
        $last = $parts[array_key_last($parts)] ?? '';
        $hasId = in_array('{id}', $parts, true);

        if ($last === 'cancel') {
            return 'Huỷ '.$label;
        }

        if ($last === 'enable') {
            return 'Kích hoạt '.$label;
        }

        if ($last === 'disable') {
            return 'Ngừng kích hoạt '.$label;
        }

        if ($method === 'get' && $hasId) {
            return 'Chi tiết '.$label;
        }

        return match ($method) {
            'get' => 'Danh sách '.$collectionNoun,
            'post' => 'Tạo '.$label.' mới',
            'patch', 'put' => 'Cập nhật '.$label,
            'delete' => 'Xoá '.$label,
            default => null,
        };
    }

    private function tag(string $uri): string
    {
        // Mini App is an adapter surface with its own client lifecycle. Keep it
        // separate from canonical Web WMS inbound routes in Swagger even though
        // the adapter delegates stock mutations to the same inbound domain.
        if (str_starts_with($uri, 'api/v1/mini-app/')) {
            return '22. Mini App';
        }

        $parts = explode('/', $uri);
        $resource = $parts[2] ?? 'platform';

        return match ($resource) {
            'auth' => '01. Authentication',
            'users' => '02. Users',
            'roles' => '03. Roles',
            'permissions' => '04. Permissions',
            'products', 'skus', 'product-masters' => '05. Product',
            'warehouses' => '06. Warehouse',
            'inventory', 'stock-movements', 'document-status-history', 'reversal-documents' => '07. Inventory',
            'brands', 'product-categories', 'product-models', 'power-sources', 'package-variants', 'catalog-masters' => '08. Catalog Master',
            'inbound-documents' => '09. Stock In (Inbound)',
            'outbound-documents', 'outbound-request-imports', 'outbound-requests' => '10. Stock Out (Outbound)',
            'scan', 'scan-batches' => '11. Scan',
            'reports' => '12. Dashboard / Report',
            'warranty-cases', 'warranty-attachments' => '13. Warranty',
            'component-issue-documents', 'warranty-component-issues' => '14. Component Issue',
            'audit-logs' => '15. Audit Log',
            'agencies' => '16. Agency',
            'defect-catalogs', 'defects' => '17. Defect Catalog',
            'containers', 'item-instances', 'physical-codes', 'import-batches' => '18. Physical Inventory',
            'label-snapshots', 'printer-configs', 'label-print-events' => '19. Label / Printer',
            'pv', 'pv-admin' => '21. Public Catalog',
            'putaway', 'putaway-tasks' => '24. Putaway',
            default => '00. Platform',
        };
    }

    /** @param array<int, string> $middleware */
    private function requiresAuth(array $middleware): bool
    {
        return collect($middleware)->contains(fn (string $item): bool => str_starts_with($item, 'auth:'));
    }

    private function routeKey(string $method, string $path): string
    {
        return strtolower($method).' '.$path;
    }

    private function resourceName(string $uri): string
    {
        $parts = explode('/', $uri);

        return $parts[2] ?? '';
    }

    private function context(): Context
    {
        return new Context(['generated' => true]);
    }
}
