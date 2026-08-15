[DEV] Rà soát giao dịch/invariant
Mục tiêu
Rà soát giao dịch/invariant.

Input đầu vào
Task cha: WWHN-48 — [INVENTORY] Số dư, movement không chỉnh sửa và API đối soát

Dependency: HN-INV-001-ST01

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-INV-001

Epic Temp Key: HN-EPIC-07

Dependency: HN-INV-001-ST01

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "Invariant Rà soát" được rà soát/xác minh, gắn evidence vào HN-INV-001 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Invariant Rà soát

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

**Exact commit SHA khi bắt đầu rà soát**: `fad424283638254f0b8ccb441fc49e88e9e1a692` (`[DEV] Triển khai trạng thái/thao tác putaway`, HEAD của `m1` tại thời điểm bắt đầu).

**Quan hệ với các task rà soát trước đó**: task cha `WWHN-48`/`HN-INV-001` ("[INVENTORY] Số dư, movement không chỉnh sửa và API đối soát") là scope **tổng quát** của toàn bộ ledger/invariant, khác với `HN-IN-003` (rà soát riêng inbound/Post Receipt — WWHN-190, BE1) và `HN-OUT-003` (rà soát riêng outbound/Post Issue — WWHN-195, BE1; WWHN-202/207, BE2). WWHN-190/195 đã xác nhận transaction/idempotency/concurrency **đầy đủ** ở mức route/service cho từng luồng riêng lẻ. Task này rà soát các **invariant xuyên suốt cả 2 luồng** ở tầng ghi sổ dùng chung (`CurrentBaselineStockService::mutateBalance()`, `CurrentBaselineReversalService`) và tầng DB, đối chiếu với các open point đã ghi nhận nhưng chưa đóng ở WWHN-207 (BE2, phát hiện `item_id` không được ghi ở write-side), WWHN-218 (BE1-OP-04, thiếu test race-condition thật), WWHN-219 (BE2-OP-04, CHECK constraint chỉ chạy Postgres không được CI xác nhận).

### Phương pháp

Đọc trực tiếp toàn bộ `CurrentBaselineStockService.php` (949 dòng), `CurrentBaselineReversalService.php` (548 dòng), model `StockMovement`/`InventoryBalance`, toàn bộ migration liên quan `stock_movements`/`inventory_balances`/append-only trigger, và test suite hiện có. Xác minh giả thuyết về hành vi PostgreSQL (`Schema::hasTable()` compile ra `relkind IN ('r','p')`) bằng cách đọc trực tiếp source `vendor/laravel/framework/.../PostgresGrammar.php`, không suy đoán. Chạy `php artisan test` thật (không chỉ đọc code) trước và sau mỗi thay đổi.

### 1. Transaction (atomic) — XÁC NHẬN ĐẦY ĐỦ (không đổi, đối chiếu lại WWHN-190/195)

`postStockDocument()` (`CurrentBaselineStockService.php:117-207`) bọc toàn bộ vòng lặp `mutateBalance()` cho mọi document line trong 1 `DB::transaction(..., 3)`. Nếu 1 line fail giữa chừng (`INSUFFICIENT_STOCK` ở line thứ N), Laravel rollback toàn bộ — không có document POST một phần, không có `stock_movements` mồ côi. `CurrentBaselineReversalService::execute()` cũng bọc `DB::transaction(..., 3)` tương tự. Không có thay đổi cần thiết ở mục này.

### 2. Bug thật đã sửa: `stock_movements.item_id` không được ghi ở write-side chính (postStockDocument/postComponentIssue)

**Phát hiện** (đã được WWHN-202/207, BE2, ghi nhận ở phía đọc nhưng chủ động để ngoài phạm vi vì thuộc BE1/transaction-posting logic): `CurrentBaselineStockService::mutateBalance()` (dùng cho Post Receipt/Post Issue/Component Issue qua `postStockDocument()`/`postComponentIssue()`) **không set** `item_id`/`container_id` khi insert `stock_movements`, dù 2 cột này tồn tại có FK constraint (migration `2026_08_10_000600_enforce_current_erd_business_contract.php:133-157`). Ngược lại, `CurrentBaselineReversalService::mutateBalance()` (dòng 425-448 bản cũ) **luôn** set `item_id` cho mỗi `reversal_line`. Đây là inconsistency thật giữa 2 code path ghi cùng 1 bảng.

**Nguyên nhân gốc — không phải oversight đơn giản**: 1 `stock_movements` row được tạo cho mỗi **document line** (`qty_planned` gộp), không phải mỗi item. Với SKU serial-tracked/PRODUCT, `qty_planned > 1` nghĩa là nhiều item riêng biệt (mỗi scan `quantity=1`, xác nhận bởi guard `SERIAL_QUANTITY_MUST_BE_ONE` ở `scanStockDocument()`) được gộp vào 1 movement. Vì vậy gán 1 `item_id` đơn cho movement gộp nhiều item là sai dữ liệu thật sự, không đơn thuần là field thiếu — đây là lý do BE2 (WWHN-202/207) chủ động chọn giải pháp read-side fallback thay vì tự sửa write-side.

**Đã sửa (đúng phạm vi, không đổi business_key/quantity semantics)**: `Modules/Warehouse/app/Services/CurrentBaselineStockService.php`

- Thêm tham số `?string $itemId = null` vào `mutateBalance()`, set vào `stock_movements.item_id` khi insert.
- Thêm method `resolveLineItemId(string $documentType, string $lineId)`: query `inbound_scan_entries`/`outbound_item_matches` theo `lineId`, trả về `item_id` **chỉ khi đúng 1 item** (không rõ ràng nếu ≥2 item — giữ `null`, đúng với hành vi cũ, không regress).
- `postStockDocument()`: gọi `resolveLineItemId()` cho mỗi line, truyền vào `mutateBalance()`.
- `postComponentIssue()`: dùng lại collection `$itemIds` từ `component_issue_items` đã fetch sẵn ngay sau insert (tránh query trùng), set `item_id` khi đúng 1 item.

**Không đổi**: `business_key` vẫn `sha1(TYPE|documentId|lineId)` theo line (đã có test xác nhận unique-per-line ở `PostIssueConcurrencyBehaviorTest`/`PostReceiptConcurrencyBehaviorTest` — không đổi để tránh phá vỡ invariant idempotency đã kiểm chứng). Quantity/balance math của `mutateBalance()` giữ nguyên 100%.

**Test mới**: `tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php` — 4 test case, dẫn qua HTTP thật:

| Test case | Xác nhận |
|---|---|
| `test_post_issue_sets_item_id_on_movement_for_single_item_line` | Post Issue outbound (qty=1, 1 item match) → `stock_movements.item_id` khớp đúng item |
| `test_post_receipt_sets_item_id_on_movement_for_single_item_serial_tracked_line` | Post Receipt PRODUCT/serial-tracked (qty=1, 1 item scan) → `item_id` khớp đúng item |
| `test_post_receipt_leaves_item_id_null_when_line_aggregates_multiple_items` | Post Receipt qty=2 (2 item riêng biệt trên cùng line) → `item_id` **null** đúng theo thiết kế (không gán nhầm 1 trong 2 item); xác nhận cả 2 item đều có mặt trong `inbound_scan_entries` |
| `test_component_receipt_leaves_item_id_null_for_quantity_mode_sku` | Component Issue COMPONENT (quantity-mode, không serial) → `item_id` null đúng (không có khái niệm item cho SKU dạng này) |

Kết quả: `php artisan test tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php` → **4/4 passed, 25 assertions**.

### 3. Đánh giá non-negative balance invariant (không sửa code service — đã đầy đủ)

`mutateBalance()` (dòng 785-891) validate `after < 0` (INSUFFICIENT_STOCK) và `availableAfter < 0` (INSUFFICIENT_AVAILABLE_STOCK) **sau khi** `lockForUpdate()` trên `inventory_balances`, dưới cùng transaction — đọc giá trị mới nhất, không stale. Với 2 request Post Issue đồng thời trên cùng SKU/warehouse, request thứ 2 bị block ở mức DB row lock cho tới khi request 1 commit/rollback (ACID chuẩn trên PostgreSQL). Kết hợp version check kép (`WHERE version = $balance->version` → `VERSION_CONFLICT` nếu 0 row affected) và `business_key` unique — 3 lớp bảo vệ độc lập. Không cần sửa code service.

**Gap CI đã đóng một phần**: CHECK constraint `chk_inventory_balance_non_negative` (migration `2026_08_10_000600:390`, chỉ chạy trên PostgreSQL) tồn tại nhưng **không nằm trong danh sách bắt buộc** của `tests/Feature/ErdBusinessInvariantParityTest::test_required_named_check_constraints_exist()` — nghĩa là kể cả khi CI chạy trên Postgres thật, test này cũng không xác nhận sự tồn tại của đúng constraint bảo vệ invariant non-negative balance. Đã bổ sung `['inventory_balances', 'chk_inventory_balance_non_negative']` vào danh sách `$required`.

**Gap CI-trigger đã đóng**: workflow `erd-postgres-diagnostic.yml` (chạy PHPUnit thật trên PostgreSQL 16, bao gồm `ErdBusinessInvariantParityTest`) chỉ có `paths: ['.github/workflows/erd-postgres-diagnostic.yml']` — tức **chỉ tự kích hoạt khi sửa chính file workflow**, không bao giờ chạy khi migration/service thay đổi thật. Đây là nguyên nhân gốc khiến gap ở mục 4 (bên dưới) tồn tại mà không bị CI bắt được. Đã mở rộng `paths` (cả `push` và thêm `pull_request`) để bao gồm `Modules/Warehouse/database/migrations/**`, `Modules/Warehouse/app/Services/**`, và 3 file `Erd*ParityTest.php`.

**Giới hạn đã xác nhận, không tự ý mở rộng**: không có Postgres chạy được trong môi trường rà soát này (không có `psql`, Docker daemon không chạy) để tự verify workflow mới trên Postgres thật — cần CI/DevOps xác nhận lần chạy đầu tiên trên nhánh `m1` sau khi merge. Việc thiết kế lại CI pipeline (ví dụ chạy Postgres job trên mọi PR thay vì path-filtered) là quyết định DevOps, ngoài phạm vi tự ý mở rộng của BE1 — chỉ mở rộng `paths` tối thiểu để đúng mục đích ban đầu của workflow (đã có tên "diagnostic", rõ ràng có chủ đích chạy khi có thay đổi liên quan, không phải chỉ tự sửa chính nó).

### 4. Bug thật đã sửa: append-only trigger DB-level bảo vệ nhầm bảng mirror, không bảo vệ `stock_movements` (bảng ứng dụng thực sự ghi)

**Phát hiện qua đọc kỹ chuỗi migration** (không phải suy đoán — xác nhận từng bước bằng cách đọc source):

1. `stock_movements` là bảng vật lý thật mà `CurrentBaselineStockService`/`CurrentBaselineReversalService` ghi trực tiếp (`DB::table('stock_movements')->insert(...)`).
2. `inventory_movements` được tạo là **VIEW** compatibility trên `stock_movements` (`2026_08_09_000400_align_latest_erd_baseline.php:402`, tái tạo lại ở `2026_08_10_000500`/`000600`).
3. Migration `2026_08_10_000750_materialize_erd_1_0_physical_objects.php` sau đó **materialize** `inventory_movements` thành bảng vật lý riêng (bản copy, tách khỏi `stock_movements`), vì đây là entity được document trong ERD (`docs/HoaNam_ERD_Moi_Nhat.md:203,1383`).
4. Migration `2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync.php:363` cài 1 trigger mirror AFTER-INSERT trên `stock_movements` (nguồn) để đồng bộ mỗi row mới sang `inventory_movements` (đích, `ON CONFLICT DO NOTHING` vì append-only).
5. Migration `2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints.php::installHistoryGuards()` cài trigger `trg_inventory_movements_append_only` cho danh sách `['audit_logs', 'inventory_movements', 'warranty_events']`, gate bởi `Schema::hasTable($table)`.

**Xác nhận bằng cách đọc trực tiếp** `vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php::compileTableExists()`: trên PostgreSQL, `Schema::hasTable()` compile thành `... AND c.relkind IN ('r', 'p') ...` — chỉ bảng thường (`r`)/bảng partition (`p`), **không gồm view** (`relkind = 'v'`). Tại thời điểm `000770` chạy (sau `000750` materialize, `000765` mirror), `inventory_movements` **đã là bảng vật lý thật** (nhờ bước 3) nên `Schema::hasTable('inventory_movements')` trả `true` và trigger append-only **được cài đúng** — nhưng cài trên bảng mirror/materialized, không phải trên `stock_movements` (bảng nguồn ứng dụng thực sự ghi/đọc cho toàn bộ balance/reconciliation logic).

**Hệ quả invariant thật**: `stock_movements` — bảng duy nhất mà `mutateBalance()` ghi và mọi API đối soát/trace đọc — chỉ được bảo vệ append-only ở **tầng Eloquent** (`StockMovement::booted()`, chỉ có hiệu lực nếu code đi qua Eloquent, không chặn `DB::table('stock_movements')->update()/delete()` trực tiếp). DB-level trigger backstop (Postgres, chặn UPDATE/DELETE ngay cả khi bypass Eloquent) chỉ tồn tại trên `inventory_movements` — bảng mirror phái sinh, không phải nguồn sự thật ứng dụng ghi vào.

**Đã sửa**: migration mới `Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` — cài `trg_stock_movements_append_only` trực tiếp trên `stock_movements`, tái sử dụng function `hn_erd_reject_append_only_change()` đã có sẵn (từ `000770`, cùng hành vi với 3 bảng kia). Theo đúng convention đã có trong dự án cho việc sửa trigger ở migration sau (`2026_08_12_170000_drop_stale_defect_catalog_runtime_mirror_trigger.php`, `2026_08_12_213000_fix_materialize_receipt_putaway_status_trigger.php`) — **không sửa migration cũ đã áp dụng**, thêm migration mới, Postgres-only (`DB::getDriverName() !== 'pgsql'` → return sớm), có `down()` đối xứng.

**Test mới**: bổ sung vào `tests/Feature/ErdBusinessInvariantParityTest::test_append_only_tables_reject_update_and_delete()` — assert `trg_stock_movements_append_only` tồn tại trên `stock_movements` với definition chứa cả `UPDATE`/`DELETE`, cùng cấu trúc với 3 bảng đã test.

**Giới hạn đã xác nhận, không tự ý mở rộng**: không có Postgres khả dụng cục bộ để chạy `migrate:fresh` thật và tự xác nhận trigger mới hoạt động đúng trên production schema (guard `driverName !== pgsql` khiến migration skip sạch trên SQLite — đã verify bằng test, 5/5 skip đúng, không lỗi). Đây là thay đổi DDL rủi ro cao nếu sai — đã đọc kỹ toàn bộ chuỗi 4 migration liên quan trước khi viết, tái dùng chính xác function/pattern đã tồn tại, nhưng **cần QA/DevOps chạy `php artisan migrate` thật trên Postgres (staging/CI mới mở rộng ở mục 3) trước khi coi là đã verify đầy đủ trên production**, đúng tinh thần "không tự chứng nhận PASS" mà các task rà soát trước đã tuân thủ.

### 5. Concurrency race-condition test — xác nhận giới hạn đã biết, không có action mới khả thi trong phạm vi code

Đọc lại `PostIssueConcurrencyBehaviorTest.php`/`PostReceiptConcurrencyBehaviorTest.php`: cả 2 dùng SQLite `:memory:` (1 connection/process theo `phpunit.xml`), nên mô phỏng race bằng request tuần tự với version cũ — xác nhận đúng **hành vi/outcome** (stale version bị 412, state guard chặn re-post, business_key unique) mà 1 racer thua cuộc sẽ quan sát được, không phải race thật đa connection.

Race thật (2 HTTP request đồng thời qua Node `fetch`) đã có sẵn ở `scripts/run-wwhn172-concurrency.mjs`/`scripts/run-wwhn150-concurrent-scan.mjs` — nhưng xác nhận qua grep toàn bộ `.github/workflows/`: 2 script này **chỉ được `node --check`** (syntax-check, không thực thi) trong `auth-rbac-audit-contract.yml` và `wwhn-172-sku-container-contract.yml`; không có workflow nào thực sự chạy chúng với server sống + concurrent request. Đây đúng là gap đã ghi nhận ở WWHN-218 (BE1-OP-04), **chưa đóng**.

**Không tự ý xử lý**: nối script Node vào 1 workflow CI mới (cần khởi động `php artisan serve`, seed dữ liệu, chạy song song, thu evidence JSON) là quyết định thiết kế CI/DevOps (chọn concurrency count, timeout, môi trường), không phải một fix code phạm vi hẹp — đúng như WWHN-218 đã phân loại owner là "BE1 + DevOps", không phải BE1 đơn phương. Giữ nguyên khuyến nghị: DevOps xác nhận có đưa 2 script này vào 1 job CI thực thi thật (không chỉ syntax-check) hay giữ chạy thủ công.

### 6. Test coverage — trước và sau

| Hạng mục | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `stock_movements.item_id` khớp đúng item cho line 1-item | Sai (luôn null, đã biết từ WWHN-202/207) | **Đã sửa** — set đúng khi unambiguous, giữ null khi multi-item (đúng thiết kế) |
| CHECK constraint `chk_inventory_balance_non_negative` được CI xác nhận tồn tại | Không (thiếu trong `$required` của parity test) | **Đã bổ sung** vào `ErdBusinessInvariantParityTest` |
| Workflow Postgres CI tự chạy khi migration/service kho thay đổi | Không (chỉ tự sửa chính workflow) | **Đã mở rộng `paths`** — chạy khi đổi migration/service/parity test |
| `stock_movements` có DB-level append-only trigger (không chỉ Eloquent) | Không (trigger chỉ bảo vệ bảng mirror `inventory_movements`) | **Đã bổ sung** migration + assert trong parity test |
| Race-condition test đa connection thật trong CI | Không (script Node chỉ `node --check`) | Không đổi — ghi nhận rõ, cần DevOps disposition (BE1-OP-04, WWHN-218) |

Kết quả chạy: `php artisan test tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php` → **4/4 passed, 25 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + test transaction/concurrency/idempotency/reconciliation có sẵn):
`php artisan test tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/PostReceiptAuditLogTest.php tests/Feature/Wwhn191BalanceMovementProjectionTest.php`
→ **34/34 passed, 274 assertions** — không regression từ thay đổi `mutateBalance()`.

`php artisan test tests/Feature/ErdBusinessInvariantParityTest.php` → **5/5 skipped** (đúng — chứng thực trên PostgreSQL 14+, SQLite bỏ qua theo `requirePostgres()`), không lỗi migration mới trên SQLite.

Kết quả chạy full suite (xác nhận không regression toàn cục): `php artisan test` → **383 tests, 367 passed, 16 skipped (baseline có sẵn, không liên quan tới thay đổi này), 0 failed, 4624 assertions**.

`vendor/bin/pint --test` cho toàn bộ file đã sửa/tạo (`CurrentBaselineStockService.php`, migration mới, `ErdBusinessInvariantParityTest.php`, `Wwhn205StockMovementItemIdInvariantTest.php`) → **pass**, không cần auto-fix (trừ 1 lần format import/trait order tự động khi tạo file test mới, đã re-run xanh sau đó).

### 7. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `Modules/Warehouse/app/Services/CurrentBaselineStockService.php` | Sửa | `mutateBalance()` nhận thêm `?string $itemId`; thêm `resolveLineItemId()`; `postStockDocument()`/`postComponentIssue()` truyền `item_id` khi unambiguous |
| `Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` | Mới | Cài `trg_stock_movements_append_only` trực tiếp trên `stock_movements` (Postgres-only, tái dùng function có sẵn) |
| `tests/Feature/ErdBusinessInvariantParityTest.php` | Sửa | Bổ sung `chk_inventory_balance_non_negative` vào CHECK constraint bắt buộc; bổ sung assert `trg_stock_movements_append_only` |
| `tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php` | Mới | 4 test case HTTP end-to-end cho invariant `item_id` (single-item receipt/issue, multi-item null, COMPONENT null) |
| `.github/workflows/erd-postgres-diagnostic.yml` | Sửa | Mở rộng `paths` trigger (push + pull_request) để chạy khi migration/service kho hoặc parity test ERD thay đổi, không chỉ khi tự sửa workflow |

### 8. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-205) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Invariant Rà soát" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE1 | **Done** — rà soát phát hiện 2 bug thật (item_id write-side, append-only trigger bảo vệ nhầm bảng) và 2 gap CI (CHECK constraint thiếu trong parity test, workflow Postgres dormant), đã sửa cả 4, có test | Mục 2-4, 6 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — transaction/idempotency/RBAC/audit đã xác nhận đầy đủ ở WWHN-190/195, không đổi; concurrency implementation đầy đủ (3 lớp lock độc lập), gap còn lại (race-condition test thật) là CI infra, đã ghi nhận rõ owner DevOps | Mục 1, 3, 5 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — không đổi error code nào; fix item_id không đổi response contract; fix trigger là DB-level backstop, không đổi hành vi API | Mục 2, 4 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 4 test case HTTP mới + 2 assert bổ sung vào parity test; **không cần cập nhật OpenAPI/Postman** vì response JSON shape giữ nguyên 100% (chỉ bổ sung giá trị cho field `item_id` đã có sẵn trong contract, không thêm/bớt field) | Mục 2, 4, 6 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần QA/DevOps xác nhận độc lập** — xem mục "Blocker/Open point" bên dưới | — |

### 9. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-INV-OP-01 | Migration `2026_08_15_000100` (append-only trigger trên `stock_movements`) chưa được chạy thật trên PostgreSQL (không có Postgres khả dụng trong môi trường rà soát) — cần chạy `php artisan migrate` trên staging/CI Postgres và xác nhận `ErdBusinessInvariantParityTest` PASS trước khi coi là verify đầy đủ | DevOps/QA | Mở |
| BE1-INV-OP-02 | Workflow `erd-postgres-diagnostic.yml` vừa mở rộng `paths` — cần xác nhận lần chạy đầu tiên trên `m1` sau merge thành công (bao gồm cả migration mới ở BE1-INV-OP-01) | DevOps | Mở |
| BE1-INV-OP-03 | Race-condition test đa connection thật (`scripts/run-wwhn172-concurrency.mjs`/`run-wwhn150-concurrent-scan.mjs`) vẫn chỉ `node --check` trong CI, chưa thực thi thật với server sống — kế thừa nguyên trạng từ WWHN-218 (BE1-OP-04), chưa đóng | BE1 + DevOps | Mở (kế thừa) |
| BE1-INV-OP-04 | Dead code đã biết (`StockDocumentService`, `StockLedger`, `CurrentBaselineStockController`, `CurrentBaselineStockService::reverseStockDocument()`) — vẫn chưa có disposition chính thức từ BA/Tech Lech qua nhiều task rà soát (WWHN-190/195/207/218) | BA/Tech Lead | Mở (kế thừa, không tự ý xử lý) |

### 10. Định nghĩa Done — thực tế

#### BE1 technical completion (mục này)

Đạt: 2 bug thật được phát hiện qua đọc trực tiếp source (không suy đoán) và sửa đúng phạm vi — (a) `stock_movements.item_id` không được ghi ở write-side chính, khác biệt với reversal write-side, đã thống nhất; (b) DB-level append-only trigger bảo vệ nhầm bảng mirror thay vì bảng ứng dụng thực sự ghi, đã bổ sung trigger trực tiếp trên `stock_movements`. 2 gap CI được đóng: CHECK constraint non-negative balance được thêm vào danh sách bắt buộc của parity test; workflow Postgres CI dormant được mở rộng để tự chạy khi có thay đổi liên quan. 4 test case HTTP end-to-end mới cho invariant `item_id`, 2 assert mới cho parity test. Full suite 367/367 pass (16 skip baseline không liên quan) tại HEAD `fad424283638254f0b8ccb441fc49e88e9e1a692` + các thay đổi của task này. Không đổi contract API/response nào.

#### Còn lại — không thuộc phạm vi tự nhận của BE1

- DevOps xác nhận migration mới chạy sạch trên Postgres thật (BE1-INV-OP-01) và workflow mở rộng hoạt động đúng (BE1-INV-OP-02).
- BE1 + DevOps quyết định có nối race-condition script Node vào CI thực thi thật hay giữ nguyên chạy thủ công (BE1-INV-OP-03, kế thừa từ WWHN-218).
- BA/Tech Lead xác nhận disposition dead code đã biết qua nhiều task (BE1-INV-OP-04, kế thừa).
- QA xác nhận evidence (mục 6-8) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự ghi trong file này.
