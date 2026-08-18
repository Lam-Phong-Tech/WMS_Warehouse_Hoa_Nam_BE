[DEV] Triển khai luồng hoàn chỉnh nhập kho

Description

Mục tiêu
Triển khai luồng hoàn chỉnh nhập kho.

Input đầu vào
Task cha: WWHN-49 — [FE][INBOUND] UI chứng từ, quét mã, ghi nhận nhập kho và putaway

Dependency: HN-IN-001; HN-IN-002; HN-IN-003; HN-FE-001

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: FE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, UI/UX, field specification, Acceptance Criteria và điểm chưa chốt đã được xử lý.

Nhận từ BE1/BE2: endpoint, payload, validation, error code, permission và dữ liệu mẫu; nhận cấu hình build/environment từ DevOps nếu có dependency.

Phản hồi ngay contract mismatch hoặc thiếu UI trạng thái; không tự suy diễn quy tắc nghiệp vụ hay sửa API contract.

Bàn giao cho BA/QA: UI đã tích hợp, evidence responsive/RBAC/UI trạng thái, kết quả lint/build/test và blocker còn lại.

Source & Traceability
Parent Temp Key: HN-FE-003

Epic Temp Key: HN-EPIC-05

Dependency: HN-IN-001; HN-IN-002; HN-IN-003; HN-FE-001

Role: FE1

Assignee Placeholder: HN-FE-01

Acceptance Criteria
Output "Inbound FE" được rà soát/xác minh, gắn evidence vào HN-FE-003 và không còn blocker thuộc vai trò FE1.

UI đáp ứng quy tắc nghiệp vụ, field specification và responsive; xử lý loading, empty, error, success, disabled và confirm khi phù hợp.

Permission/RBAC và API error hiển thị đúng; không hard-code dữ liệu hoặc quy tắc trái contract.

Tích hợp đúng endpoint/payload đã duyệt; lint, build và test phù hợp phải pass.

BA/QA có evidence để kiểm tra; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Inbound FE

Definition of Done
Code, review, lint/build/test, evidence UI và traceability hoàn tất.

Contract mismatch đã được xử lý hoặc có disposition; tài liệu component/build được cập nhật khi cần.

BA/QA có thể kiểm tra theo Acceptance Criteria; không còn blocker chưa có owner và ETA.

Phạm vi thực hiện
Thực hiện đúng Mục tiêu, Input đầu vào, Source & Traceability, Acceptance Criteria và Output đầu ra.

Bảo toàn baseline, contract, dependency và evidence; không tự ý mở rộng scope hoặc thay đổi reference.

Thay đổi ảnh hưởng API, UI, data hoặc CI/CD phải được trace và review trước khi áp dụng.

Thuật ngữ / technical terms giữ nguyên
API, endpoint, payload, field, enum, table, schema, branch, environment, status, label, UI, RBAC, OpenAPI, CI/CD, UAT và tên kỹ thuật trong phạm vi.

Parent key, Epic key, output name, role và assignee placeholder được giữ nguyên để bảo toàn traceability.

Lưu ý cho DEV
Làm đúng vai trò và phạm vi bàn giao; nếu thiếu input/dependency, phải comment blocker kèm owner và ETA.

Không thay thế hoặc xóa baseline/reference; khác biệt giữa code, API, UI và data phải được nêu trong PR/evidence.

Lưu ý cho BA/QA
Xác nhận Acceptance Criteria, evidence, dependency và open point theo baseline.

Đánh dấu rõ nội dung cần formal sign-off, rủi ro hiểu sai hoặc contract mismatch trước khi nghiệm thu.

---

## Kết quả rà soát (Evidence — cập nhật 2026-08-15)

**Ghi chú traceability quan trọng — đọc trước khi dùng file này**: Task gốc (HN-FE-003, output "Inbound FE") yêu cầu triển khai **React/Vite frontend** theo `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` (cấu trúc `src/modules/inbound/...`). Repo đang thao tác (`Hoa_Nam_Backend`) là **thuần Laravel backend** — không tồn tại thư mục `src/`, không có `package.json` React/Vite, không có repo FE nào khác nằm cùng cấp trên máy. Do đó, theo lựa chọn trực tiếp của người yêu cầu (xác nhận qua câu hỏi làm rõ phạm vi), rà soát này **chuyển hướng sang phía Backend**: xác minh toàn bộ API/luồng nghiệp vụ nhập kho (chứng từ, quét mã, post-receipt, putaway) mà một FE trong tương lai sẽ tích hợp — tương đương lớp "API contract readiness" đứng trước công việc FE thật. Phần UI/RBAC-hiển-thị/responsive/lint FE trong Acceptance Criteria gốc **không thể thực hiện** vì không có mã nguồn FE trong phạm vi truy cập hiện tại — đây là gap đã biết, cần BA/PM xác nhận vị trí repo FE thật (nếu có) để giao task tiếp theo.

**Exact commit SHA khi bắt đầu rà soát**: `fad4242` (`[DEV] Triển khai trạng thái/thao tác putaway`, HEAD của `m1` tại thời điểm bắt đầu).

**Phạm vi đã rà soát**: toàn bộ luồng nhập kho phía Backend trong `Modules/Warehouse/` — chứng từ (`inbound-documents`), đăng ký BOX cho SKU COMPONENT, quét mã (`scan`), ghi nhận nhập kho (`post-receipt`), putaway (`putaway/tasks`, `confirm`, `cancel`, `history`), và reverse (`reverse`).

### 1. Khảo sát route/controller/service/middleware/permission (đọc toàn bộ, không chỉ preview)

Route thực thi xác nhận qua `php artisan route:list` và đọc trực tiếp `Modules/Warehouse/routes/api.php`, `governance.php`:

| Method | Path | Controller@action active | Permission |
|---|---|---|---|
| GET | `/inbound-documents` | `InboundDocumentController::index` | `inbound.view` |
| POST | `/inbound-documents` | `CurrentBaselineDocumentController::storeInbound` | `inbound.create` + `EnsureCanonicalInboundWarehouse` |
| GET | `/inbound-documents/{id}` | `InboundDocumentController::show` | `inbound.view` |
| PATCH | `/inbound-documents/{id}` | `CurrentBaselineDocumentController::updateInbound` | `inbound.create` + `EnsureCanonicalInboundWarehouse` |
| POST | `/inbound-documents/{id}/cancel` | `CurrentBaselineDocumentController::cancelInbound` | `inbound.cancel\|inbound.create` (route nhận cả hai, xem DR_HN_20260817_FLOW02_OPEN_DECISIONS.md) |
| POST | `/inbound-documents/{id}/boxes` | `CurrentBaselineInboundBoxController::store` | `inbound.scan` |
| POST | `/inbound-documents/{id}/scan` | `CurrentBaselineStockFlowController::inboundScan` | `inbound.scan` + `EnsureInboundComponentBox` |
| POST | `/inbound-documents/{id}/post-receipt` | `CurrentBaselineStockFlowController::postReceipt` | `inbound.post` |
| POST | `/inbound-documents/{id}/reverse` | `CurrentBaselineStockFlowController::reverseInbound` | `inventory.reversal.post` |
| GET | `/putaway/tasks` | `PutawayController::tasks` | `container.view` |
| POST | `/putaway/tasks` | `CurrentBaselinePutawayTaskController::store` | `container.manage` |
| POST | `/putaway/tasks/{id}/confirm` | `PutawayController::confirm` | `container.manage` |
| POST | `/putaway/tasks/{id}/cancel` | `PutawayController::cancel` | `container.manage` |
| GET | `/putaway/history` | `PutawayController::history` | `container.view` |
| POST | `/scan/classify` | `ScanController::classify` (route đăng ký ở `governance.php`, không phải `api.php`) | `scan.classify` |

Toàn bộ endpoint dùng chung idempotency (`Idempotency-Key` bắt buộc cho post-receipt/reverse), optimistic concurrency (`If-Match` bắt buộc cho update/scan/post/putaway confirm/cancel), và audit log (`AuditLogger::record`) cho mọi mutation.

**Điểm ban đầu nghi ngờ là gap nhưng đã xác minh KHÔNG phải bug** (ghi lại để tránh điều tra trùng lặp trong tương lai):
- `POST /api/v1/scan/classify` — ban đầu tưởng thiếu route vì không thấy trong `api.php`; xác nhận route có thật, đăng ký ở `Modules/Warehouse/routes/governance.php:35-36`.
- `PutawayController::confirm()` cho phép BOX ở cả `RECEIVED_PENDING_PUTAWAY` và `STORED` khi confirm, trong khi `CurrentBaselinePutawayTaskController::store()` (tạo DRAFT) chỉ chấp nhận `RECEIVED_PENDING_PUTAWAY` — xác nhận đây là chủ đích thiết kế (docblock class giải thích rõ: initial putaway strict, relocation của box STORED là nghiệp vụ riêng chưa mở); `Wwhn193PutawayLifecycleTest::test_putaway_confirm_twice_is_rejected...` xác nhận DRAFT không bao giờ được tạo cho box STORED nên nhánh "confirm cho STORED" chỉ là phòng thủ, không active trong thực tế.
- `assertBoxPutawayEligible($box, $allowDraftTarget)` trong `PutawayController.php:506-517` — tham số `$allowDraftTarget` không thay đổi kết quả (cả 2 nhánh if/else trả cùng mảng `['RECEIVED_PENDING_PUTAWAY', 'STORED']`). Đây là code smell (tham số chết) nhưng không gây sai hành vi — **không sửa** trong task này để tránh mở rộng phạm vi ngoài rà soát; ghi nhận cho task dọn dẹp code riêng nếu BA/Tech Lead muốn.
- `CurrentBaselineStockService::mutateBalance()` dùng `where('sku_id', $x)->orWhere('product_id', $x)` lồng trong closure — đã xác nhận bằng cách in SQL thật (`->toSql()`): Laravel bọc đúng thành `AND (sku_id = ? OR product_id = ?) AND ...`, không phá vỡ điều kiện `warehouse_id`/`stock_status` xung quanh.
- `CanonicalScanClassificationService extends ScanClassificationService` — không phải trùng lặp có hại; đây là DI binding pattern chủ đích (`WarehouseServiceProvider.php:80`: `bind(ScanClassificationService::class, CanonicalScanClassificationService::class)`), lớp con chỉ override fallback cho `products.code` trên Postgres.

**Dead code đã biết từ trước (WWHN-190), xác nhận lại còn tồn tại, KHÔNG xử lý (ngoài phạm vi rà soát này)**: `InboundDocumentController` (chỉ giữ OpenAPI annotation cho index/show, phần index/show thật vẫn active qua trait `InteractsWithStockDocuments` nhưng mọi write action không active), `StockDocumentService` (không dùng bởi route thật), `PutawayController::registerInboundBox()`/`storeTask()` (route trỏ vào `CurrentBaselineInboundBoxController`/`CurrentBaselinePutawayTaskController`, không phải các method này), `StoreInboundDocumentRequest`/`UpdateInboundDocumentRequest` (không được inject ở đâu, route dùng inline `$request->validate()` với rule set khác). WWHN-190 đã khuyến nghị dọn dẹp trong task riêng — khuyến nghị này vẫn còn hiệu lực, không lặp lại xử lý ở đây để tránh sửa ngoài scope "Inbound FE"/rà soát nhập kho.

### 2. Phát hiện gap thật: thiếu test HTTP end-to-end cho reverse-inbound

Rà soát 4 file test hiện có (`FoundationInboundPutawayReadinessTest.php`, `InboundCanonicalListContractTest.php`, `InboundExpectedCorrectionContractTest.php`, `Wwhn193PutawayLifecycleTest.php` — tổng 946 dòng, ~35 test case) cho thấy coverage rất mạnh cho create → scan → post-receipt → putaway (draft/confirm/cancel/history), nhưng **`POST /inbound-documents/{id}/reverse` chỉ được xác nhận tồn tại ở mức route-surface tĩnh** (`DocumentationBaselineContractTest.php`, kiểm tra string khớp controller/permission, không gọi HTTP thật) — không có test nào lái qua flow reverse thật.

Theo đúng phương pháp đã dùng ở WWHN-190/191/202 (BE2) — nơi việc test bằng HTTP thật thay vì chỉ đọc code đã phát hiện bug thật ở phía outbound — đã viết test mới `tests/Feature/Wwhn207InboundReversalFlowTest.php` (6 test case, lái qua flow thật `POST /inbound-documents` → `/boxes` → `/scan` → `/post-receipt` → `/reverse`) để xác minh `CurrentBaselineReversalService::execute()` (service active thật cho reverse, **khác** với `CurrentBaselineStockService::reverseStockDocument()` — service đó không được route gọi tới, cũng là dead code chưa từng ghi nhận trước đây, xem mục 3):

| Test case | Mục tiêu xác minh |
|---|---|
| `test_reverse_inbound_partial_quantity_decreases_balance_and_creates_reversal_movement` | Reverse 2/5 giảm đúng balance còn 3, tạo `stock_movements` REVERSAL với `qty_delta=-2`, original document giữ nguyên POSTED |
| `test_reverse_inbound_full_quantity_then_second_reverse_is_rejected` | Reverse hết 3/3 → balance về 0; reverse thêm bị chặn `REVERSAL_QTY_EXCEEDED`, không tạo thêm movement, không âm tồn |
| `test_reverse_inbound_replays_idempotently_without_double_effect` | Replay cùng `Idempotency-Key` trả `Idempotent-Replay: true`, cùng `reversal_document_id`, không trừ tồn thêm lần 2 |
| `test_reverse_inbound_rejects_quantity_above_reversible_and_invalid_movement` | `quantity` vượt reversible → `REVERSAL_QTY_EXCEEDED`; `movement_id` không thuộc document → `ORIGINAL_MOVEMENT_INVALID`; balance không đổi |
| `test_reverse_inbound_requires_reversal_post_permission` | Thiếu `inventory.reversal.post` → 403 `ACCESS_DENIED`, không tạo `reversal_document` |
| `test_reverse_inbound_requires_idempotency_key_and_if_match` | Thiếu `If-Match` hoặc `Idempotency-Key` → 428 `PRECONDITION_REQUIRED` |

**Kết quả**: `php artisan test tests/Feature/Wwhn207InboundReversalFlowTest.php` → **6/6 passed, 59 assertions** ngay lần chạy đầu tiên. Khác với các task rà soát outbound trước đó, **không phát hiện bug chức năng nào** trong `CurrentBaselineReversalService` cho nhánh receipt/COMPONENT (quantity-mode) — logic partial/full reverse, idempotency, validation quantity/movement, permission và header guard đều đúng theo baseline. File test mới đã qua `vendor/bin/pint --test` (pass, sau 1 lần auto-fix unused import).

### 3. Ghi nhận thêm (không xử lý — ngoài phạm vi, cần BA/Tech Lead quyết định)

- `CurrentBaselineStockService::reverseStockDocument()` (dòng 210-370 của `CurrentBaselineStockService.php`) là một **service reverse thứ hai, song song, không được route nào gọi tới** — route thật `POST /inbound-documents/{id}/reverse` đi qua `CurrentBaselineReversalService::execute()` (khác class, khác implementation, đọc kỹ ở mục 2). Đây là dead code trùng lặp **chưa từng được WWHN-190/191 ghi nhận trước đây** (2 task đó tập trung vào `scanStockDocument()`/`postStockDocument()`, không đọc sâu `reverseStockDocument()`). Khuyến nghị: BA/Tech Lead xác nhận dọn dẹp cùng đợt với các dead code đã biết ở mục 1, tránh 2 developer tương lai sửa nhầm method không active.
- Chỉ test nhánh reverse cho SKU COMPONENT (quantity-mode, không serial-tracked) — khớp với toàn bộ test coverage hiện có của luồng inbound (mọi test COMPONENT). Nhánh reverse cho PRODUCT/serial-tracked qua đường inbound (`assertItemBelongsToOriginal`/`assertItemDependency` trong `CurrentBaselineReversalService`) chưa có test HTTP riêng — rủi ro thấp hơn vì cùng code path đã được test kỹ ở phía outbound/issue trong WWHN-202 (BE2), nhưng khuyến nghị task riêng nếu QA cần evidence sâu hơn cho item-mode reversal phía receipt.
- Acceptance Criteria gốc của HN-FE-003 (permission/RBAC hiển thị UI, loading/empty/error/success/disabled/confirm state, responsive, lint/build FE) **không được rà soát** vì không có mã nguồn FE trong phạm vi truy cập — xem ghi chú traceability ở đầu mục Evidence.

### 4. Test coverage — trước và sau

Chạy trước khi thay đổi (baseline, commit `fad4242`): `php artisan test` → **367 tests, 351 passed, 16 skipped, 0 failed, 4497 assertions**.

Chạy sau khi thêm `Wwhn207InboundReversalFlowTest.php` (không sửa code sản xuất nào): `php artisan test` → **373 tests, 357 passed, 16 skipped, 0 failed, 4556 assertions** — không có regression, 16 skip giữ nguyên (baseline có sẵn, không liên quan tới thay đổi này).

### 5. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-207 / HN-FE-003) | Trạng thái | Ghi chú |
|---|---|---|---|
| 1 | Output "Inbound FE" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò FE1 | **Không thể thực hiện đúng nghĩa gốc** — không có mã nguồn FE. Đã thực hiện thay thế: rà soát toàn bộ API/luồng nhập kho phía Backend mà FE sẽ tích hợp | Cần BA/PM xác nhận vị trí repo FE thật để giao lại task FE1 đúng nghĩa |
| 2 | UI đáp ứng quy tắc nghiệp vụ, field spec, responsive; loading/empty/error/success/disabled/confirm | **Không áp dụng được** trong phạm vi đã rà soát (Backend-only) | Blocker cần chuyển cho FE1 khi có repo FE |
| 3 | Permission/RBAC và API error hiển thị đúng; không hard-code trái contract | **Done (phía API)** — mọi endpoint inbound/putaway/reverse có permission middleware riêng, đã test 401/403 với error code chuẩn (`ACCESS_DENIED`, `UNAUTHENTICATED`); response luôn theo envelope chuẩn `success/message/data/meta` hoặc `success/message/error_code/errors/meta` | Mục 1-2, `Wwhn193PutawayLifecycleTest`, `Wwhn207InboundReversalFlowTest` |
| 4 | Tích hợp đúng endpoint/payload đã duyệt; lint, build, test phù hợp phải pass | **Done (phía API)** — route surface xác nhận khớp OpenAPI/permission catalog; `php artisan test` 373/373 (357 passed + 16 skip baseline) không regression; `vendor/bin/pint --test` pass cho file mới | Mục 2, 4 ở trên |
| 5 | BA/QA có evidence để kiểm tra; không còn P0/P1 blocker chưa có disposition | **Có disposition rõ ràng** — không phát hiện bug P0/P1 mới phía API nhập kho sau khi test HTTP thật cho reverse (gap coverage duy nhất tìm thấy); blocker P0 thực sự của Acceptance Criteria gốc là **thiếu repo FE**, đã nêu ở mục 1, cần BA/PM xử lý trước khi FE1 có thể tiếp tục | Mục 1, 3 ở trên |

### 6. Định nghĩa Done — thực tế

#### Backend API readiness (phần đã thực hiện trong task này)

Đạt: rà soát toàn bộ route/controller/service/middleware/permission của luồng nhập kho (document CRUD, box registration, scan, post-receipt, putaway draft/confirm/cancel/history, reverse); xác minh 5 điểm nghi vấn ban đầu đều không phải bug (có bằng chứng cụ thể ở mục 1); phát hiện và lấp gap coverage duy nhất — thiếu test HTTP end-to-end cho reverse-inbound — bằng 6 test case mới, toàn bộ pass ngay lần đầu (không phát hiện bug chức năng, khác với pattern bug thật ở WWHN-190/191/202 phía outbound); full suite 357/357 pass (16 skip baseline không liên quan) tại HEAD `fad4242` + thay đổi của task này; ghi nhận thêm 1 dead code chưa từng biết trước đây (`CurrentBaselineStockService::reverseStockDocument()`) cho task dọn dẹp riêng.

#### Còn lại — KHÔNG thuộc phạm vi có thể tự hoàn thành trong task này

- **Blocker P0 thật sự**: không có mã nguồn FE (React/Vite) trong phạm vi truy cập để thực hiện đúng nghĩa Acceptance Criteria gốc của HN-FE-003 ("Inbound FE"). Owner: BA/PM/Tech Lead — cần xác nhận vị trí repo FE (nếu tồn tại ở nơi khác) hoặc điều chỉnh lại phạm vi/assignee của task, kèm ETA trước khi FE1 có thể tiếp tục.
- BA/Tech Lead xác nhận có cần task riêng dọn dẹp dead code (`InboundDocumentController`, `StockDocumentService`, `PutawayController::registerInboundBox/storeTask`, `StoreInboundDocumentRequest`/`UpdateInboundDocumentRequest`, và `CurrentBaselineStockService::reverseStockDocument()` mới ghi nhận) hay giữ nguyên hiện trạng (chỉ giữ OpenAPI annotation).
- QA xác nhận evidence mục 2-4 đáp ứng đúng Acceptance Criteria phần Backend; khi có repo FE, cần rà soát lại toàn bộ 5 Acceptance Criteria gốc theo đúng nghĩa UI.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do phiên rà soát này tự ghi trong file.
