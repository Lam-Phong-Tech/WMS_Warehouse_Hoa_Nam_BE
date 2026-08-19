# Đặc tả task dọn dẹp lớp legacy không active (WWHN-190 / DEV190-P2-01R2)

| Trường | Giá trị |
|---|---|
| Nguồn | Register item `DEV190-P2-01R2` — "tạo cleanup/deprecation task cho inactive legacy controller/service" |
| Trạng thái | **CHỜ BA/PM TẠO JIRA KEY** (con của WWHN-43, hoặc tech-debt backlog) |
| Jira key | `____________` ← điền sau khi tạo, rồi cập nhật lại file này |
| Đề xuất loại | Tech Debt / Refactor — **không** gộp vào WWHN-190 |
| Người soạn | DEV (WWHN-190), 2026-08-19 |

## 1. Vì sao tách task riêng

`WWHN-190` bị ràng buộc "không xoá code trong phạm vi remediation". Việc gỡ lớp legacy chạm
nhiều bounded context (inbound / outbound / transfer) và chạm generator OpenAPI, nên phải có
regression scope + owner riêng, không làm lén trong ticket posting.

## 2. Hiện trạng (đã kiểm bằng đọc route + source, không suy đoán)

| Lớp | Route thật | Vai trò còn lại |
|---|---|---|
| `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` | **CÓ** cho `index()`/`show()` (`GET /api/v1/inbound-documents`, `GET /api/v1/inbound-documents/{id}`) — hai route ĐỌC | Các annotation OpenAPI cho create/scan/post/reverse trong file là **tài liệu thuần tuý**, không có handler nào được route tới |
| `Modules/Warehouse/app/Services/StockDocumentService.php` | **KHÔNG** | Được inject vào constructor của `InboundDocumentController` / `OutboundDocumentController` / `TransferController` nhưng **không method ghi nào được gọi**; container vẫn khởi tạo mỗi request |

Toàn bộ hành động ghi thật đi qua `CurrentBaselineDocumentController` /
`CurrentBaselineStockFlowController` / `AlignedOutboundFlowController` +
`CurrentBaselineStockService`.

Rủi ro thực tế: dev sau này sửa `StockDocumentService` hoặc annotation trong
`InboundDocumentController` và tưởng đã đổi hành vi runtime — trong khi không có gì đổi.
Đây đúng là loại nhầm lẫn mà review gọi là "maintainability".

## 3. Scope đề xuất cho task mới

1. Gỡ `StockDocumentService` khỏi constructor của 3 controller (`InboundDocumentController`,
   `OutboundDocumentController`, `TransferController`) nếu không còn method nào được gọi;
   giữ hoặc xoá class tuỳ kết quả grep cuối cùng.
2. Với các annotation OpenAPI "chỉ để tài liệu" trong `InboundDocumentController`: chuyển sang
   lớp Swagger chuyên trách (`app/Swagger/*`, đúng pattern đã dùng cho Flow06/Reversal) để file
   controller chỉ còn phần có route thật.
3. Đánh dấu `@deprecated` + ghi rõ "no runtime write path" cho bất kỳ lớp legacy nào chưa xoá được.
4. Cập nhật `docs/docs_task_jira/WWHN-190.md` mục 10.e sau khi dọn xong.

## 4. Acceptance Criteria đề xuất

- `php artisan route:list --json` **trước/sau** giống hệt nhau (không mất/không thêm route nào).
- `storage/api-docs/api-docs.json` sinh lại không mất operation nào đang được FE dùng
  (so bằng danh sách `operationId`).
- Test xanh: toàn bộ suite sqlite hiện hành + `Wwhn190PostingContractParityTest` +
  gate `.github/workflows/wwhn-190-posting-evidence-gate.yml`.
- `grep -rn "StockDocumentService" app/ Modules/` chỉ còn kết quả hợp lệ (hoặc rỗng nếu xoá hẳn).

## 5. Không thuộc scope

- Không đụng `CurrentBaselineStockFlowController` / `CurrentBaselineStockService` (đường chạy thật).
- Không đổi API contract; nếu buộc phải đổi thì phải qua release contract mới như
  `docs/api-contract/releases/WWHN-190/1.8.0/`.
- Không đụng pack immutable WWHN-180 v1.7.0 (DISP-STK-236 §3).

## 6. Ghi chú công cụ

Phiên làm WWHN-190 **không tạo được Jira key**: MCP `atlassian` trong phiên này chưa được
authorize (non-interactive), nên không gọi được API tạo issue. BA/PM tạo key rồi điền vào bảng đầu file.
