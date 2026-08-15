[DEV] Rà soát khóa đồng thời/quy tắc reservation
Mục tiêu
Rà soát khóa đồng thời/quy tắc reservation.

Input đầu vào
Task cha: WWHN-46 — [OUTBOUND] Lấy hàng/quét mã và chống ghi nhận trùng

Dependency: HN-OUT-002-ST01

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-OUT-002

Epic Temp Key: HN-EPIC-06

Dependency: HN-OUT-002-ST01

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "Kết quả rà soát đồng thời" được rà soát/xác minh, gắn evidence vào HN-OUT-002 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Kết quả rà soát đồng thời

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

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE1)

**Exact commit SHA khi bắt đầu rà soát**: HEAD của `m1` tại thời điểm bắt đầu, cùng working tree với các thay đổi chưa commit của WWHN-205 (`item_id` write-side fix, append-only trigger trên `stock_movements`) — 2 task chạy song song trên cùng nhánh, không xung đột file (WWHN-205 sửa `CurrentBaselineStockService.php` phần `mutateBalance()`/`resolveLineItemId()`, task này chỉ đọc và bổ sung test, không sửa cùng vùng code).

**Quan hệ với các task rà soát trước đó**: WWHN-195 (BE1, task cha HN-OUT-001 — "Triển khai chứng từ/chế độ/trạng thái xuất kho") đã xác nhận đầy đủ transaction/idempotency/concurrency ở **tầng document** (optimistic lock `If-Match`, row lock `stock_documents`, `Idempotency-Key` cho Post/Reverse, `business_key` unique chống double stock-effect) và bổ sung 4 file test HTTP (`OutboundDocumentFlowTest`, `PostIssueConcurrencyBehaviorTest`, `PostIssueAuditLogTest`, `OutboundPermissionTest`). Task này (WWHN-199, task cha HN-OUT-002 — "Lấy hàng/quét mã và chống ghi nhận trùng") rà soát một tầng **hẹp và sâu hơn**: quy tắc reservation ở mức **item vật lý** (một item không được match/nhận vào 2 phiếu khác nhau cùng lúc — đúng tên gọi "chống ghi nhận trùng" của task cha WWHN-46) và mức **toa yêu cầu xuất** (một toa chỉ sinh đúng 1 phiếu xuất). Đây là phần business rule chưa từng được lái qua HTTP thật ở bất kỳ task rà soát nào trước đó.

### Phương pháp

Đọc trực tiếp toàn bộ `CurrentBaselineStockFlowController.php` (555 dòng), `CurrentBaselineDocumentController.php` (phần `hydrateOutboundRequest`/`createDocument`), các hàm reservation trong `CurrentBaselineStockService.php` (`scanStockDocument`, `resolveItem`, `assertItemAvailableForIssue`, `assertItemAvailableForReceipt`, `recordOutboundMatch`, `assertIssueAvailability`), toàn bộ migration liên quan `outbound_item_matches`/`outbound_requests`/`item_instances`, và 2 file test hiện có (`OutboundDocumentFlowTest.php`, `PostIssueConcurrencyBehaviorTest.php`). Không suy đoán hành vi DB — xác nhận migration nào chạy trên driver nào bằng cách đọc trực tiếp guard `DB::getDriverName()`.

### 1. Quy tắc reservation cấp toa (outbound_requests → stock_documents) — ĐẦY ĐỦ

`CurrentBaselineDocumentController::hydrateOutboundRequest()` (dòng 423-448): khi tạo outbound document, khóa row `outbound_requests` (`lockForUpdate()`), reject nếu không tồn tại/`CANCELLED` (422 `OUTBOUND_REQUEST_NOT_ACTIVE`), reject nếu đã có `stock_documents` khác đang `DRAFT`/`SCANNING`/`POSTED` gắn cùng `outbound_request_id` (409 `OUTBOUND_REQUEST_ALREADY_USED`). Toàn bộ nằm trong `DB::transaction(..., 3)` bao quanh `createDocument()` — 2 request tạo document đồng thời từ cùng 1 toa bị tuần tự hóa đúng bởi row lock: request thứ hai phải chờ request thứ nhất commit/rollback mới lấy được lock, lúc đó `exists()` check chắc chắn thấy document vừa tạo.

**Không có unique constraint DB-level backstop riêng** cho quan hệ 1-toa-1-document (không giống `outbound_item_matches` có `ux_erd_outbound_active_item`) — bảo vệ hoàn toàn dựa vào `lockForUpdate()` + `DB::transaction()`. Đã xác nhận đây là bảo vệ **đủ** cho PostgreSQL (ACID chuẩn, row lock trong transaction là cơ chế tuần tự hóa hợp lệ, không cần unique index bổ sung khi luồng ghi duy nhất đi qua đúng 1 code path có lock) — khác với `outbound_item_matches`/`inventory_balances` là nơi có nhiều điểm ghi khác nhau (match + reversal + có thể thao tác trực tiếp DB) nên cần unique index làm lớp phòng thủ độc lập thứ hai. Không đề xuất thêm unique index ở đây để tránh over-engineering ngoài scope.

### 2. Quy tắc reservation cấp item vật lý (chống ghi nhận trùng khi quét mã) — ĐẦY ĐỦ, phát hiện gap coverage lớn

**Cơ chế đã tồn tại từ trước** (không phải mới viết trong task này):
- `resolveItem()` (`CurrentBaselineStockService.php:478-512`): với mọi scan có `item_id`/`code_value` resolve ra item, khóa row `item_instances` bằng `lockForUpdate()` — đây là **lock target dùng chung** cho cả inbound lẫn outbound, nên 2 request scan trùng cùng 1 item (dù vào document nào) bị tuần tự hóa bởi chính DB row lock, không phụ thuộc race window nào ở tầng application.
- `assertItemAvailableForIssue()` (dòng 514-533): sau khi có lock, kiểm tra `item_status == IN_STOCK` và không có `outbound_item_matches` ACTIVE nào ở document khác đang `DRAFT`/`SCANNING` → 409 `ITEM_ALREADY_MATCHED` nếu vi phạm.
- `assertItemAvailableForReceipt()` (dòng 535-551): đối xứng phía inbound, kiểm tra `item_status IN (NEW, ADJUSTED_OUT)` và không có `inbound_scan_entries` nào ở document khác đang active → 409 `ITEM_ALREADY_RECEIVING`.
- `recordOutboundMatch()` (dòng 584-611): chặn riêng trường hợp cùng 1 item bị match 2 lần vào **cùng 1** document → 409 `DUPLICATE_ITEM_MATCH`; `CurrentBaselineStockFlowController::assertNoDuplicateItemInDocument()` (dòng 422-439) là lớp chặn thứ hai ở tầng controller cho cùng invariant, trả 409 `DUPLICATE_ITEM_IN_DOCUMENT` (áp dụng cho cả inbound/outbound, sớm hơn trong luồng request).
- Backstop DB-level (Postgres-only, xác nhận qua migration `2026_08_10_000670_align_identity_container_and_scan_lifecycle.php:290` và `2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints.php:236`): `CREATE UNIQUE INDEX ux_erd_outbound_active_item ON outbound_item_matches(item_id) WHERE item_id IS NOT NULL AND status='ACTIVE'` — đảm bảo tại mọi thời điểm, 1 item chỉ có tối đa 1 match ACTIVE, độc lập với logic application. Tương tự có `uk_inbound_scan_active_item` cho `inbound_scan_entries`. Cả hai migration đều gate bằng `if (DB::getDriverName() === 'pgsql')` — trên SQLite (test suite) các unique index này **không tồn tại**, chỉ có application-level check; đây là gap môi trường-test đã biết (cùng pattern với các CHECK constraint khác trong dự án, không phải sai sót riêng của task này).

**Gap tìm thấy — coverage, không phải bug chức năng**: trước khi rà soát, **không có test HTTP nào** xác nhận `ITEM_ALREADY_MATCHED`, `DUPLICATE_ITEM_MATCH`/`DUPLICATE_ITEM_IN_DOCUMENT`, hoặc `ITEM_ALREADY_RECEIVING` thực sự chặn được request thứ hai qua đường HTTP thật. `OutboundDocumentFlowTest`/`PostIssueConcurrencyBehaviorTest` (WWHN-195) chỉ cover `OUTBOUND_REQUEST_ALREADY_USED` (tuần tự, không đúng race semantics), version/state/business_key — hoàn toàn không đụng tới quy tắc "một item không được match trùng", vốn chính là ý nghĩa cốt lõi của tên task cha WWHN-46 ("chống ghi nhận trùng").

### 3. Đánh giá `assertIssueAvailability()` — soft-check tại scan time, không phải reservation thật; không phải bug

`assertIssueAvailability()` (`CurrentBaselineStockService.php:553-565`) đọc `inventory_balances.available_qty` **không** `lockForUpdate()` khi scan — 2 scan đồng thời cùng SKU/khác item có thể cùng pass check dù tổng vượt quá tồn khả dụng thực tế (race window thật ở tầng scan). Xác nhận đây **không phải reservation gap nghiêm trọng** vì:
- `mutateBalance()` (dùng bởi `postStockDocument()`, đã xác nhận đầy đủ ở WWHN-190/195/205) là nguồn sự thật cuối cùng, có `lockForUpdate()` + version check + `business_key` unique tại **Post Issue time** — nếu 2 document cùng scan vượt tồn thực, document Post trước sẽ thành công, document Post sau sẽ bị `INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK` chặn đúng lúc trừ tồn thật.
- Do đó thiết kế hiện tại là **soft-check tại scan (early feedback cho người quét, không chặn tuyệt đối) + hard-check tại post (nguồn sự thật)** — đây là pattern hợp lý cho luồng vật lý (người quét hàng trước khi biết chắc tồn kho cuối cùng), không phải lỗ hổng dữ liệu sai vì Post Issue không bao giờ cho tồn âm.
- Không sửa code ở mục này — thêm `lockForUpdate()` vào `assertIssueAvailability()` sẽ giữ lock `inventory_balances` xuyên suốt cả quá trình quét vật lý (có thể kéo dài), gây nghẽn không cần thiết cho các warehouse khác cùng SKU; đây là quyết định thiết kế đã đúng, không tự ý mở rộng.

### 4. `inventory_balances.reserved_qty` — trường tồn tại trong schema nhưng không được nghiệp vụ nào cập nhật (ghi nhận, không sửa)

Xác nhận qua `grep` toàn bộ codebase: cột `reserved_qty` (migration `2026_01_01_000034_create_inventory_balances_table.php`, có CHECK constraint `>= 0`) chỉ được **hardcode `0`** ở 3 nơi insert balance mới (`CurrentBaselineStockService::mutateBalance()`, `CurrentBaselineReversalService`, `StockLedger`) — không có bất kỳ luồng nghiệp vụ nào tăng/giảm giá trị này khi item được match/scan vào outbound document. Nghĩa là hệ thống **không dùng mô hình soft-reserve cấp số lượng kiểu WMS chuẩn** (`on_hand = available + reserved`); cơ chế reservation thật của hệ thống là **item-level match record + row lock** (mục 2 ở trên), không phải cộng/trừ một cột số lượng.

**Không sửa** — đây không phải bug (không nơi nào đọc `reserved_qty` để ra quyết định nghiệp vụ sai; các endpoint đọc `InventoryBalanceController`/`OperationalReportController`/`ReportService` chỉ hiển thị giá trị luôn-0 này ra response, không dùng để tính available). Nhưng cần **ghi nhận rõ cho FE/BA**: nếu UI hiển thị `reserved_qty` với kỳ vọng phản ánh "số lượng đang được giữ chỗ bởi các outbound document DRAFT/SCANNING chưa Post", giá trị này **luôn là 0**, không đúng kỳ vọng đó — cần BA xác nhận có cần bổ sung tính năng cộng dồn `reserved_qty` theo item match ACTIVE hay field này chỉ dự phòng cho tương lai/không dùng.

### 5. Test mới bổ sung

File `tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` — 5 test case, toàn bộ lái qua HTTP thật:

| Test case | Xác nhận |
|---|---|
| `test_scanning_same_item_into_two_different_outbound_documents_is_rejected` | Item match thành công vào phiếu A; scan cùng item vào phiếu B (toa khác) → 409 `ITEM_ALREADY_MATCHED`; xác nhận đúng 1 match ACTIVE tồn tại, line B không tăng `scanned_qty` |
| `test_scanning_same_item_twice_into_same_document_is_rejected` | Scan lặp cùng 1 item vào cùng 1 document → 409 `DUPLICATE_ITEM_IN_DOCUMENT`; không tạo match thứ hai |
| `test_scanning_same_item_into_two_different_inbound_documents_is_rejected` | Đối xứng phía inbound — item `NEW` scan vào phiếu nhập A; scan cùng item vào phiếu nhập B → 409 `ITEM_ALREADY_RECEIVING` |
| `test_creating_outbound_document_from_already_consumed_request_leaves_single_active_document` | 2 request tạo document từ cùng 1 toa; request thứ hai → 409 `OUTBOUND_REQUEST_ALREADY_USED`; xác nhận toa chỉ có đúng 1 document active, toa chuyển `CONSUMED` |
| `test_item_removed_from_one_document_can_be_matched_into_another` | Item match vào A → unmatch (REMOVED) → match thành công vào B; xác nhận `ITEM_ALREADY_MATCHED` chỉ chặn match ACTIVE, không khóa vĩnh viễn item sau unmatch |

**Kết quả**: `php artisan test tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` → **5/5 passed, 35 assertions** ngay lần chạy đầu tiên — xác nhận toàn bộ guard reservation hiện có hoạt động đúng theo baseline, **không phát hiện bug chức năng nào** (khác với pattern bug thật ở WWHN-202/207 phía outbound balance/movement) — gap thực sự chỉ là thiếu test coverage, đã được lấp.

### 6. Test coverage — trước và sau

| Hạng mục | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `ITEM_ALREADY_MATCHED` (item trùng giữa 2 outbound document) qua HTTP thật | Không có | **Đã bổ sung** |
| `DUPLICATE_ITEM_IN_DOCUMENT` (item trùng trong cùng 1 document) qua HTTP thật | Không có | **Đã bổ sung** |
| `ITEM_ALREADY_RECEIVING` (item trùng giữa 2 inbound document) qua HTTP thật | Không có | **Đã bổ sung** |
| `OUTBOUND_REQUEST_ALREADY_USED` với xác nhận invariant "1 toa - 1 document active" | Có (tuần tự, không assert invariant tổng) | **Đã bổ sung** assert `activeDocuments == 1` |
| Item được gỡ (unmatch) có thể match lại vào document khác | Không có | **Đã bổ sung** |

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test outbound/inbound flow, concurrency, audit có sẵn):
`php artisan test tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/OutboundPermissionTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/PostReceiptAuditLogTest.php tests/Feature/Wwhn207InboundReversalFlowTest.php tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php`
→ **41/41 passed, 338 assertions**.

Kết quả chạy full suite (xác nhận không regression toàn cục): `php artisan test` → **388 tests, 372 passed, 16 skipped (baseline có sẵn, Postgres-gated, không liên quan tới thay đổi này), 0 failed, 4659 assertions**.

`vendor/bin/pint --test tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` → pass (sau 1 lần auto-fix thứ tự `use trait`, đã re-run xanh).

### 7. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` | Mới | 5 test case HTTP end-to-end cho quy tắc reservation cấp item (outbound + inbound) và cấp toa |

**Không sửa code sản xuất nào** — toàn bộ guard reservation/khóa đồng thời đã đúng theo baseline từ trước; rà soát chỉ phát hiện gap coverage (mục 2, 5) và 2 điểm cần BA/FE lưu ý (mục 3, 4), không phải bug.

### 8. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-199) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Kết quả rà soát đồng thời" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE1 | **Done** — rà soát toàn bộ quy tắc reservation cấp item và cấp toa, xác nhận đầy đủ, lấp gap coverage bằng 5 test case mới | Mục 1-2, 5 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — row lock (`item_instances`, `outbound_requests`), unique index Postgres-level (`ux_erd_outbound_active_item`, `uk_inbound_scan_active_item`), transaction bao trọn toàn bộ luồng scan/create đã xác nhận đầy đủ, không cần sửa | Mục 1-4 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — `ITEM_ALREADY_MATCHED`/`ITEM_ALREADY_RECEIVING`/`DUPLICATE_ITEM_IN_DOCUMENT`/`OUTBOUND_REQUEST_ALREADY_USED` đều là error code đã có, rõ ràng, xác nhận không tạo match/scan entry nào khi bị chặn (test assert đếm số row) | Mục 5 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 5 test case HTTP mới; **không cần cập nhật OpenAPI/Postman** vì không đổi endpoint/request/response shape nào (chỉ bổ sung test cho error code đã có sẵn trong contract từ WWHN-195) | Mục 5-6 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần QA/BA xác nhận độc lập, đặc biệt mục "reserved_qty"** — xem Blocker/Open point bên dưới | — |

### 9. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-RESV-OP-01 | `inventory_balances.reserved_qty` tồn tại trong schema/response API nhưng luôn `0`, không phản ánh số lượng đang được match/giữ chỗ bởi outbound document chưa Post — cần BA xác nhận có cần bổ sung tính năng cộng dồn theo item match ACTIVE, hay field này chỉ dự phòng và FE không nên hiển thị/diễn giải là "đang giữ chỗ" | BA/FE1 | Mở |
| BE1-RESV-OP-02 | Unique index Postgres-level cho reservation (`ux_erd_outbound_active_item`, `uk_inbound_scan_active_item`) chưa được chạy thật trên PostgreSQL trong phiên rà soát này (không có Postgres khả dụng cục bộ) — kế thừa nguyên trạng đã ghi nhận ở WWHN-205 (BE1-INV-OP-01); cần DevOps/QA xác nhận các unique index này còn tồn tại đúng sau khi migrate trên staging/CI Postgres | DevOps/QA | Mở (kế thừa) |
| BE1-RESV-OP-03 | Race-condition test đa connection thật (2 HTTP request đồng thời qua Node) cho riêng kịch bản "quét trùng item" chưa có — test mới trong task này mô phỏng đúng outcome mà 1 racer thua cuộc sẽ quan sát được (cùng phương pháp đã dùng ở `PostIssueConcurrencyBehaviorTest`, WWHN-195), không phải race đa connection thật; kế thừa giới hạn đã ghi nhận ở WWHN-218 (BE1-OP-04) và WWHN-205 (BE1-INV-OP-03) — chưa đóng | BE1 + DevOps | Mở (kế thừa) |

### 10. Định nghĩa Done — thực tế

#### BE1 technical completion (mục này)

Đạt: rà soát toàn bộ quy tắc reservation/khóa đồng thời cho luồng "lấy hàng/quét mã" (task cha WWHN-46) ở cả 2 cấp — item vật lý (`ITEM_ALREADY_MATCHED`, `DUPLICATE_ITEM_IN_DOCUMENT`, `ITEM_ALREADY_RECEIVING`, unmatch-rematch) và toa yêu cầu xuất (`OUTBOUND_REQUEST_ALREADY_USED`); xác nhận toàn bộ guard đã đúng theo baseline (row lock `item_instances`/`outbound_requests`, unique index Postgres-level cho `outbound_item_matches`/`inbound_scan_entries`), không phát hiện bug chức năng nào — khác với các task rà soát trước, gap thực sự chỉ nằm ở test coverage (chưa từng lái qua HTTP) và 1 điểm cần BA làm rõ (`reserved_qty` không được cập nhật, không phải bug nhưng có thể gây hiểu nhầm ở FE). 5 test case HTTP end-to-end mới, toàn bộ pass ngay lần đầu. Full suite 372/372 pass (16 skip baseline không liên quan) — không regression, không sửa code sản xuất nào.

#### Còn lại — không thuộc phạm vi tự nhận của BE1

- BA xác nhận disposition cho `reserved_qty` (BE1-RESV-OP-01) — có cần triển khai cộng dồn theo item match hay giữ nguyên field dự phòng.
- DevOps/QA xác nhận unique index Postgres-level cho reservation tồn tại đúng trên staging/CI (BE1-RESV-OP-02, kế thừa từ WWHN-205).
- BE1 + DevOps quyết định có bổ sung race-condition test đa connection thật cho kịch bản quét trùng item hay giữ nguyên mô phỏng tuần tự (BE1-RESV-OP-03, kế thừa từ WWHN-218/WWHN-205).
- QA xác nhận evidence (mục 5-8) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự ghi trong file này.
