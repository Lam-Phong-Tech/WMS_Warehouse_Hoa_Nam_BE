[DEV] Triển khai giao dịch xuất kho/lock/idempotency
Mục tiêu
Triển khai giao dịch xuất kho/lock/idempotency.

Input đầu vào
Task cha: WWHN-47 — [OUTBOUND] Ghi nhận xuất kho (Post Issue) atomic, không âm tồn và idempotent

Dependency: HN-DEC-002; HN-DEC-005; HN-OUT-002

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-OUT-003

Epic Temp Key: HN-EPIC-06

Dependency: HN-DEC-002; HN-DEC-005; HN-OUT-002

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "ghi nhận xuất kho (Post Issue) backend" được rà soát/xác minh, gắn evidence vào HN-OUT-003 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
ghi nhận xuất kho (Post Issue) backend

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

**File WWHN-201.md trống hoàn toàn (0 byte) tại thời điểm bắt đầu rà soát.** Task cha **WWHN-47**, và dependency **HN-DEC-002**, **HN-DEC-005** không tìm thấy ở bất kỳ đâu trong repo (đã grep toàn bộ, loại trừ `vendor`). **HN-OUT-002** đã có baseline đầy đủ qua WWHN-199 (`docs/docs_task_jira/WWHN-199.md`). Toàn bộ nội dung task phía trên (Mục tiêu/AC/DoD...) được phục hồi nguyên trạng từ JIRA description do người dùng cung cấp trong yêu cầu — không suy đoán thêm nội dung ngoài đó.

**Quan hệ với các task rà soát trước đó**: task cha của WWHN-201 (**HN-OUT-003** — "Post Issue atomic, không âm tồn và idempotent") có scope **hẹp và sâu**, tập trung đúng 1 endpoint: `POST /outbound-documents/{id}/post-issue`. Phần lớn hạ tầng transaction/idempotency/concurrency cho outbound (bao gồm chính endpoint Post Issue) đã được rà soát và bổ sung test ở **WWHN-195** (task cha HN-OUT-001, tầng document rộng — create/scan/post/cancel/reverse) và **WWHN-199** (task cha HN-OUT-002, tầng reservation cấp item/toa). Task này rà soát **lại chuyên sâu đúng phạm vi tên gọi HN-OUT-003**: atomic (transaction rollback toàn phần khi bị chặn), không âm tồn (tại nguồn sự thật cuối cùng — Post Issue time, không phải soft-check tại scan time), và idempotent (replay trả đúng kết quả cũ, không side-effect kép) — xác nhận độc lập bằng test mới thay vì chỉ kế thừa kết luận từ 2 task trước.

### Phương pháp

Đọc trực tiếp `CurrentBaselineStockFlowController::postDocument()`/`postIssue()` (dòng 145-252), `CurrentBaselineStockService::postStockDocument()`/`mutateBalance()` (dòng 110-212, 793-903), toàn bộ 4 file test outbound hiện có (`OutboundDocumentFlowTest`, `PostIssueConcurrencyBehaviorTest`, `PostIssueAuditLogTest`, `OutboundPermissionTest`) và test reservation (`Wwhn199ReservationConcurrencyGuardTest`) để lập bản đồ chính xác những gì đã được chứng minh qua HTTP thật, từ đó xác định phần chưa được chứng minh. Xác nhận route thật (`routes/api.php:82`) trỏ tới `CurrentBaselineStockFlowController::postIssue`, không phải `StockDocumentService::postIssue`/`CurrentBaselineStockController::postIssue` (2 class đó không nằm trong route thực thi cho outbound post-issue — cùng pattern dead-code đã ghi nhận ở WWHN-195/190).

### 1. Atomic — ĐẦY ĐỦ

`postDocument()` bọc toàn bộ luồng Post Issue trong `DB::transaction(..., 3)` (controller dòng 213-239), lồng bên trong còn `postStockDocument()` cũng tự có `DB::transaction(..., 3)` riêng (Laravel gộp thành 1 transaction vật lý qua savepoint). Trong cùng 1 transaction: lock document (`lockForUpdate`), version check, state check (`SCANNING`), `OUTBOUND_REQUEST_REQUIRED`, `assertLabelsPrinted()`, version bump, `mutateBalance()` (row lock `inventory_balances` + insert `stock_movements`), `applyIssueSubledger()`, update `stock_documents.status = POSTED`, ghi `document_status_transitions`, ghi `idempotency_keys`/`posting_events`, ghi `audit_log`. Bất kỳ bước nào `ApiError::abort()` đều rollback toàn bộ — không có trạng thái "POSTED một phần" hay "movement tạo nhưng document chưa cập nhật".

### 2. Không âm tồn — ĐẦY ĐỦ ở 2 lớp, đã bổ sung test cho lớp quan trọng nhất chưa từng được chứng minh qua HTTP

**Lớp 1 — soft-check tại scan time** (`assertIssueAvailability`, không `lockForUpdate`): đã rà soát và xác nhận đầy đủ ở WWHN-199 mục 3 — là "early feedback", không phải nguồn sự thật.

**Lớp 2 — hard-check tại Post Issue time** (`mutateBalance()` dòng 848-858): `lockForUpdate()` thật trên `inventory_balances`, tính `after = before + sign*quantity`, `ApiError::abort(422, 'INSUFFICIENT_STOCK', ...)` nếu `after < 0`, và riêng `INSUFFICIENT_AVAILABLE_STOCK` nếu `available_qty` sau delta < 0. Đây là nguồn sự thật cuối cùng — **không nơi nào trong hệ thống có thể ghi âm tồn** vì mọi write-path outbound đều đi qua đúng hàm này.

**Gap tìm thấy trước khi rà soát**: `OutboundDocumentFlowTest::test_...insufficient_stock...` (dòng 186) chỉ verify `INSUFFICIENT_STOCK` **tại scan time** (item không đủ tồn để scan). Không có test HTTP nào dựng đúng kịch bản "2 document cùng SKU/warehouse đều scan xong SCANNING hợp lệ (mỗi cái pass soft-check riêng), nhưng tổng vượt tồn thực — document Post trước thắng, document Post sau phải bị chặn tại `mutateBalance()`". Đây chính xác là ý nghĩa "atomic, không âm tồn" trong tên task cha HN-OUT-003 — chưa từng được lái qua HTTP thật ở WWHN-195/199.

**Đã bổ sung**: `test_second_post_issue_is_rejected_with_insufficient_stock_when_first_post_already_consumed_the_shared_balance` (file mới, xem mục 5) — dựng 2 document A/B cùng SKU/warehouse, tồn chỉ đủ 1 đơn vị, cả 2 scan xong SCANNING bình thường; Post A thành công trừ hết tồn (`available_qty=0`, `quantity=0`); Post B bị chặn 422 `INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK`, xác nhận: không tạo thêm `stock_movement`, `inventory_balances` không bị trừ âm (vẫn `0`, không phải `-1`), document B giữ nguyên `SCANNING`/`version=2` (không đổi trạng thái khi bị chặn — đúng ngữ nghĩa atomic).

### 3. Idempotent — ĐẦY ĐỦ, đã bổ sung test verify side-effect (không chỉ audit log)

Cơ chế: `IdempotencyKeyGuard::require()` bắt buộc header `Idempotency-Key` ở Post Issue (thiếu → 428 `PRECONDITION_REQUIRED`); `IdempotencyStore::handle()` cache theo `(idem_key, operation='post_issue_{id}', user_id, request_hash)`, trả lại response đã cache kèm header `Idempotent-Replay: true` nếu đã tồn tại, không chạy lại closure business logic.

**Đã có trước** (WWHN-195): `PostIssueAuditLogTest::test_post_issue_writes_single_audit_log_row_and_replay_does_not_duplicate_it` — verify replay không tạo thêm `audit_log` row.

**Gap tìm thấy → đã đóng**: chưa có test verify trực tiếp **side-effect nghiệp vụ** (không chỉ audit log) không bị nhân đôi khi replay — cụ thể `inventory_balances.available_qty`/`quantity` và `stock_movements` count. Về lý thuyết `IdempotencyStore::handle()` không chạy lại closure nên không thể có side-effect kép, nhưng đây là invariant cốt lõi nhất của "Post Issue idempotent" (tên task cha) nên cần bằng chứng trực tiếp, không suy luận gián tiếp qua audit log.

**Đã bổ sung**: `test_replaying_post_issue_with_same_idempotency_key_returns_identical_result_without_creating_a_second_movement` — Post lần 1 (`Idempotent-Replay: false`), ghi nhận `movement_ids`/`available_qty`/`quantity`/`stock_movements` count; Post lần 2 cùng key (`Idempotent-Replay: true`), xác nhận `movement_ids` giống hệt, balance không đổi, `stock_movements` count không đổi.

### 4. Concurrency guard (row lock, optimistic lock, business-key) — ĐẦY ĐỦ, kế thừa nguyên trạng từ WWHN-195/199

Không phát hiện gap mới ngoài mục 2-3 ở trên. Đã xác nhận lại (không sửa): `If-Match`/optimistic lock (412 `PRECONDITION_FAILED`, thiếu header → 428), row lock `stock_documents`/`inventory_balances`/`outbound_requests`/`item_instances`, `business_key` unique trên `stock_movements` (409 `DUPLICATE_STOCK_EFFECT`), state guard (`SCANNING` mới Post được, 409 `STATE_CONFLICT` khi vi phạm) — toàn bộ đã có test HTTP thật từ `PostIssueConcurrencyBehaviorTest` (WWHN-195).

### 5. Test mới bổ sung

File `tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php` — 2 test case, toàn bộ lái qua HTTP thật:

| Test case | Xác nhận |
|---|---|
| `test_second_post_issue_is_rejected_with_insufficient_stock_when_first_post_already_consumed_the_shared_balance` | 2 document cùng SKU/warehouse, cùng SCANNING hợp lệ; Post A thắng, Post B bị 422 `INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK` tại **Post Issue time** (khác gap coverage cũ chỉ có ở scan time); không tạo movement thừa; balance không âm; document B không đổi trạng thái |
| `test_replaying_post_issue_with_same_idempotency_key_returns_identical_result_without_creating_a_second_movement` | Replay đúng Idempotency-Key trả `movement_ids` giống hệt lần Post gốc; `inventory_balances`/`stock_movements` không bị tác động lần hai |

**Kết quả**: `php artisan test tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php` → **2/2 passed, 25 assertions** ngay lần chạy đầu tiên — xác nhận guard atomic/không-âm-tồn/idempotent tại Post Issue hoạt động đúng theo baseline, **không phát hiện bug chức năng nào** — gap thực sự chỉ là thiếu test coverage ở đúng 2 điểm hẹp (INSUFFICIENT_STOCK tại post-time, side-effect verify cho idempotent replay), đã được lấp.

`vendor/bin/pint --test tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php` → pass (sau 1 lần auto-fix thứ tự import/trait, đã re-run xanh, test vẫn 2/2 pass).

### 6. Test coverage — trước và sau

| Hạng mục | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `INSUFFICIENT_STOCK` tại scan time | Có (WWHN-195, `OutboundDocumentFlowTest`) | Không đổi |
| `INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK` tại **Post Issue time** (nguồn sự thật cuối cùng, race 2 document) qua HTTP thật | **Không có** | **Đã bổ sung** |
| Idempotent replay không nhân đôi `audit_log` | Có (WWHN-195, `PostIssueAuditLogTest`) | Không đổi |
| Idempotent replay không nhân đôi `stock_movements`/không đổi `inventory_balances` (side-effect nghiệp vụ trực tiếp) | **Không có** | **Đã bổ sung** |
| Atomic rollback: document giữ nguyên state/version khi Post bị chặn giữa chừng | Ngầm định qua state guard test khác | **Đã bổ sung xác nhận trực tiếp** trong kịch bản race thật |

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test outbound flow/concurrency/audit/permission/reservation có sẵn):
`php artisan test tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/OutboundPermissionTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php tests/Feature/Wwhn46OutboundPickScanDedupTest.php`
→ **43/43 passed, 301 assertions**.

Kết quả chạy full suite (xác nhận không regression toàn cục): `php artisan test` → **429 tests, 413 passed, 16 skipped (baseline có sẵn, môi trường-gated, không liên quan tới thay đổi này), 0 failed, 4841 assertions**.

### 7. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php` | Mới | 2 test case HTTP end-to-end cho gap "không âm tồn tại Post Issue time" (race 2 document) và "idempotent replay không side-effect kép" |

**Không sửa code sản xuất nào** — toàn bộ guard atomic/không-âm-tồn/idempotent đã đúng theo baseline từ trước (kế thừa hạ tầng đã kiểm chứng ở WWHN-195/199); rà soát chỉ phát hiện gap coverage ở đúng phần lõi nhất của tên task cha HN-OUT-003 (Post Issue time guard, không phải scan-time; side-effect trực tiếp, không phải chỉ audit log), không phải bug.

**Ghi chú về working tree tại thời điểm rà soát**: có thay đổi chưa commit thuộc phạm vi task khác (WWHN-205 tiếp diễn — `item_id` write-side trên `stock_movements`, chuẩn hóa `item_instances.status` → `item_instances.item_status` ở `StockMovementController`/`InventoryBalanceController`) cùng tồn tại trên working tree khi rà soát task này. Không đụng tới các file đó; xác nhận chúng không giao nhau với vùng code Post Issue được rà soát ở đây (không cùng function/dòng), và full suite pass cùng lúc với các thay đổi đó xác nhận không có xung đột runtime.

### 8. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-201) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "ghi nhận xuất kho (Post Issue) backend" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE1 | **Done** — rà soát đúng phạm vi atomic/không-âm-tồn/idempotent, xác nhận đầy đủ, lấp 2 gap coverage bằng test mới | Mục 1-5 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — transaction bao trọn, row lock + optimistic lock + business_key unique, idempotency key bắt buộc, đã xác nhận đầy đủ, không cần sửa | Mục 1-4 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — `INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK`/`STATE_CONFLICT`/`PRECONDITION_FAILED` đều là error code đã có, rõ ràng; test mới xác nhận không tạo movement/không âm tồn khi bị chặn | Mục 2, 5 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 2 test case HTTP mới; **không cần cập nhật OpenAPI/Postman** vì không đổi endpoint/request/response shape nào (chỉ bổ sung test cho error code đã có sẵn trong contract từ WWHN-195: `docs/api-contract/WWHN-195-outbound-openapi.yaml`/`.json`) | Mục 5-6 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần QA/BA xác nhận độc lập** — xem Blocker/Open point bên dưới | — |

### 9. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-ISS-OP-01 | Task cha WWHN-47 và dependency HN-DEC-002/HN-DEC-005 không có trong repo — cần BA/PM lấy lại từ Jira để đối chiếu AC chính xác, tránh rủi ro code lệch spec thật do phải suy luận từ baseline code hiện tại | BA/PM | Mở |
| BE1-ISS-OP-02 | Race-condition test đa connection thật (2 HTTP request đồng thời qua Node/worker riêng) cho kịch bản "2 document cùng rút 1 nguồn tồn" chưa có — test mới trong task này mô phỏng đúng outcome tuần tự (A commit trước, B post sau), không phải race đồng thời thật trên nhiều DB connection; kế thừa giới hạn đã ghi nhận ở WWHN-199 (BE1-RESV-OP-03), WWHN-218, WWHN-205 — chưa đóng | BE1 + DevOps | Mở (kế thừa) |
| BE1-ISS-OP-03 | Unique index/constraint Postgres-level liên quan `stock_movements.business_key` và các CHECK constraint không âm tồn (`inventory_balances`) chưa được chạy thật trên PostgreSQL trong phiên rà soát này (không có Postgres khả dụng cục bộ) — kế thừa nguyên trạng đã ghi nhận ở WWHN-199/WWHN-205; cần DevOps/QA xác nhận trên staging/CI Postgres | DevOps/QA | Mở (kế thừa) |

### 10. Định nghĩa Done — thực tế

#### BE1 technical completion (mục này)

Đạt: rà soát đúng phạm vi hẹp và sâu của task cha HN-OUT-003 ("Post Issue atomic, không âm tồn và idempotent") — xác nhận transaction bao trọn (atomic), guard không-âm-tồn tại nguồn sự thật cuối cùng (`mutateBalance()` tại Post Issue time, phân biệt rõ với soft-check tại scan time), và idempotent replay không side-effect kép, cả 3 đều đã đúng theo baseline kế thừa từ WWHN-195/199 — không phát hiện bug chức năng nào. Gap thực sự chỉ nằm ở 2 điểm test coverage hẹp nhưng quan trọng nhất theo đúng tên task (race Post-time, side-effect verify cho replay), đã lấp bằng 2 test case HTTP end-to-end mới, cả hai pass ngay lần đầu. Full suite 413/413 pass (16 skip baseline không liên quan) — không regression, không sửa code sản xuất nào.

#### Còn lại — không thuộc phạm vi tự nhận của BE1

- BA/PM cung cấp lại nội dung gốc WWHN-47/HN-DEC-002/HN-DEC-005 từ Jira (BE1-ISS-OP-01).
- BE1 + DevOps quyết định có bổ sung race-condition test đa connection thật hay giữ nguyên mô phỏng tuần tự (BE1-ISS-OP-02, kế thừa từ WWHN-199/218/205).
- DevOps/QA xác nhận constraint/index Postgres-level liên quan tồn tại đúng trên staging/CI (BE1-ISS-OP-03, kế thừa từ WWHN-199/205).
- QA xác nhận evidence (mục 5-8) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự ghi trong file này.
