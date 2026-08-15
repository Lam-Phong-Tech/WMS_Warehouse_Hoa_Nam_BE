[DEV] Triển khai trạng thái/thao tác putaway
Description

Mục tiêu
Triển khai trạng thái/thao tác putaway.

Input đầu vào
Task cha: WWHN-44 — [INBOUND] Putaway cơ bản, không mở rộng bin/kệ

Dependency: HN-IN-003

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-IN-004

Epic Temp Key: HN-EPIC-05

Dependency: HN-IN-003

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria
Output "xếp hàng vào vị trí (putaway) backend" được rà soát/xác minh, gắn evidence vào HN-IN-004 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
xếp hàng vào vị trí (putaway) backend

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

**Exact commit SHA khi bắt đầu rà soát**: `ad688f7851f4c11efad7fdc115fe0daa8adc61a9` (HEAD của `m1`).

**Phạm vi output "xếp hàng vào vị trí (putaway) backend"**: theo Input đầu vào (task cha HN-44 — Putaway cơ bản BOX→TRAY, không mở rộng bin/kệ; dependency HN-IN-003 — post-receipt, đã được BE1/BE2 xác minh ở WWHN-190/WWHN-191), phần BE2 phụ trách là toàn bộ vòng đời putaway sau khi BOX đã POST Receipt và ở trạng thái `RECEIVED_PENDING_PUTAWAY`:

1. `GET /api/v1/putaway/tasks` — danh sách BOX chờ xếp khay + DRAFT đang xử lý (`PutawayController::tasks`).
2. `POST /api/v1/putaway/tasks` — tạo putaway DRAFT BOX→TRAY, stock-neutral (`CurrentBaselinePutawayTaskController::store`).
3. `POST /api/v1/putaway/tasks/{id}/confirm` — xác nhận putaway, cập nhật container, không phát sinh stock movement (`PutawayController::confirm`).
4. `POST /api/v1/putaway/tasks/{id}/cancel` — hủy putaway DRAFT (`PutawayController::cancel`).
5. `GET /api/v1/putaway/history` — lịch sử putaway CONFIRMED, append-only (`PutawayController::history`).
6. `POST /api/v1/inbound-documents/{id}/boxes` — đăng ký BOX cho dòng nhập COMPONENT trước khi scan (`CurrentBaselineInboundBoxController::store`) — thuộc luồng foundation cho putaway, đã có test E2E từ trước.

**Ghi chú kỹ thuật (khớp phát hiện dead-code pattern của BE1/BE2 ở WWHN-190/191)**: `Modules/Warehouse/app/Http/Controllers/PutawayController.php` chứa 2 method **không có route nào trỏ tới** — `registerInboundBox()` (dòng 40) và `storeTask()` (dòng 221). Cả 2 chỉ giữ lại để lưu OpenAPI annotation cho đúng `path`; route thật (`Modules/Warehouse/routes/api.php:63,70`) trỏ tới `CurrentBaselineInboundBoxController::store` và `CurrentBaselinePutawayTaskController::store`. Xác nhận bằng `php artisan route:list --path=putaway` và Grep toàn repo (routes, providers, tests) — không có nơi nào gọi 2 method dead này.

**Rủi ro bảo trì mới phát hiện (không phải bug runtime vì code không active, nhưng nghiêm trọng hơn dead-code thông thường)**: logic nghiệp vụ trong `PutawayController::assertBoxReusable()` (dòng 474-485, dead) **khác hẳn ý nghĩa** so với bản active `CurrentBaselineInboundBoxController::assertBoxReusable()` (dòng 99-110) — bản dead cho phép reuse BOX đang `RECEIVED_PENDING_PUTAWAY` (không nằm trong danh sách chặn `['CLOSED','ISSUED','STORED']`), trong khi bản active chỉ cho phép reuse BOX đang `EMPTY` (`status !== 'EMPTY'` → chặn). Nếu dev sau này vô tình đổi route trỏ lại `PutawayController::registerInboundBox`, hành vi nghiệp vụ sẽ đổi hẳn (cho phép đăng ký lại BOX đã pending-putaway cho phiếu khác). Không tự ý xóa `PutawayController::registerInboundBox`/`storeTask` vì nằm ngoài phạm vi "sửa 2 method OpenAPI-only" — chỉ ghi nhận rủi ro để BA/PM/Tech Lead quyết định có cần dọn code chết này trong task riêng hay không (tương tự khuyến nghị đã đưa ra ở WWHN-190 cho `StockDocumentController`/`StockDocumentService`).

### 1. Rà soát trạng thái ban đầu — phát hiện 1 bug thật (audit ghi ngoài transaction, không phải thiếu test)

Trước khi viết test mới, đã đọc kỹ toàn bộ `PutawayController.php`, `CurrentBaselinePutawayTaskController.php`, `CurrentBaselineInboundBoxController.php`, các migration `putaway_events`/`containers` liên quan, và đối chiếu với pattern audit đã chốt ở WWHN-190 mục 6 ("`AuditLogger::record()` ghi trong cùng transaction với stock effect" — tham chiếu `CurrentBaselineStockService.php:191-199`):

- **Bug — `AuditLogger::record()` nằm NGOÀI `DB::transaction()`** ở 3/4 điểm ghi audit thuộc luồng putaway active:
  - `CurrentBaselinePutawayTaskController::store()` (bản cũ, dòng 91-100): `DB::transaction()` return rồi mới gọi `AuditLogger::record()` bên ngoài.
  - `PutawayController::confirm()` (bản cũ, dòng 354-364): tương tự.
  - `PutawayController::cancel()` (bản cũ, dòng 409-419): tương tự.
  - Riêng `CurrentBaselineInboundBoxController::store()` (đăng ký BOX) đã ghi audit **đúng bên trong** transaction — không cần sửa.

  Hệ quả nếu không sửa: business record (`putaway_events` DRAFT/CONFIRMED/CANCELLED, `containers.status`/`parent_container_id`) đã **commit** trước khi `AuditLogger::record()` chạy. Theo policy `outbox_required`/`fail_closed` của `AuditLogger` (`Modules/AuditLog/app/Services/AuditLogger.php:80-85`), nếu cả `audit_log` và `audit_outbox` đều ghi lỗi, service throw `RuntimeException` — nhưng vì đã ở ngoài transaction, exception này không rollback được state nghiệp vụ đã commit; client nhận 500 trong khi thao tác putaway trên thực tế đã thành công, dẫn tới state không nhất quán giữa response và DB (đúng loại rủi ro mà WWHN-190 đã chủ động phòng ngừa cho `post-receipt`).

Không phát hiện bug runtime khác (không có query cột-không-tồn-tại/bảng-bị-drop kiểu Bug 1/2 ở WWHN-191) sau khi rà soát toàn bộ `PutawayController::tasks()`/`history()` (query join nhiều bảng) và chạy HTTP thật.

### 2. Đã sửa (đúng phạm vi — chỉ di chuyển vị trí gọi `AuditLogger::record()` vào trong transaction, không đổi logic nghiệp vụ/contract)

`Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php`:
- `store()`: chuyển `AuditLogger::record(action: 'PUTAWAY_DRAFT_CREATE', ...)` vào bên trong closure `DB::transaction()`, ngay sau khi đọc lại `$event` vừa insert. Response JSON và request/response contract giữ nguyên 100%.

`Modules/Warehouse/app/Http/Controllers/PutawayController.php`:
- `confirm()`: chuyển `AuditLogger::record(action: 'PUTAWAY_CONFIRM', ...)` vào trong transaction, sau khi update `containers`/`putaway_events` và sau invariant check `movementBefore === movementAfter`.
- `cancel()`: chuyển `AuditLogger::record(action: 'PUTAWAY_CANCEL', ...)` vào trong transaction, sau khi update `putaway_events`.
- Không sửa `registerInboundBox()`/`storeTask()` (dead code, audit đã ở đúng vị trí sẵn, không active nên không có rủi ro runtime) — giữ nguyên để tránh mở rộng phạm vi ngoài scope.

**Response JSON/contract giữ nguyên 100%** ở cả 3 endpoint — chỉ đổi thứ tự thực thi nội bộ (audit trước khi transaction commit thay vì sau), không đổi field nào, nên không cần cập nhật OpenAPI/Postman.

### 3. Test coverage — trước và sau

| Endpoint / hành vi | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `POST /putaway/tasks` (create DRAFT, happy path) | Có (`FoundationInboundPutawayReadinessTest`) | Giữ nguyên, re-run xanh |
| `POST /putaway/tasks/{id}/confirm` (happy path, stock-neutral) | Có (`FoundationInboundPutawayReadinessTest`) | Giữ nguyên, re-run xanh |
| `GET /putaway/history` (happy path) | Có (`FoundationInboundPutawayReadinessTest`) | Giữ nguyên, re-run xanh |
| `GET /putaway/tasks` (200 success + shape) | **Không có** (chỉ OpenAPI annotation) | **Đã bổ sung** — 2 test case (list cơ bản + search/keyword filter + draft_id/to_tray_code) |
| `POST /putaway/tasks/{id}/cancel` (happy path, stock-neutral, box quay lại eligible) | **Không có** | **Đã bổ sung** |
| `POST /putaway/tasks/{id}/cancel` (404 unknown id) | **Không có** | **Đã bổ sung** |
| 401 `UNAUTHENTICATED` cho cả 5 route putaway | **Không có** | **Đã bổ sung** |
| 403 `ACCESS_DENIED` khi thiếu `container.view`/`container.manage` | **Không có** | **Đã bổ sung** — kèm assert không tạo dữ liệu khi bị từ chối |
| `confirm()` version conflict (If-Match sai) → 412 `PRECONDITION_FAILED` | **Không có** (chỉ OpenAPI annotation) | **Đã bổ sung** — kèm assert state không đổi |
| `confirm()`/`cancel()` thiếu `If-Match` → 428 `PRECONDITION_REQUIRED` | **Không có** | **Đã bổ sung** (confirm) |
| `confirm()` gọi lần 2 sau khi đã CONFIRMED → 409 `STATE_CONFLICT` (state guard ưu tiên trước version guard) | **Không có** | **Đã bổ sung** — xác nhận BOX không bị relocate 2 lần |
| `PUTAWAY_DRAFT_EXISTS` guard (409 khi BOX đã có DRAFT) | Có ở mức source code, chưa test hành vi HTTP riêng | **Đã bổ sung** test hành vi HTTP |
| Validation: thiếu `box_container_id`/`to_tray_id`, box=tray, tray không tồn tại | **Không có** | **Đã bổ sung** (3 test case) |
| `TRAY_NOT_USABLE` (tray CLOSED) | **Không có** | **Đã bổ sung** |
| `CONTAINER_CYCLE` guard | **Không có** | **Đã bổ sung** |
| `BOX_NOT_PUTAWAY_ELIGIBLE` (BOX chưa POST Receipt) | Có 1 case (STORED rồi) | Giữ nguyên + **đã bổ sung** case BOX còn `EMPTY` |
| Audit log row thật cho `PUTAWAY_DRAFT_CREATE`/`PUTAWAY_CONFIRM` | **Không có** | **Đã bổ sung** — phát hiện bug audit-ngoài-transaction khi verify |
| Audit log row thật cho `PUTAWAY_CANCEL` | **Không có** | **Đã bổ sung** |
| `putaway/history` chỉ liệt kê CONFIRMED, không liệt kê CANCELLED, order DESC | **Không có** | **Đã bổ sung** |

**File test mới**: `tests/Feature/Wwhn193PutawayLifecycleTest.php` — 20 test case, dẫn qua **flow HTTP thật** (`POST /putaway/tasks` → `/confirm` hoặc `/cancel` → `GET /putaway/history`), không mock/stub:

1. `test_all_putaway_endpoints_require_authentication`
2. `test_putaway_read_endpoints_require_container_view_permission`
3. `test_putaway_write_endpoints_require_container_manage_permission`
4. `test_putaway_cancel_is_stock_neutral_and_does_not_move_box`
5. `test_putaway_cancel_returns_404_for_unknown_id`
6. `test_putaway_confirm_rejects_stale_version_with_412`
7. `test_putaway_confirm_requires_if_match_header`
8. `test_putaway_confirm_twice_is_rejected_by_state_guard_and_does_not_double_move`
9. `test_putaway_draft_exists_guard_blocks_duplicate_draft_for_same_box`
10. `test_putaway_create_rejects_missing_required_fields`
11. `test_putaway_create_rejects_same_box_and_tray_id`
12. `test_putaway_create_rejects_box_not_yet_eligible`
13. `test_putaway_create_rejects_closed_tray`
14. `test_putaway_create_rejects_container_cycle`
15. `test_putaway_create_returns_422_for_non_existent_tray`
16. `test_putaway_lifecycle_writes_audit_log_rows_for_each_transition`
17. `test_putaway_cancel_writes_audit_log_row`
18. `test_putaway_tasks_list_returns_pending_box_with_expected_shape`
19. `test_putaway_tasks_list_includes_box_with_draft_and_search_filters_by_keyword`
20. `test_putaway_history_lists_only_confirmed_events_ordered_desc`

Kết quả chạy: `php artisan test tests/Feature/Wwhn193PutawayLifecycleTest.php` → **20/20 passed, 95 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + test putaway có sẵn + RBAC negative): `php artisan test tests/Feature/Wwhn193PutawayLifecycleTest.php tests/Feature/FoundationInboundPutawayReadinessTest.php tests/Feature/SecurityRbacAuditNegativeTest.php` → **40/40 passed, 292 assertions**.

Kết quả chạy full suite (xác nhận không có regression): `php artisan test` → **359 tests, 343 passed, 16 skipped (baseline có sẵn, không liên quan tới thay đổi này), 0 failed, 4426 assertions**.

### 4. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-193) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "xếp hàng vào vị trí (putaway) backend" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát toàn bộ 5 route active + luồng đăng ký BOX, phát hiện 1 bug thật (audit ngoài transaction) đã sửa, 1 rủi ro dead-code-logic-khác-biệt đã ghi nhận cho BA/Tech Lead quyết định | Mục 1-2 ở trên |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — permission `container.view`/`container.manage` đã test hành vi thật (401/403); transaction đã bọc đúng (kể cả audit sau khi sửa); optimistic concurrency (`If-Match`/`version`) đã test 412/428/409; idempotency: putaway confirm/cancel là stock-neutral (có invariant runtime check chặn nếu phát sinh `stock_movements`, đã verify bằng test), không tạo side-effect ngoài transaction nên không cần `Idempotency-Key` riêng — nhất quán với cách BE1 phân loại ở WWHN-190 (chỉ route có business-key/movement thật mới bắt buộc header này); `PUTAWAY_DRAFT_EXISTS` guard đã test chặn tạo trùng DRAFT | Test case 1-9, 16-17 trong `Wwhn193PutawayLifecycleTest` |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — 404 `PUTAWAY_NOT_FOUND`, 409 `STATE_CONFLICT`/`PUTAWAY_DRAFT_EXISTS`/`BOX_NOT_PUTAWAY_ELIGIBLE`, 412 `PRECONDITION_FAILED`, 422 validation/`TRAY_NOT_USABLE`/`CONTAINER_CYCLE`, 428 `PRECONDITION_REQUIRED` đều có test xác nhận qua `ApiError`/`ApiResponse`; test permission-denied xác nhận không tạo `putaway_events` row khi bị 403 | Test case 3, 5-15 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 20 test case HTTP end-to-end mới; **không cần cập nhật OpenAPI/Postman** vì response JSON shape giữ nguyên 100% (chỉ sửa thứ tự thực thi nội bộ của audit, không đổi field/status code nào so với annotation hiện có) | `tests/Feature/Wwhn193PutawayLifecycleTest.php` |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/QA/FE xác nhận độc lập** — phía BE2 không còn blocker kỹ thuật. **Lưu ý cho QA**: bug audit-ngoài-transaction là rủi ro P2 (không gây sai dữ liệu nghiệp vụ, chỉ ảnh hưởng tính nhất quán response/audit trail khi audit-write cực hiếm khi fail-closed) — đã sửa trong task này, không cần re-test riêng ngoài việc chạy lại evidence ở mục 3. **Lưu ý cho BA/Tech Lead**: cần quyết định có dọn code chết `PutawayController::registerInboundBox()`/`storeTask()` (logic khác biệt với bản active) trong task riêng hay không | Mục 1 (rủi ro dead-code), mục 3 |

### 5. Phạm vi đã xác nhận và điểm còn mở (không tự ý mở rộng)

- **Không xóa/refactor** `PutawayController::registerInboundBox()`/`storeTask()` dù xác nhận không có route trỏ tới và logic nghiệp vụ khác biệt với bản active `CurrentBaselineInboundBoxController`/`CurrentBaselinePutawayTaskController` — dọn dẹp code chết vượt phạm vi output "putaway backend" của task này, đã ghi nhận rủi ro ở mục 1 để BA/PM/Tech Lead quyết định trong task riêng (tương tự khuyến nghị đã đưa ra ở WWHN-190/191 cho `StockDocumentController`/`SafeStockConfig*`).
- Không sửa trigger Postgres `hn_reject_putaway_event_change`/`hn_materialize_receipt_putaway_status` (migration `2026_08_10_000680`, `2026_08_10_000781`, `2026_08_11_161500`, `2026_08_12_130500`, `2026_08_12_213000`) — đã đọc kỹ toàn bộ 5 migration, xác nhận logic cuối cùng nhất quán với application-level guard (state check trước khi chạm DB), không phát hiện xung đột.
- Route `PATCH /containers/{id}` (`CurrentBaselineContainerFlowController::update`) cũng có thể kích hoạt side-effect tương đương putaway (đổi `parent_container_id` → ghi `PUTAWAY_CONFIRM` audit) khi thay đổi container tổng quát — đây là route "container update" riêng biệt, ngoài phạm vi "putaway task lifecycle" (`/putaway/tasks*`) của task này, không xử lý ở đây.
- Không phát hiện blocker kỹ thuật P0/P1 nào còn mở phía BE2 tại thời điểm rà soát, sau khi đã sửa bug audit-ngoài-transaction.

### 6. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: 1 bug thật (`AuditLogger::record()` ngoài transaction ở `store`/`confirm`/`cancel`) đã được sửa (giữ nguyên contract JSON), 20 test case HTTP end-to-end mới cho toàn bộ vòng đời putaway (`putaway/tasks` list/create/confirm/cancel, `putaway/history`, permission, validation, concurrency, audit), test coverage sẵn có (`FoundationInboundPutawayReadinessTest`) re-run xanh không regression, full suite 343/343 pass (16 skip baseline không liên quan) tại HEAD `ad688f7851f4c11efad7fdc115fe0daa8adc61a9` + các thay đổi của task này.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (mục 3-4 ở trên) đáp ứng đúng Acceptance Criteria.
- BA/Tech Lead xác nhận có cần task riêng để dọn code chết `PutawayController::registerInboundBox()`/`storeTask()` (logic khác biệt với bản active, rủi ro maintain) hay không.
- FE1 xác nhận response shape của `putaway/tasks`, `putaway/history`, `putaway/tasks/{id}/confirm|cancel` đủ để tích hợp UI putaway.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
