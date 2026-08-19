[DEV] Hỗ trợ đối soát/availability

Description

Mục tiêu
Hỗ trợ đối soát/availability.

Input đầu vào
Task cha: WWHN-47 — [OUTBOUND] Ghi nhận xuất kho (Post Issue) atomic, không âm tồn và idempotent

Dependency: HN-OUT-003-ST01

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-OUT-003

Epic Temp Key: HN-EPIC-06

Dependency: HN-OUT-003-ST01

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria
Output "Balance hỗ trợ" được rà soát/xác minh, gắn evidence vào HN-OUT-003 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Balance hỗ trợ

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

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE2)

**Ghi chú traceability**: nội dung task này (mục tiêu, output "Balance hỗ trợ", parent HN-OUT-003, dependency HN-OUT-003-ST01) khớp với `docs/docs_task_jira/WWHN-207.md`. File này (WWHN-202) được cập nhật theo yêu cầu trực tiếp — evidence dưới đây là bản ghi đầy đủ cho output "Balance hỗ trợ" của HN-OUT-003, đối xứng với cách WWHN-191.md (BE2) đã ghi evidence cho output "Movement/balance hỗ trợ" của HN-IN-003 (task cha WWHN-43, phía inbound). WWHN-207.md giữ nguyên không sửa để bảo toàn baseline gốc.

**Exact commit SHA khi bắt đầu rà soát**: `ad688f7` (`[BE1] Triển khai chứng từ/chế độ/trạng thái xuất kho`, HEAD của `m1` tại thời điểm bắt đầu).

**Phạm vi output "Balance hỗ trợ"**: theo Input đầu vào (task cha HN-OUT-003, dependency HN-OUT-003-ST01), phạm vi BE2 là lớp đọc/hỗ trợ đối soát dựng trên cùng ledger `stock_movements` (nguồn sự thật, append-only) và `inventory_balances` (projection) — **dùng chung code với phía inbound đã rà soát ở WWHN-191** — nhưng lần này rà soát trực tiếp theo luồng **outbound/Post Issue** (BE1 vừa triển khai ở `ad688f7`), việc mà WWHN-191 chưa từng lái qua HTTP:

1. `GET /api/v1/inventory/balances` — tồn kho hiện tại sau khi ISSUE giảm tồn, kèm đối soát ledger (`ledger_total`, `reconciliation_status`, `variance`, `incident_id`).
2. `GET /api/v1/inventory/balances/{id}/subledger` — drill-down `item_instances` phải loại trừ item vừa ISSUED.
3. `GET /api/v1/stock-movements`, `/{id}`, `/{id}/trace-source`, `/trace-by-code`, `/reversible` — lịch sử biến động ISSUE, trace về outbound document, và listing cho reverse.
4. `GET /api/v1/reports/inventory-reconciliation` — báo cáo đối soát on-demand sau Post Issue.
5. `php artisan inventory:reconcile` — đối soát chủ động sau Post Issue, không tạo incident giả khi ledger khớp.
6. `POST /api/v1/outbound-documents/{id}/reverse` — xác nhận reverse của ISSUE cũng được phản ánh đúng ở balance/movement trace (Post Issue → Reverse là cặp nghiệp vụ đối xứng của Post Receipt → Reverse mà WWHN-191 không cover).

### 1. Rà soát bằng cách gọi API thật qua HTTP test client (không chỉ đọc source code)

Theo đúng phương pháp đã dùng ở WWHN-191: viết test lái qua **flow HTTP thật** (`POST /outbound-requests` (fixture) → `/outbound-documents` → `/scan` → mark label PRINTED → `/post-issue`) rồi gọi trực tiếp từng endpoint đọc, thay vì chỉ đọc code — đã phát hiện **2 lỗi thật** trong `StockMovementController` (dùng chung cho cả inbound lẫn outbound, nhưng chỉ lộ ra khi test theo nhánh outbound/serial-tracked-item):

- **Bug 1 — `GET /api/v1/stock-movements/reversible` trả 500** khi trong danh sách có document serial-tracked (mọi outbound PRODUCT đều serial-tracked). Nguyên nhân: `StockMovementController::buildItemDependencyMap()` (dòng 367-383 bản cũ) join `item_instances.status` — cột này **không tồn tại** trên schema thật; cột thật là `item_status` (khớp `Modules/Warehouse/app/Models/ItemInstance.php:15,23`, và mọi call site khác trong codebase đều dùng `item_status`). Điều tra sâu hơn cho thấy `status`/`id` trên `item_instances` từng tồn tại như **compatibility alias cột** được backfill một lần bởi migration `2026_08_10_000760_align_erd_1_0_fields_types_and_data.php` — nhưng migration đó có `if (DB::getDriverName() !== 'pgsql') { return; }` (chỉ chạy trên Postgres) **và** là one-time backfill, không có trigger đồng bộ liên tục. Nghĩa là: (a) trên SQLite (test suite) cột `status` không tồn tại → crash ngay; (b) trên Postgres production, cột `status` tồn tại nhưng **chỉ đúng tại thời điểm migration chạy** — mọi `item_instances` row tạo sau đó (tức toàn bộ hoạt động outbound/inbound từ đó về sau) sẽ có `status` là NULL/stale, không đồng bộ với `item_status` thật. Đây là lỗi thật đang tồn tại trên production, không phải chỉ do khác biệt SQLite/Postgres của test harness. Cùng lỗi ở cả 2 nhánh join (`blockedByInbound` và `blockedByOutbound`); đồng thời `item_instances.id` (thay vì PK thật `item_id`) trong điều kiện JOIN có cùng vấn đề, chỉ khác là `id` bị alias sớm hơn trong danh sách `backfillSameTableAliases()` nên ít lộ ra hơn `status` (đã sửa cả hai để dùng cột chuẩn `item_id`, nhất quán với toàn bộ codebase còn lại).
- **Bug 2 — `GET /api/v1/stock-movements/trace-by-code` trả `movements: []` (rỗng) cho item đã ISSUED**, dù chính item đó vừa có 1 `stock_movements` (ISSUE) được ghi khi Post Issue. Nguyên nhân: `traceByPhysicalCode()` lọc `stock_movements.item_id = $resolvedId`, nhưng `CurrentBaselineStockService::mutateBalance()` — hàm duy nhất ghi `stock_movements` — ghi **1 dòng movement cho mỗi document line (số lượng gộp)**, không phải 1 dòng cho mỗi item; tham số `mutateBalance()` không hề nhận `item_id`, nên cột `stock_movements.item_id` luôn là `NULL` cho mọi movement do Post Receipt/Post Issue tạo ra (xác nhận bằng cách dump trực tiếp bản ghi `stock_movements` sau khi Post Issue trong lúc viết test). Cột `stock_movements.item_id`/`container_id` được thêm có chủ đích bởi migration ERD baseline (`2026_08_10_000600_enforce_current_erd_business_contract.php:133,157`, có FK constraint) — tức tính năng trace theo item được thiết kế nhưng chưa từng được ghi dữ liệu ở tầng write, khiến `trace-by-code` (endpoint hỗ trợ đối soát/truy vết theo mã vật lý) không thể trả về lịch sử movement của item dù response 200 (không crash, chỉ sai dữ liệu — khó phát hiện hơn Bug 1 nếu không test path "tìm thấy").

Ghi chú kỹ thuật (khớp phát hiện của BE1 ở WWHN-190/204 và BE2 ở WWHN-191): 2 controller `InventoryBalanceController`/`StockMovementController` dùng chung cho cả inbound lẫn outbound — không có code trùng tên riêng cho outbound trong phạm vi output này.

### 2. Đã sửa (đúng phạm vi — chỉ sửa 2 bug trong lớp đọc "Balance hỗ trợ", không đụng transaction/posting logic của BE1)

`Modules/Warehouse/app/Http/Controllers/StockMovementController.php`:

- `buildItemDependencyMap()`: đổi `item_instances.status` → `item_instances.item_status`, `item_instances.id` → `item_instances.item_id` ở cả 2 nhánh join (inbound dependency guard, outbound dependency guard) — khớp cột chuẩn dùng nhất quán trong toàn bộ codebase. Không đổi tên cột trả về response (`guard_reason`, `dependency_guard`) — contract JSON giữ nguyên.
- `traceByPhysicalCode()`: khi lookup `movements` cho `resolved_type = ITEM` theo `stock_movements.item_id` trả rỗng (trường hợp luôn xảy ra với mọi movement do Post Receipt/Post Issue tạo, vì lý do nêu ở Bug 2), fallback sang resolve line mà item đã được scan/match vào (`inbound_scan_entries.item_id` hoặc `outbound_item_matches.item_id` → `outbound_line_id`/`inbound_line_id`), rồi lấy `stock_movements` theo `stock_document_line_id` của (các) line đó. Đây là **read-side fix trong phạm vi BE2** (không sửa `mutateBalance()`/`postStockDocument()` — thuộc transaction/posting logic của BE1, ngoài phạm vi output "Balance hỗ trợ"); nếu tương lai write-side được cập nhật để ghi `item_id` trực tiếp, nhánh match trực tiếp (đã giữ nguyên, chạy trước fallback) sẽ tự động ưu tiên dùng, không cần sửa lại chỗ này. Response JSON shape giữ nguyên 100% (cùng field `movements`) — không cần cập nhật OpenAPI/Postman.

Không phát hiện thêm lỗi nào khác trong 2 controller sau khi rà soát toàn bộ method còn lại theo nhánh outbound (`index`, `subledger`, `reversible` phần còn lại, `trace-source`).

**Không sửa** (đã cân nhắc, không thuộc phạm vi/không phải bug):
- `container_items.is_active` (dùng trong `subledger()` cũ theo fix của WWHN-191, và trong `traceByPhysicalCode()`): xác nhận đây là cột thật, có default, có unique index phụ thuộc (`uk_container_items_one_active_item`), và được `CurrentBaselineContainerFlowController` ghi đồng bộ cùng `status` ở mọi insert/update — khác với case `item_instances.status` (chỉ backfill 1 lần, không đồng bộ tiếp). Không phải bug.
- `physical_codes.item_id`/`container_id` đọc trong `traceByPhysicalCode()` (dòng 140-149, nhánh đầu trước khi fallback `assigned_object_type`/`assigned_object_id`): xác nhận 2 cột này không tồn tại trên schema thật (khớp phát hiện của WWHN-191 cho `subledger()`) nên luôn `null`, code rơi xuống nhánh `assigned_object_type`/`assigned_object_id` đang hoạt động đúng — dead code vô hại, không phải bug chức năng vì response cuối vẫn đúng. Không sửa vì không có nguy cơ sai dữ liệu, tránh mở rộng phạm vi.

### 3. Test coverage — trước và sau

File test mới: `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` — 8 test case, toàn bộ dẫn qua flow HTTP thật (`POST /outbound-documents` → `/scan` → mark label PRINTED → `/post-issue`, một số case tiếp `/reverse`), không mock/stub:

| Test case | Trạng thái trước | Trạng thái sau | Phát hiện |
|---|---|---|---|
| `test_inventory_balances_list_reflects_ledger_after_post_issue_and_matches` | Không có | Đã bổ sung | Xác nhận `on_hand_qty`/`available_qty` giảm đúng, `reconciliation_status=MATCH` sau ISSUE (dùng fixture ledger-backed — xem ghi chú bên dưới) |
| `test_stock_movements_list_show_and_trace_source_reflect_posted_issue` | Không có | Đã bổ sung | List filter theo `movement_type=ISSUE`, detail đúng `qty_delta=-1`, trace-source resolve đúng outbound document |
| `test_stock_movement_trace_by_code_resolves_issued_item_and_its_movement` | Không có | Đã bổ sung | **Phát hiện Bug 2** — trước khi sửa, `movements: []` sai |
| `test_stock_movements_reversible_lists_posted_issue_with_dependency_guard` | Không có | Đã bổ sung | **Phát hiện Bug 1** — trước khi sửa, HTTP 500 |
| `test_reconciliation_report_and_command_show_match_after_post_issue` | Có (test khác, generic) | Đã bổ sung case riêng cho outbound | Xác nhận `inventory:reconcile` không tạo incident giả sau Post Issue hợp lệ |
| `test_reverse_after_post_issue_is_reflected_in_balances_and_movement_trace` | Không có | Đã bổ sung | Reverse trả tồn về đúng, `reversal_movement` trace đúng qua `trace-source` |
| `test_inventory_balance_subledger_excludes_issued_item_after_post` | Không có | Đã bổ sung | Item ISSUED bị loại khỏi subledger, item còn IN_STOCK vẫn hiện |
| `test_stock_movements_endpoints_require_inventory_permissions_for_outbound_data` | Có (dạng khác, không gắn dữ liệu outbound thật) | Đã bổ sung case dùng dữ liệu outbound thật | 403 `ACCESS_DENIED` khi thiếu quyền |

**Ghi chú kỹ thuật quan trọng về fixture** (để QA/BA không hiểu nhầm là bug khi review test): `PreparesOutboundFixtures::seedAvailableBalance()` (dùng chung bởi nhiều test outbound có sẵn) chèn thẳng 1 dòng `inventory_balances` mở đầu (opening balance) mà **không** kèm `stock_movements` tương ứng — hợp lý cho test chỉ cần "có tồn sẵn", nhưng khiến `reconciliation_status` của dòng đó **luôn** là `MISMATCH` một cách chính đáng (ledger thiếu đúng phần opening balance không qua ledger) — đây là hành vi đúng của tính năng đối soát (bắt được balance không có nguồn gốc ledger), không phải bug. Test nào cần xác minh `MATCH` dùng `seedLedgerBackedBalance()` (thêm trong file test mới) — chèn kèm 1 `stock_movements` RECEIPT cho phần seed, khớp cách balance thật luôn được tạo (qua Post Receipt, không bao giờ insert thẳng) trong production.

Kết quả chạy: `php artisan test tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` → **8/8 passed, 71 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + test outbound có sẵn + test đối soát có sẵn của WWHN-191/219): `php artisan test tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php tests/Feature/Wwhn191BalanceMovementProjectionTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/OutboundDocsAlignmentContractTest.php tests/Feature/OutboundPermissionTest.php tests/Feature/InventoryReconciliationIntegrityTest.php tests/Feature/InventoryReconciliationSignedStatusTest.php tests/Feature/Wwhn219InventoryReconcileCommandTest.php` → **49/49 passed, 342 assertions**.

Kết quả chạy full suite (xác nhận không có regression toàn cục từ 2 fix trong `StockMovementController`): `php artisan test` → **367 tests, 351 passed, 16 skipped (baseline có sẵn, không liên quan tới thay đổi này), 0 failed, 4497 assertions**.

### 4. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-207) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Balance hỗ trợ" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát phát hiện 2 bug thật theo nhánh outbound (không phải thiếu tài liệu), đã sửa và có test | Mục 1-3 ở trên |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — permission `inventory.view` đã test hành vi thật (403) trên dữ liệu outbound; các endpoint này là read-only (GET), không có giao dịch/idempotency/concurrency riêng — dữ liệu được bảo vệ ở tầng ghi (đã cover ở WWHN-204/205, BE1); `StockMovement` append-only đã cover ở WWHN-191 (dùng chung model) | Test case 4, 8 trong `Wwhn207OutboundBalanceReconciliationTest` |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — sau khi sửa Bug 1, không còn 500 lộ exception; sau khi sửa Bug 2, `trace-by-code` không còn trả dữ liệu sai (rỗng) cho item hợp lệ; 404/403/401 đã cover ở WWHN-191 (dùng chung controller) | Mục 1-2 ở trên |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 8 test case HTTP end-to-end mới; **không cần cập nhật OpenAPI/Postman** vì response JSON shape giữ nguyên (chỉ sửa nguồn dữ liệu nội bộ, không đổi field nào) | `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php` |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/QA/FE xác nhận độc lập** — phía BE2 không còn blocker kỹ thuật. **Lưu ý quan trọng cho QA**: Bug 1 (`GET /stock-movements/reversible` 500 khi có document serial-tracked) là lỗi P0 thực sự đang tồn tại trên baseline trước khi sửa, ảnh hưởng UI "reverse" cho mọi outbound PRODUCT (luôn serial-tracked); Bug 2 (`trace-by-code` trả `movements: []` sai cho item) là P1 (không crash nhưng sai dữ liệu đối soát/truy vết) — cần re-test lại sau khi merge fix này | Mục 1-2 ở trên |

### 5. Phạm vi đã xác nhận và điểm còn mở (không tự ý mở rộng)

- **Không sửa** `mutateBalance()`/`postStockDocument()` (`CurrentBaselineStockService.php`) dù xác nhận đây là nguyên nhân gốc khiến `stock_movements.item_id` luôn `NULL` — thuộc transaction/posting logic (phạm vi BE1, đã rà soát ở WWHN-190/204/205), ngoài phạm vi output "Balance hỗ trợ" của BE2. Fix ở đây là read-side fallback trong `traceByPhysicalCode()`, tự động ưu tiên dùng `item_id` trực tiếp nếu write-side được cập nhật sau này.
- **Không sửa** `SafeStockConfig`/`ReportController` dead-code đã ghi nhận ở WWHN-190/191 — không phát sinh thêm vấn đề mới liên quan outbound trong lần rà soát này, giữ nguyên khuyến nghị cũ (cần task riêng do BA/PM xác nhận).
- `GET /api/v1/stock-movements/reversible` (case `search` keyword, case không `reversible_only`) và `container_items` counting trong `subledger()` cho outbound: đã rà soát source, không phát hiện thêm lỗi schema; chưa có test HTTP riêng cho các nhánh phụ này (chỉ test nhánh chính đã liệt kê ở mục 3) — khuyến nghị task riêng nếu QA cần evidence sâu hơn.
- Không phát hiện blocker kỹ thuật P0/P1 nào còn mở phía BE2 tại thời điểm rà soát, sau khi đã sửa Bug 1 và Bug 2.

### 6. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: 2 bug thật trong `StockMovementController` (dùng chung inbound/outbound) được phát hiện và sửa nhờ test theo nhánh outbound/serial-tracked-item mà WWHN-191 (nhánh inbound) chưa từng lái qua HTTP — giữ nguyên contract JSON. 8 test case HTTP end-to-end mới cho toàn bộ output "Balance hỗ trợ" phía outbound (`inventory/balances`, `subledger`, `stock-movements` list/show/trace-source/trace-by-code/reversible, reconciliation report + command, reverse). Test coverage liên quan có sẵn (WWHN-191, PostIssue*, OutboundDocumentFlow, InventoryReconciliation*, Wwhn219) re-run xanh không regression. Full suite 351/351 pass (16 skip baseline không liên quan) tại HEAD `ad688f7` + các thay đổi của task này.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (mục 3-4 ở trên) đáp ứng đúng Acceptance Criteria, đặc biệt re-test `GET /stock-movements/reversible` (Bug 1, P0) và `GET /stock-movements/trace-by-code` (Bug 2, P1) sau khi có fix.
- BA xác nhận có cần task riêng để bổ sung `item_id` trực tiếp vào `stock_movements` ở tầng write (BE1 scope) hay giữ nguyên fallback read-side hiện tại là đủ.
- FE1 xác nhận response shape của `inventory/balances`, `subledger`, `stock-movements*` đủ để tích hợp UI đối soát/reverse phía outbound.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.

---

## 7. AMENDMENT — vòng re-review 2026-08-19 (đóng DEV202-P1-01R2 … P2-01R2)

> **Đọc mục này trước mục 1-6.** Ba tuyên bố trong phần evidence 2026-08-15 ở trên đã
> **lỗi thời** so với code hiện tại. Chúng được giữ nguyên làm dấu vết lịch sử, không bị
> xoá; mục 7 là nguồn đúng khi hai bên mâu thuẫn.

### 7.1. Đính chính nội dung (DEV202-P1-04R2 — "Evidence contradicts current code")

| Tuyên bố ở mục 1-6 | Trạng thái | Sự thật tại thời điểm re-review |
|---|---|---|
| "`stock_movements.item_id` **luôn** là NULL cho mọi movement do Post Receipt/Post Issue tạo ra" (mục 1, Bug 2) | **SUPERSEDED** | Sai kể từ `WWHN-205`. `CurrentBaselineStockService::resolveLineItemId()` **có** gán `item_id` khi line được back bởi **đúng một** item — là trường hợp phổ biến nhất (PRODUCT serial-tracked, `qty_planned = 1`). |
| "Không sửa `mutateBalance()`/`postStockDocument()` — thuộc phạm vi BE1, ngoài phạm vi BE2" (mục 2 và mục 5) | **ĐÃ THỰC HIỆN Ở TASK KHÁC** | Phần write-side đã được BE1 đóng tại `WWHN-205` (`Wwhn205StockMovementItemIdInvariantTest`, 4 ca). Điểm mở mà mục 5 nêu ("BA xác nhận có cần task riêng…") **đã được trả lời bằng code**, không còn treo. |
| "Fix ở đây là read-side fallback… nếu tương lai write-side được cập nhật thì nhánh trực tiếp sẽ tự động ưu tiên" (mục 2) | **VẪN ĐÚNG** | Đúng và **đã xảy ra**. Cả hai nhánh của `traceByPhysicalCode()` đều còn sống và cần thiết: nhánh trực tiếp cho line 1 item, nhánh fallback cho line gộp nhiều item. Verifier check C4 canh không cho gỡ nhánh nào. |

**Quy tắc chính thức thay cho mô tả cũ** — `DEC-STK-202` §D-2
(`docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md`):

> Line có **đúng một** item → `movement.item_id` = item đó.
> Line gộp **nhiều** item (`qty_planned > 1`, serial-tracked) hoặc **không** item nào
> (COMPONENT / SKU quantity-mode) → `item_id` **null hợp lệ**, và `trace-by-code` phải
> fallback theo `stock_document_line_id`.

Lý do quy tắc phải là "null hợp lệ" chứ không phải "khiếm khuyết cần vá": một dòng ledger
không thể trung thực quy về nhiều hơn một serial vật lý. Ngoài ra `stock_movements` có
trigger append-only (`HN-WMS-ERD-DD-001`) chặn mọi `UPDATE`, nên **không tồn tại** phương án
"backfill `item_id` sau khi post" — điều này được xác nhận bằng cách thử thật và nhận
`SQLSTATE[23000] … stock_movements is append-only` trong lúc viết test.

Hồi quy khoá hai vế:

| Vế | Test |
|---|---|
| write, 1 item / >= 2 item / quantity-mode | `Wwhn205StockMovementItemIdInvariantTest` (4 ca, BE1) |
| read, line 1 item dùng nhánh trực tiếp | `Wwhn202OutboundBalanceEvidenceTest::test_movement_item_attribution_follows_dec_stk_202` |
| read, line gộp 2 item → `item_id` null **và** trace-by-code vẫn resolve | `Wwhn202OutboundBalanceEvidenceTest::test_aggregate_line_leaves_item_id_null_and_trace_by_code_falls_back_to_the_line` |

`ATT-11845` phải được đọc kèm mục 7.1 này; phần mô tả "item_id luôn NULL" trong attachment
đó là ảnh chụp trạng thái **trước** `WWHN-205`, không phải mô tả hệ thống hiện tại.

### 7.2. Nhánh và evidence (DEV202-P1-01R2)

- `m1` là nhánh canonical; `m1-be-2` là **ancestor thuần** của `m1` (đo: behind 0 / ahead 765).
  Supersede comment 14283 không mất commit nào. Chi tiết + lệnh đo:
  `docs/evidence/WWHN-202/EVIDENCE_MATRIX.md` §1.1.
- Evidence được ánh xạ về WWHN-202 bằng annotation `@group wwhn-202` (ràng buộc cơ học),
  **không** đổi tên `Wwhn207OutboundBalanceReconciliationTest.php` — 8 evidence pack đã đóng
  băng đang pin đúng đường dẫn đó. Lý do đầy đủ: `EVIDENCE_MATRIX.md` §1.4.
- PR = N/A vì `m1` là nhánh chung có nhiều phiên song song; thay bằng verifier chạy lại được
  + log thô hai driver. Xem `EVIDENCE_MATRIX.md` §1.2.

### 7.3. Phân lớp 409 vs 422 (DEV202-P1-03R2)

Chính thức hoá tại `DEC-STK-202` §D-1, khớp `docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json`.
Điểm cần nhớ: **hết tồn là 422 `INSUFFICIENT_STOCK`**, không phải 409. Khoá bằng
`Wwhn202OutboundBalanceEvidenceTest::test_post_issue_error_taxonomy_matches_srs_8_2`
(kiểm 412 / 200-replay / 409 / 422 trong một lượt).

Bằng chứng PostgreSQL (không chỉ sqlite): 43 test pass / 457 assertion trên PostgreSQL 16,
`migrate:fresh --seed` + `inventory:reconcile --dry-run` cho **0 lệch**, và 6 truy vấn
`RECONCILIATION.sql` đều trả 0 dòng. Log thô trong `docs/evidence/WWHN-202/`.

### 7.4. Hợp đồng FE/QA (DEV202-P1-05R2)

Pin tại `docs/evidence/WWHN-202/CONTRACT_PIN.json` theo **slice 8 operation** (không pin cả
file `api-docs.json` vì file đó sinh tự động cho toàn hệ thống và sẽ mục hằng ngày).
Phát hiện thật: bộ Postman DEV chỉ có **4/8** bề mặt — QA1 không thể retest `subledger`,
`trace-by-code`, `reversible`, `inventory-reconciliation`. Đã bổ sung 4 request sinh từ
chính OpenAPI. Nửa FE: `src/shared/api/wwhn-202-balance-contract-pin.ts` +
`src/test/wwhn-202-balance-contract-pin.test.ts` (22 assertion).

### 7.5. Ghi chú governance (DEV202-P2-01R2)

Phần "Vai trò và phối hợp" ở đầu file ghi *BE2 — Duy Khánh Nguyễn / HN-BE-02*. Giữ nguyên
làm dấu vết phân công ban đầu (register review đối chiếu chéo BE1/BE2/QA1/QA2, xoá đi sẽ
làm hỏng truy vết). Quyền sở hữu hiện hành: **đội DEV hợp nhất**, assignee đang hoạt động
chịu trách nhiệm giao nộp. Không sửa lịch sử comment.

### 7.6. Vẫn không tự đánh Done

Phần DEV đã đóng và chạy lại được. Ba xác nhận còn thiếu, đúng như mục 6 cũ đã nêu:
BA (quy tắc `DEC-STK-202`), Tech Lead (candidate SHA + contract pin), QA1 (`WWHN-203`
retest theo `docs/evidence/WWHN-202/QA1_RETEST_PACK_WWHN-203.md`).
