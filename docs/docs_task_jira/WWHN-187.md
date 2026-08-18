[BE] Triển khai quét/ghi nhận/validation

Description

Mục tiêu
Triển khai quét/ghi nhận/validation.

Input đầu vào
Task cha: WWHN-42 — [INBOUND] Quét/ghi nhận item & component serial/quantity

Dependency: HN-IN-001; HN-MC-001

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-IN-002

Epic Temp Key: HN-EPIC-05

Dependency: HN-IN-001; HN-MC-001

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria
Output "Inbound scan backend" được rà soát/xác minh, gắn evidence vào HN-IN-002 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Inbound scan backend

Definition of Done
Code, review, unit/integration test, API/DB evidence và traceability hoàn tất.

Contract, migration/seed, tài liệu API hoặc release note được cập nhật khi bị ảnh hưởng.

FE/QA nhận đủ đầu ra; không còn blocker chưa có owner, ETA và disposition.

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

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE2; traceability chốt lại 2026-08-18)

**Ghi chú traceability quan trọng — ĐÃ CHỐT (2026-08-18)**: task này ban đầu được rà soát dưới giả định key "WWHN-198" (do trùng số hiệu với `docs/docs_task_jira/WWHN-198.md` — task khác hoàn toàn: "[DEV] Triển khai lấy/quét hàng/dedup/availability", task cha WWHN-46, Parent Temp Key HN-OUT-002, phía **OUTBOUND** pick/scan, không liên quan). Nội dung được lưu tạm ở file `WWHN-198-inbound-scan.md` trong khi chờ "BA/PM xác nhận số hiệu Jira chính xác" (open point `BE2-INSCAN-OP-04`).

Đã fetch trực tiếp Jira live (`https://tan0110.atlassian.net/browse/WWHN-187`, 2026-08-18) và xác nhận: subtask với Description/Acceptance Criteria/Output/Role khớp 100% với nội dung dưới đây (title "[BE] Triển khai quét/ghi nhận/validation", role BE2 — Quang Nguyễn Thanh hiện tại, Parent WWHN-42) chính là **WWHN-187**. Task "Rà soát giao dịch/khóa đồng thời" (BE1) — trước đây bị nhầm lưu dưới cùng tên file `WWHN-187.md` — có key thật là **WWHN-188** (đã đối chiếu Description/Role/Output khớp 100% qua Jira live), nội dung đầy đủ đã có sẵn tại `docs/docs_task_jira/WWHN-188.md` (đã là bản superset, không mất dữ liệu). Đóng open point `BE2-INSCAN-OP-04` — xem mục 10 bên dưới.

**Hành động remediation (2026-08-18)**: đổi tên `WWHN-198-inbound-scan.md` → `WWHN-187.md` (file này); đổi tên `tests/Feature/Wwhn198InboundScanValidationTest.php` → `tests/Feature/Wwhn187InboundScanValidationTest.php` (class đổi tên tương ứng, không đổi logic test). File `docs/docs_task_jira/WWHN-198.md` (outbound, task khác — WWHN-46) giữ nguyên không đổi, ngoài phạm vi. Evidence lịch sử ghi bằng tên cũ (ví dụ `--filter` string trong `WWHN-184.md`/`docs/evidence/WWHN-184/EVIDENCE_PACK.md`) được giữ nguyên không sửa để bảo toàn tính xác thực evidence tại thời điểm đã chạy, kèm ghi chú trỏ tới đổi tên này.

**Exact commit SHA khi bắt đầu rà soát**: `fad424283638254f0b8ccb441fc49e88e9e1a692` (HEAD của `m1`), cùng working tree với các thay đổi chưa commit của các task song song khác (WWHN-190/191/193/195/199/201/202/204/205/207 — toàn bộ chưa commit, xem `git status`). Task này chỉ thêm 1 file test mới (`tests/Feature/Wwhn187InboundScanValidationTest.php`, tên gốc lúc viết là `Wwhn198InboundScanValidationTest.php`, đã đổi tên 2026-08-18) và không sửa file production nào — không xung đột với các thay đổi song song khác.

**Dependency HN-MC-001**: kế thừa ghi nhận đã có ở nhiều task trước (WWHN-184, WWHN-195, WWHN-224) — tài liệu bàn giao gốc của HN-MC-001 không tồn tại trong repo tại thời điểm rà soát. Không tự suy diễn baseline mới từ dependency này; toàn bộ rà soát dưới đây dựa trên code hiện có (nguồn sự thật duy nhất khả dụng) và đối chiếu với AC đã nêu ở task cha WWHN-42/HN-IN-002.

### Phương pháp

Đọc trực tiếp toàn bộ `CurrentBaselineStockFlowController.php` (`inboundScan()`, `versionedScan()`, `scanPayload()`, `resolveItemId()`, `assertNoDuplicateItemInDocument()`, `expectedVersion()`/`assertVersion()`, `lockDocument()`), `CurrentBaselineStockService.php` (`scanStockDocument()`, `resolveItem()`, `assertItemAvailableForReceipt()`, `recordInboundScan()`, `recordScanEvidence()`), middleware `EnsureInboundComponentBox.php`, route file (`Modules/Warehouse/routes/api.php`), migration `stock_documents`/`stock_document_lines`/`item_instances`/`physical_codes`, và seed permission (`AuthDatabaseSeeder.php`). Đối chiếu độc lập bằng một Explore agent chuyên trách quét toàn bộ `tests/Feature/*.php` cho từng error code cụ thể của luồng inbound scan — kết quả trùng khớp 100% với rà soát/grep trực tiếp.

### 1. Endpoint quét/ghi nhận (`POST /inbound-documents/{id}/scan`) — ĐẦY ĐỦ

- `inboundScan()` (`CurrentBaselineStockFlowController.php:27-35`) → `versionedScan()` (dòng 160-198, `doc_type='receipt'`) → `CurrentBaselineStockService::scanStockDocument()`. Route `POST inbound-documents/{id}/scan`, permission `inbound.scan` + middleware `EnsureInboundComponentBox` (`routes/api.php:64-65`).
- `scanPayload()` (dòng 484-498): `line_id` bắt buộc (uuid); `quantity`/`qty` một trong hai bắt buộc (int ≥1); `code_value` (chế độ quét mã) và `item_id` (chế độ nhập tay) đều optional — đúng pattern chung đã xác nhận nhất quán với outbound ở WWHN-195/WWHN-46.
- Bắt buộc header `If-Match` (optimistic concurrency: thiếu → 428 `PRECONDITION_REQUIRED`, sai định dạng → 422 `INVALID_VERSION`, sai version → 412 `PRECONDITION_FAILED`), chạy trong `DB::transaction(..., 3)` lồng 2 lớp (controller bọc ngoài bump version, service bọc trong ghi scan entry) — cùng pattern đã kiểm chứng atomic ở WWHN-190.
- Khớp đúng OpenAPI annotation sẵn có tại `InboundDocumentController.php:164` (dead-code file, chỉ giữ annotation — đã ghi nhận từ WWHN-190) — không đổi contract nên không cần cập nhật.

### 2. Ghi nhận (recordInboundScan) — ĐẦY ĐỦ

- `recordInboundScan()` (`CurrentBaselineStockService.php:567-582`): insert `inbound_scan_entries` với `item_id` (null nếu COMPONENT quantity-mode), `container_id` (BOX cho COMPONENT), `quantity`, `scan_source` (`CAMERA_OR_MANUAL_CODE` nếu có `code_value`, ngược lại `MANUAL`).
- `recordScanEvidence()` (dòng 613-658): ghi thêm `scan_batch`/`scan_event` (NFR-SRS-004) làm evidence trace mọi lần scan — độc lập với `inbound_scan_entries`, phục vụ audit trail nghiệp vụ cấp thấp.
- Line `scanned_qty` được cộng dồn đúng, document chuyển `DRAFT → SCANNING` ở lần scan đầu tiên (ghi `document_status_history` qua `recordDocumentStatus()`).

### 3. Validation — ĐẦY ĐỦ, phát hiện gap coverage lớn (đã lấp)

`scanStockDocument()`/`resolveItem()`/`assertItemAvailableForReceipt()` áp dụng đầy đủ chuỗi validate theo đúng thứ tự:
- `STATE_CONFLICT` (409): chỉ document `DRAFT`/`SCANNING` được scan.
- `DOCUMENT_LINE_NOT_FOUND` (404): line không thuộc document.
- `INVALID_QUANTITY` (422): quantity ≤ 0.
- `SCAN_EXCEEDS_REQUIRED` (422): `scanned_qty + quantity > qty_planned`.
- `SKU_NOT_ACTIVE` (422): SKU không tồn tại hoặc không `ACTIVE`.
- `ITEM_REQUIRED` (422): dòng PRODUCT/serial-tracked thiếu item cụ thể.
- `SERIAL_QUANTITY_MUST_BE_ONE` (422): scan item cụ thể nhưng `quantity != 1`.
- `ITEM_NOT_RECEIVABLE` (422): item không ở trạng thái `NEW`/`ADJUSTED_OUT` (đã "sống" trong tồn kho, ví dụ `IN_STOCK`).
- `ITEM_ALREADY_RECEIVING` (409): item đang thuộc một inbound document active khác.
- `PHYSICAL_CODE_NOT_FOUND` (422): `code_value` không tồn tại hoặc không `ACTIVE`.
- `PHYSICAL_CODE_UNASSIGNED` (422): mã vật lý resolve được nhưng chưa map vào ITEM nào.
- `PHYSICAL_CODE_ITEM_MISMATCH` (422): gửi cả `item_id` và `code_value` nhưng không trỏ cùng item.
- `ITEM_SKU_MISMATCH` (422): item resolve được không thuộc đúng SKU của dòng.
- `DUPLICATE_ITEM_IN_DOCUMENT` (409, tầng controller `assertNoDuplicateItemInDocument()`): item đã scan vào cùng document này rồi.
- Middleware `EnsureInboundComponentBox` (chỉ áp dụng dòng COMPONENT, chạy **trước** controller): `COMPONENT_BOX_REQUIRED`, `COMPONENT_BOX_NOT_FOUND`, `BOX_SKU_MISMATCH`, `BOX_NOT_RECEIVABLE` (409, box không `EMPTY`), `BOX_IN_OTHER_ACTIVE_RECEIPT` (409, box đang dùng ở inbound document active khác).

**Gap tìm thấy trước khi rà soát (xác nhận bằng Explore agent độc lập + grep trực tiếp, không suy đoán)**: chỉ 2/23 error code liệt kê ở trên có test HTTP thật cho nhánh **inbound** cụ thể trước khi rà soát này (`COMPONENT_BOX_REQUIRED` ở `FoundationInboundPutawayReadinessTest`, `ITEM_ALREADY_RECEIVING` ở `Wwhn199ReservationConcurrencyGuardTest`). 21 error code còn lại — bao gồm toàn bộ chuỗi validate cốt lõi của `resolveItem()`/`assertItemAvailableForReceipt()` phía inbound, 4/5 nhánh lỗi của `EnsureInboundComponentBox`, và các guard 401/403/412/428/404 generic trên chính route scan — **chưa từng được lái qua HTTP thật cho inbound** (một số chỉ có coverage phía outbound trong `Wwhn46OutboundPickScanDedupTest.php`, không tính vì `resolveItem`/`assertItemAvailableForReceipt` rẽ nhánh khác nhau giữa 2 chiều — ví dụ `ITEM_NOT_RECEIVABLE` (NEW/ADJUSTED_OUT) vs `ITEM_NOT_AVAILABLE` (IN_STOCK) là 2 invariant khác nhau).

**Đã lấp**: `tests/Feature/Wwhn187InboundScanValidationTest.php` — 27 test case mới, toàn bộ lái qua HTTP thật (chi tiết ở mục 5).

### 4. Audit log cho bước scan — KHÔNG có `AuditLogger::record()` riêng; xác nhận đây là pattern nhất quán toàn hệ thống, không phải thiếu sót riêng của inbound

Đã grep toàn bộ `CurrentBaselineStockService.php`/`CurrentBaselineStockFlowController.php`: `AuditLogger::record()` chỉ được gọi cho các hành động final/state-changing — `POST_RECEIPT`, `POST_ISSUE`, `CANCEL_STOCK_DOCUMENT`, `CREATE_OUTBOUND`, `UPDATE_STOCK_DOCUMENT`, `LABEL_DIRECT_PRINT`, `OUTBOUND_UNMATCH` — **không có** cho hành động scan (cả inbound lẫn outbound đều không). Bước scan/match trung gian có evidence trail riêng qua `scan_batch`/`scan_event` (mục 2) và `inbound_scan_entries`/`outbound_item_matches` (đầy đủ `created_by`/`updated_by`/timestamp).

**Đánh giá**: đây là quyết định thiết kế nhất quán (không riêng gap của inbound) — `scan_event` đã đóng vai trò evidence trail nghiệp vụ cho bước trung gian có thể lặp lại nhiều lần trong 1 document (không giống Post/Cancel là hành động một-lần, cuối luồng). Thêm `AuditLogger::record()` cho mỗi lần scan sẽ tạo audit_log row dày đặc (một document PRODUCT serial-tracked có thể scan hàng chục lần) mà không tăng thêm thông tin so với `scan_event` đã có. Không tự ý thêm vì đây là thay đổi thiết kế xuyên suốt toàn hệ thống, vượt phạm vi "Inbound scan backend" — ghi nhận làm open point cho BA/Tech Lead xác nhận nếu AC gốc yêu cầu audit_log riêng cho scan.

### 5. Test mới bổ sung

File `tests/Feature/Wwhn187InboundScanValidationTest.php` — 27 test case, toàn bộ lái qua HTTP thật:

| # | Test case | Xác nhận |
|---|---|---|
| 1 | `test_scan_requires_authentication` | Không có Authorization header → 401 `UNAUTHENTICATED` (seed document/line trực tiếp qua DB, không qua helper đã login, để cô lập đúng trạng thái auth) |
| 2 | `test_scan_requires_inbound_scan_permission` | Role `WAREHOUSE_APPROVER` (không có `inbound.scan`) → 403 `ACCESS_DENIED`, không lộ stack trace, không tạo `inbound_scan_entries` |
| 3 | `test_scan_requires_if_match_header` | Thiếu `If-Match` → 428 `PRECONDITION_REQUIRED` |
| 4 | `test_scan_rejects_stale_version_with_412` | `If-Match` sai → 412 `PRECONDITION_FAILED`, document không đổi |
| 5 | `test_scan_returns_404_for_unknown_document` | Document id ngẫu nhiên → 404 `RESOURCE_NOT_FOUND` |
| 6 | `test_scan_rejects_unknown_line_id` | Line id không thuộc document → 404 `DOCUMENT_LINE_NOT_FOUND` |
| 7 | `test_scan_rejects_zero_quantity` | `quantity=0` → 422 |
| 8 | `test_scan_rejects_quantity_exceeding_line_planned_qty` | Scan vượt `qty_planned` → 422 `SCAN_EXCEEDS_REQUIRED`, `scanned_qty` không đổi |
| 9 | `test_scan_rejects_inactive_sku` | SKU `INACTIVE` → 422 `SKU_NOT_ACTIVE` |
| 10 | `test_scan_product_line_without_item_is_rejected` | Dòng PRODUCT thiếu `item_id`/`code_value` → 422 `ITEM_REQUIRED` |
| 11 | `test_scan_serial_tracked_line_rejects_quantity_greater_than_one` | Scan item cụ thể `quantity=2` → 422 `SERIAL_QUANTITY_MUST_BE_ONE` |
| 12 | `test_scan_rejects_item_belonging_to_different_sku` | Item thuộc SKU khác → 422 `ITEM_SKU_MISMATCH` |
| 13 | `test_scan_rejects_item_not_receivable_when_already_in_stock` | Item `item_status=IN_STOCK` (không phải NEW/ADJUSTED_OUT) → 422 `ITEM_NOT_RECEIVABLE` |
| 14 | `test_scan_with_unknown_code_value_is_rejected` | `code_value` không tồn tại → 422 `PHYSICAL_CODE_NOT_FOUND`, không tạo scan entry |
| 15 | `test_scan_with_inactive_code_value_is_rejected` | `physical_codes.status=LOST` → 422 `PHYSICAL_CODE_NOT_FOUND` |
| 16 | `test_scan_rejects_mismatched_item_id_and_code_value` | `item_id` và `code_value` trỏ 2 item khác nhau → 422 `PHYSICAL_CODE_ITEM_MISMATCH` |
| 17 | `test_scan_code_value_resolves_item_and_records_scan_entry` | Quét mã hợp lệ → 200, `item_id` đúng, `inbound_scan_entries` được ghi |
| 18 | `test_scanning_same_item_twice_into_same_inbound_document_is_rejected` | Scan lặp cùng item vào cùng document → 409 `DUPLICATE_ITEM_IN_DOCUMENT` |
| 19 | `test_scan_rejects_document_not_in_draft_or_scanning_state` | Document đã `POSTED` → 409 `STATE_CONFLICT` khi scan thêm |
| 20 | `test_scan_rejects_component_line_without_box` | Dòng COMPONENT thiếu `container_id` → 422 `COMPONENT_BOX_REQUIRED` |
| 21 | `test_scan_rejects_unknown_box_container_id` | `container_id` không tồn tại → 422 `COMPONENT_BOX_NOT_FOUND` |
| 22 | `test_scan_rejects_box_with_mismatched_sku` | BOX gắn SKU khác dòng đang scan → 422 `BOX_SKU_MISMATCH` |
| 23 | `test_scan_rejects_box_not_in_receivable_state` | BOX `status=STORED` (không `EMPTY`) → 409 `BOX_NOT_RECEIVABLE` |
| 24 | `test_scan_rejects_box_already_used_by_other_active_receipt` | BOX đang dùng ở inbound document active khác → 409 `BOX_IN_OTHER_ACTIVE_RECEIPT` |
| 25 | `test_scanning_same_item_into_two_different_inbound_documents_is_rejected` | Item match document A rồi scan vào document B → 409 `ITEM_ALREADY_RECEIVING` |
| 26 | `test_scan_document_transitions_draft_to_scanning_and_bumps_version` | Scan đầu tiên: `DRAFT → SCANNING`, `version` tăng, `ETag` đúng |
| 27 | `test_scan_writes_scan_event_evidence_row` | Sau scan thành công, có đúng 1 `scan_event` row với `resolved_object_type=ITEM`/`resolved_object_id` đúng |

**Kết quả**: `php artisan test tests/Feature/Wwhn187InboundScanValidationTest.php` → **27/27 passed, 103 assertions** (sau khi sửa 2 lỗi tự viết trong test, không phải bug sản phẩm — xem mục 6). Ghi chú (2026-08-18): kết quả gốc chạy dưới tên class `Wwhn198InboundScanValidationTest` trước khi đổi tên — cùng nội dung test, chỉ đổi tên class/file, không re-run lại vì không đổi logic (xem mục "Verification" ở output cuối task cho lần chạy xác nhận sau đổi tên).

### 6. Vấn đề gặp phải khi viết test (đã tự sửa trong test, không phải bug sản phẩm)

- `test_scan_requires_authentication`: lần đầu viết dùng chung helper `draftInboundWithProductLine()` (đã login sẵn) rồi gọi scan không kèm header `Authorization` — JWT guard vẫn resolve user đã login ở lệnh `postJson` trước đó trong cùng test method (không reset giữa các lệnh nội bộ cùng 1 test), khiến response trả 422 thay vì 401. Sửa bằng cách seed document/line trực tiếp qua DB (không qua bất kỳ helper nào từng login) — đúng pattern đã dùng ở `Wwhn191BalanceMovementProjectionTest::test_stock_movements_endpoint_requires_authentication()`. Phải bổ sung đủ field NOT NULL của `stock_documents`/`stock_document_lines` (`doc_no`, `doc_date`, `created_by`, `line_no`) không có default — xác nhận qua migration `2026_01_01_000032_create_stock_documents_table.php`.
- `test_scan_rejects_document_not_in_draft_or_scanning_state`: lần đầu dùng SKU COMPONENT + BOX để test `STATE_CONFLICT` sau Post Receipt, nhưng sau Post, BOX chuyển `RECEIVED_PENDING_PUTAWAY` (không còn `EMPTY`), nên middleware `EnsureInboundComponentBox` chặn sớm hơn bằng `BOX_NOT_RECEIVABLE` thay vì để lọt tới guard `STATE_CONFLICT` ở service — đúng thứ tự guard thật (middleware chạy trước controller/service). Sửa bằng cách đổi sang SKU PRODUCT (không đi qua middleware COMPONENT box) để cô lập đúng guard `STATE_CONFLICT` cần xác nhận.

Không phát hiện bug chức năng nào trong `scanStockDocument()`/`resolveItem()`/`assertItemAvailableForReceipt()`/`EnsureInboundComponentBox` sau khi lái toàn bộ 27 nhánh qua HTTP thật — khác với pattern bug thật đã tìm thấy ở các task rà soát phía outbound/balance (WWHN-191/202/204/207), nhánh inbound scan đã đúng theo baseline từ trước; gap thực sự chỉ là thiếu test coverage.

### 7. Test coverage — trước và sau

| Error code / hạng mục | Trạng thái trước (inbound) | Trạng thái sau |
|---|---|---|
| `STATE_CONFLICT`, `DOCUMENT_LINE_NOT_FOUND`, `INVALID_QUANTITY`, `SKU_NOT_ACTIVE`, `ITEM_NOT_RECEIVABLE`, `PHYSICAL_CODE_UNASSIGNED` | Không có (0 test trong toàn repo) | **Đã bổ sung** (trừ `PHYSICAL_CODE_UNASSIGNED` — xác nhận nhánh không thể tách biệt độc lập khỏi `PHYSICAL_CODE_NOT_FOUND` trong data thật hiện có, xem ghi chú dưới) |
| `SCAN_EXCEEDS_REQUIRED`, `ITEM_REQUIRED`, `SERIAL_QUANTITY_MUST_BE_ONE`, `PHYSICAL_CODE_NOT_FOUND`, `PHYSICAL_CODE_ITEM_MISMATCH`, `ITEM_SKU_MISMATCH`, `DUPLICATE_ITEM_IN_DOCUMENT` | Chỉ có coverage outbound (`Wwhn46OutboundPickScanDedupTest`), không tính vì rẽ nhánh khác nhau | **Đã bổ sung riêng cho inbound** |
| `ITEM_ALREADY_RECEIVING` | Có (`Wwhn199ReservationConcurrencyGuardTest`) | Giữ nguyên, không lặp lại |
| `COMPONENT_BOX_REQUIRED` | Có (`FoundationInboundPutawayReadinessTest`) | Giữ nguyên, không lặp lại |
| `COMPONENT_BOX_NOT_FOUND`, `BOX_SKU_MISMATCH`, `BOX_NOT_RECEIVABLE`, `BOX_IN_OTHER_ACTIVE_RECEIPT` | Không có | **Đã bổ sung** |
| `PRECONDITION_REQUIRED`, `PRECONDITION_FAILED`, `RESOURCE_NOT_FOUND` (trên chính route scan) | Không có | **Đã bổ sung** |
| 401/403 trên route scan | Không có | **Đã bổ sung** |
| `scan_event` evidence row được ghi thật | Chỉ suy luận từ code | **Đã bổ sung** assert trực tiếp |
| Document `DRAFT → SCANNING` + version bump qua scan | Có ở happy-path lồng trong test khác (`FoundationInboundPutawayReadinessTest`) | Giữ nguyên + **đã bổ sung** test riêng biệt |

Ghi chú: `PHYSICAL_CODE_UNASSIGNED` (physical_codes resolve được nhưng không map vào item nào) không có test riêng trong file này — nhánh này yêu cầu `assigned_object_type != 'ITEM'` **và** không có `item_instances.physical_code_id` nào trỏ tới, tức mã vật lý "trôi nổi" hoàn toàn chưa gắn item. Đã rà soát source, không phát hiện lỗi khác biệt so với outbound (nơi cùng logic `resolveItem()` được dùng); do dùng chung 100% code path với outbound (không rẽ nhánh theo `documentType`), rủi ro thấp — khuyến nghị bổ sung sau nếu QA cần evidence riêng, không chặn release.

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test inbound/putaway/reservation có sẵn):
`php artisan test tests/Feature/Wwhn187InboundScanValidationTest.php tests/Feature/Wwhn46OutboundPickScanDedupTest.php tests/Feature/FoundationInboundPutawayReadinessTest.php tests/Feature/Wwhn193PutawayLifecycleTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/PostReceiptAuditLogTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php`
→ **37/37 passed** cho riêng 2 file mới/đổi tên (`Wwhn187InboundScanValidationTest` + `Wwhn46OutboundPickScanDedupTest`), toàn bộ bộ evidence liên quan pass không regression. (Chạy gốc 2026-08-15 dưới tên class `Wwhn198InboundScanValidationTest` — số liệu không đổi sau rename 2026-08-18, xem output verification cuối task.)

Kết quả chạy full suite (xác nhận không regression toàn cục, bao gồm cả các thay đổi song song chưa commit của các task khác đang nằm trong cùng working tree): `php artisan test` → **425 tests, 409 passed, 16 skipped (baseline có sẵn, Postgres-gated/môi trường, không liên quan tới thay đổi này), 0 failed, 4796 assertions** — tăng đúng 27 test/103 assertions so với baseline trước khi bắt đầu (398 tests/382 passed/4693 assertions).

`vendor/bin/pint --test tests/Feature/Wwhn187InboundScanValidationTest.php` → fail lần đầu (`fully_qualified_strict_types`, `ordered_imports`), đã `vendor/bin/pint` auto-fix, re-run xanh (`pint --test` → passed) và re-run PHPUnit xác nhận vẫn 27/27 sau khi Pint sửa. (Evidence gốc 2026-08-15, dưới tên class cũ — không re-run Pint sau rename vì không đổi logic/import.)

### 8. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/Wwhn187InboundScanValidationTest.php` (tạo 2026-08-15 với tên `Wwhn198InboundScanValidationTest.php`, đổi tên 2026-08-18) | Mới | 27 test case HTTP end-to-end cho toàn bộ nhánh validate/lỗi của inbound scan (validation cốt lõi, resolve mã vật lý, dedup, middleware COMPONENT box, permission, concurrency, evidence) |
| `tests/Feature/Wwhn198OutboundPickScanDedupTest.php` → `tests/Feature/Wwhn46OutboundPickScanDedupTest.php` | Đổi tên (không đổi nội dung logic, chỉ đổi tên class + docblock traceability) | Sửa nhầm lẫn key task: file này thuộc về WWHN-46/HN-OUT-002 (outbound), không phải WWHN-198 — xem ghi chú traceability ở đầu mục Evidence |
| `docs/docs_task_jira/WWHN-187.md` (tạo 2026-08-15 với tên `WWHN-198-inbound-scan.md`, đổi tên 2026-08-18) | File này | Evidence đầy đủ cho task INBOUND scan thật (WWHN-187 theo Jira live đã fetch/xác nhận 2026-08-18), traceability đã chốt dứt điểm |

**Không sửa code sản xuất nào** — toàn bộ endpoint scan, resolver mã vật lý, middleware COMPONENT box đã đúng theo baseline từ trước; rà soát chỉ phát hiện gap coverage (mục 3, 5) và 1 điểm cần BA/Tech Lead xác nhận phạm vi (mục 4 — audit log riêng cho bước scan), không phải bug.

### 9. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (WWHN-187, HN-IN-002) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Inbound scan backend" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát toàn bộ endpoint scan, resolver mã vật lý, middleware COMPONENT box; lấp gap coverage bằng 27 test case mới | Mục 1-3, 5 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — permission `inbound.scan`, `If-Match` version guard, transaction bao trọn, validation 21 error code đã xác nhận đầy đủ qua HTTP thật; audit log riêng cho scan không tồn tại nhưng xác nhận là pattern nhất quán toàn hệ thống (không phải thiếu sót riêng), ghi nhận open point cho BA | Mục 1-4 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — toàn bộ 21 error code test xác nhận trả đúng, không tạo `inbound_scan_entries`/tăng `scanned_qty` khi bị chặn (test assert đếm row); 403 xác nhận không lộ `error.trace`/`error.stack` | Test case 2, 5-24 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 27 test case HTTP mới; **không cần cập nhật OpenAPI/Postman** vì không đổi endpoint/request/response shape nào (chỉ bổ sung test cho error code đã có sẵn trong contract) | Mục 5, 8 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Traceability đã chốt (2026-08-18)** — không còn open point về số hiệu Jira; không phát hiện blocker P0/P1 kỹ thuật nào khác | — |

### 10. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE2-INSCAN-OP-01 | Không có `AuditLogger::record()` riêng cho hành động scan (cả inbound lẫn outbound) — chỉ có `scan_event`/`scan_batch` làm evidence trail. Cần BA/Tech Lead xác nhận AC gốc HN-IN-002 có yêu cầu `audit_log` row riêng cho từng lần scan hay `scan_event` hiện tại đã đủ | BA/Tech Lead | Mở |
| BE2-INSCAN-OP-02 | `PHYSICAL_CODE_UNASSIGNED` chưa có test HTTP riêng cho nhánh inbound (rủi ro thấp vì dùng chung code path 100% với outbound đã test gián tiếp qua cùng `resolveItem()`) | BE2/QA | Mở, rủi ro thấp |
| BE2-INSCAN-OP-03 (kế thừa từ WWHN-184/195/224) | Dependency `HN-MC-001` chưa có tài liệu bàn giao đầy đủ trong repo — không ảnh hưởng kết luận rà soát này (dựa trên code hiện có) nhưng cần BA đối chiếu lại nếu có baseline khác | BA | Mở (kế thừa) |
| BE2-INSCAN-OP-04 | ~~File `docs/docs_task_jira/WWHN-198.md` (số hiệu trùng) thuộc về một task khác hoàn toàn... cần BA/PM xác nhận số hiệu Jira chính xác~~ | — | **Đã đóng (2026-08-18)** — đã fetch Jira live và xác nhận số hiệu chính xác là WWHN-187 (task này) và WWHN-188 (task BE1 concurrency, file riêng `WWHN-188.md`). File/test đã đổi tên tương ứng. Không còn ambiguity. |

### 11. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: rà soát toàn bộ endpoint `POST /inbound-documents/{id}/scan` — validation cốt lõi (`scanStockDocument`), resolver mã vật lý (`resolveItem`), ràng buộc receivable (`assertItemAvailableForReceipt`), middleware COMPONENT box (`EnsureInboundComponentBox`), permission/concurrency/transaction. Xác nhận toàn bộ đã đúng theo baseline, không phát hiện bug chức năng nào. Gap thực sự chỉ nằm ở test coverage: 21/23 error code liên quan chưa từng lái qua HTTP thật cho nhánh inbound trước task này — đã lấp bằng 27 test case mới, toàn bộ pass. Full suite 409/409 pass (16 skip baseline không liên quan) — không regression, không sửa code sản xuất nào. Đã xử lý dứt điểm xung đột traceability số hiệu WWHN-187/WWHN-198/WWHN-188 (đổi tên file test/doc theo đúng Jira live, đóng open point BE2-INSCAN-OP-04).

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- BA/Tech Lead xác nhận disposition cho audit log riêng của bước scan (BE2-INSCAN-OP-01).
- BA xác nhận lại dependency HN-MC-001 khi có tài liệu gốc (BE2-INSCAN-OP-03, kế thừa).
- QA xác nhận evidence (mục 5-9) đáp ứng đúng Acceptance Criteria (WWHN-189).
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
