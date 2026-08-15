[DEV] Triển khai ledger/số dư/truy vấn/đối soát

Mục tiêu
Triển khai ledger/số dư/truy vấn/đối soát.

Input đầu vào
Task cha: WWHN-48 — [INVENTORY] Số dư, movement không chỉnh sửa và API đối soát

Dependency: HN-DEC-002; HN-TEC-001

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-INV-001

Epic Temp Key: HN-EPIC-07

Dependency: HN-DEC-002; HN-TEC-001

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria
Output "tồn kho backend" được rà soát/xác minh, gắn evidence vào HN-INV-001 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
tồn kho backend

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

**Exact commit SHA khi bắt đầu rà soát**: `fad424283638254f0b8ccb441fc49e88e9e1a692` (`[DEV] Triển khai trạng thái/thao tác putaway`, HEAD của `m1` tại thời điểm bắt đầu).

**Quan hệ với các task trước**: WWHN-191 (BE2, cùng output "Movement/balance hỗ trợ") đã rà soát và sửa 2 bug trong `InventoryBalanceController` trước đó (crash do bảng `safe_stock_configs` bị drop; sai cột trong `subledger()`). Task này (WWHN-204) rà soát lại **toàn bộ** bề mặt ledger/balance/query/reconciliation một lần nữa từ đầu — không giả định các fix trước đó đã đầy đủ — và phát hiện thêm gap chưa từng được ghi nhận.

**Phạm vi output "tồn kho backend"**: toàn bộ lớp đọc/truy vấn/đối soát dựng trên ledger `stock_movements` (nguồn sự thật, append-only) và `inventory_balances` (projection), gồm:

| Method | Path | Controller@action | Permission |
|---|---|---|---|
| GET | `/api/v1/inventory/balances` | `InventoryBalanceController::index` | `inventory.view` |
| GET | `/api/v1/inventory/balances/{id}/subledger` | `InventoryBalanceController::subledger` | `inventory.view` |
| GET | `/api/v1/stock-movements` | `StockMovementController::index` | `inventory.view` |
| GET | `/api/v1/stock-movements/{id}` | `StockMovementController::show` | `inventory.view` |
| GET | `/api/v1/stock-movements/{id}/trace-source` | `StockMovementController::traceSource` | `inventory.trace` |
| GET | `/api/v1/stock-movements/trace-by-code` | `StockMovementController::traceByPhysicalCode` | `inventory.trace` |
| GET | `/api/v1/stock-movements/reversible` | `StockMovementController::reversible` | `inventory.reversal.post` |
| GET | `/api/v1/reports/inventory-balance` | `OperationalReportController::inventoryBalance` | `report.view` |
| GET | `/api/v1/reports/inventory-xnt`, `inventory-xnt-enhanced` | `OperationalReportController::inventoryXnt*` | `report.view` |
| GET | `/api/v1/reports/inventory-reconciliation` | `OperationalReportController::inventoryReconciliation` | `report.view` |
| GET | `/api/v1/reports/dashboard-quick` | `OperationalReportController::dashboardQuick` | `report.view` |
| CLI | `php artisan inventory:reconcile` | `ReconcileInventoryBalances` | — (console) |

### 1. Rà soát toàn diện — đọc từng dòng, không chỉ preview

Đọc toàn bộ `InventoryBalanceController.php`, `StockMovementController.php`, `OperationalReportController.php` (1164 dòng), `ReconcileInventoryBalances.php`, `StockLedger.php`, và các migration ERD liên quan (`2026_08_10_000600/000620/000670/000760/000765/000780/000791`, `2026_08_02_000002`, `2026_08_09_000300/000305`) để xác định chính xác schema thật hiện hành trên cả hai driver (pgsql production, sqlite test).

**Phương pháp**: mọi nghi vấn "cột/bảng có tồn tại không" đều được xác minh bằng test HTTP thật hoặc query schema thật (`Schema::hasColumn`, `sqlite_master`), **không dựa vào việc đọc migration suy luận** — vì nhiều migration có driver-gate (`if (DB::getDriverName() !== 'pgsql') return;`) khiến suy luận tĩnh dễ sai. Cách này đã tự phát hiện và loại bỏ 1 false-positive trước khi đưa vào fix (mục 3 bên dưới).

### 2. Bug thật đã xác nhận và sửa

**Bug — `GET /api/v1/inventory/balances` (mọi request có ≥1 balance sở hữu container_items ACTIVE) trả 500.**

`InventoryBalanceController::buildSubledgerCounts()` (dòng ~254) query `item_instances.status` để đếm subledger item — nhưng cột thật trong bảng `item_instances` là `item_status` (`status` không tồn tại, xác nhận bằng `Schema::hasColumn('item_instances', 'status')` → `false` trên cả pgsql và sqlite). Đây là gap sót lại từ fix WWHN-191: task đó đã sửa đúng cột trong `subledger()` (dòng ~168) nhưng **không sửa `buildSubledgerCounts()`** — hàm được gọi từ `index()` để tính `container_subledger_count`/`item_subledger_count` cho toàn bộ danh sách balance, dùng chung logic sai. Vì coverage test cũ (`Wwhn191BalanceMovementProjectionTest`) không có fixture nào tạo `container_items` ACTIVE khớp SKU đang test, nhánh lỗi này chưa từng bị exercise.

**Đã sửa** (`Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php`): `buildSubledgerCounts()` — `item_instances.status` → `item_instances.item_status`. `container_items.is_active` giữ nguyên (đã xác nhận là cột thật, được thêm bởi migration `2026_08_10_000670_align_identity_container_and_scan_lifecycle.php::alignContainerItems()`, chạy trên mọi driver, không driver-gated). Response JSON shape không đổi (chỉ sửa nguồn dữ liệu nội bộ).

**Bug — `GET /api/v1/stock-movements/reversible?search=...` trả 500 trên SQLite (mọi environment dùng SQLite; không ảnh hưởng Postgres production).**

`StockMovementController::reversible()` build filter `search` bằng `whereRaw('... ILIKE ?', ...)` — `ILIKE` là cú pháp riêng của PostgreSQL, không hợp lệ trên SQLite. Endpoint này **chưa từng có test HTTP nào** trong toàn bộ suite trước task này (xác nhận bằng `grep -r reversible tests/` — chỉ khớp 3 file không liên quan đến `stock-movements/reversible`), nên gap không được phát hiện dù route đã tồn tại từ trước.

**Đã sửa**: thay `whereRaw(... ILIKE ...)` bằng `where(..., $operator, ...)` với `$operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like'` — cùng pattern driver-agnostic đã được dùng sẵn ở `app/Http/Controllers/Controller.php::applyKeyword()` (helper dùng chung toàn dự án) và ở `OperationalReportController` (đã tự ghi chú "LIKE tương thích SQLite + PostgreSQL (thay ilike)" từ trước). Hành vi trên Postgres không đổi (vẫn `ilike`, case-insensitive). Response JSON shape không đổi.

### 3. False-positive đã điều tra và loại bỏ (ghi lại để tránh điều tra trùng lặp)

Trong lúc rà soát, nghi ngờ ban đầu là `reversible()` join bảng `products` (dòng `leftJoin('products', 'stock_movements.product_id', '=', 'products.id')`) sẽ crash vì bảng `products` "đã bị đổi tên thành `sku`" (theo migration `2026_08_02_000002_safe_guard_products_to_sku_rename.php`). **Đã xác minh đây là hiểu sai — không phải bug**:

- Migration `2026_08_09_000300_create_products_compatibility_view.php` tạo lại `products` như một **updatable compatibility VIEW** chính thức, có chủ đích (`CREATE VIEW products AS SELECT sku_id AS id, sku_code AS code, sku_name AS name, ... FROM sku`), chạy trên mọi driver.
- Migration `2026_08_09_000305_make_products_view_writable_on_sqlite.php` bổ sung `INSTEAD OF INSERT/UPDATE/DELETE` trigger trên SQLite để view này ghi xuyên qua `sku` (SQLite không tự làm view ghi được).
- Xác minh trực tiếp bằng query `sqlite_master`: `products` có `type: view`, và `SELECT * FROM products` thực thi thành công. `Schema::hasTable('products')` của Laravel trả `false` — đây là hạn chế riêng của Laravel/SQLite (không đếm view là "table"), **không phản ánh khả năng query thật**.
- Ban đầu viết test `?search=...` cho `reversible()` và thấy 500 → kết luận vội là do `products`; sau khi tách riêng field `ILIKE` khỏi giả thuyết `products` (test không dùng `search`) thì endpoint chạy đúng ngay cả khi **chưa sửa gì** — chứng minh `products` không phải nguyên nhân, `ILIKE` mới là nguyên nhân thật. Đã revert nhầm lẫn ban đầu (đổi `products` sang `sku`) về lại `products` trước khi commit, để không tạo thay đổi không cần thiết ngoài phạm vi bug thật.

Ghi chú thêm: `physical_codes.item_id`/`container_id` (dùng trong `traceByPhysicalCode()`) và `container_items.is_active` (dùng trong `traceByPhysicalCode()`, `buildSubledgerCounts()`, và 3 chỗ khác ngoài phạm vi task này) đều đã xác nhận là cột thật, tồn tại trên mọi driver — không phải bug.

### 4. Rà soát không phát hiện thêm lỗi

- `OperationalReportController.php` (1164 dòng, 8 báo cáo + export Excel/PDF): join `products`/`p.code`/`p.name` ở nhiều nơi (`buildInventoryBalance`, `buildXnt`, `buildLabelLog`, `buildOutboundByAgency`) — tất cả hợp lệ vì `products` là view thật (mục 3). Dùng `LOWER(...) LIKE ?` cho tìm kiếm (đã tự ghi chú driver-compatible, không dùng `ILIKE`) — đúng, không cần sửa.
- `ReconcileInventoryBalances.php`: đã đọc toàn bộ, logic phân biệt bảng `reconciliation_incidents` (pgsql, số nhiều) và `reconciliation_incident` (sqlite, số ít, view) đúng theo comment giải thích; test trực tiếp bằng cách tạo mismatch thật (balance bị sửa tay lệch khỏi ledger) → command phát hiện đúng, ghi incident đúng, idempotent khi rerun (không tạo trùng), dry-run không ghi gì — xem test case mới ở mục 5.
- `StockMovementController::index/show/traceSource/traceByPhysicalCode` — không phát hiện lỗi mới, khớp với kết luận WWHN-191.
- `StockLedger.php` (`Modules/Warehouse/app/Services/`) — xác nhận lại là compatibility service không được route nào gọi (route hiện hành dùng `CurrentBaselineStockService`), đúng theo dead-code đã ghi nhận ở WWHN-190/191 — không xử lý vì ngoài phạm vi (đã là quyết định retire-scope rộng hơn).

### 5. Test coverage — trước và sau

| Endpoint / Command | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `GET /inventory/balances` (200, có `container_items` ACTIVE khớp SKU) | **Không có** (chỉ test nhánh item_instances qua WWHN-191, chưa từng có container_items ACTIVE khớp SKU trong fixture) | **Đã bổ sung** — phát hiện bug `item_instances.status` |
| `GET /stock-movements/reversible` (200, list + field mapping) | **Không có** — 0 test trong toàn repo | **Đã bổ sung** |
| `GET /stock-movements/reversible?search=...` | **Không có** | **Đã bổ sung** — phát hiện bug `ILIKE` trên SQLite |
| `GET /stock-movements/reversible` (401 unauthenticated) | **Không có** | **Đã bổ sung** |
| `php artisan inventory:reconcile` (mismatch thật từ post-receipt + corrupt tay balance) | Có coverage cũ (`Wwhn219InventoryReconcileCommandTest`) nhưng dùng dữ liệu dựng sẵn, không qua flow HTTP thật | **Đã bổ sung** — end-to-end qua HTTP post-receipt thật + đối soát, xác nhận idempotent khi rerun |
| `php artisan inventory:reconcile --dry-run` | Có (`Wwhn219InventoryReconcileCommandTest`) | Giữ nguyên, re-run xanh; bổ sung thêm 1 case độc lập trong file mới |

**File test mới**: `tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php` — 6 test case:

1. `test_stock_movements_reversible_lists_posted_receipt_with_correct_sku_fields` — lái qua flow post-receipt HTTP thật, verify `reversible` trả đúng `sku_code`/`sku_name`/`uom`/`original_qty`/`can_reverse`.
2. `test_stock_movements_reversible_search_filter_does_not_crash_on_sqlite` — verify filter `search` không crash (phát hiện bug ILIKE).
3. `test_stock_movements_reversible_requires_authentication` — 401 khi thiếu token.
4. `test_inventory_balances_list_succeeds_with_active_container_items_present` — tạo container thật qua `POST /containers` + `POST /containers/{id}/items` (quantity-mode), verify `GET /inventory/balances` trả 200 với `container_subledger_count ≥ 1` (phát hiện bug `item_instances.status`).
5. `test_inventory_reconcile_command_detects_and_records_manufactured_mismatch` — post-receipt thật rồi corrupt tay `inventory_balances.on_hand_qty`, verify command phát hiện, ghi đúng `divergence_type=QUANTITY_MISMATCH`, `status=OPEN`, và **idempotent** khi rerun (không tạo incident trùng).
6. `test_inventory_reconcile_command_dry_run_does_not_write_incident` — dry-run không ghi incident.

Kết quả chạy: `php artisan test tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php` → **6/6 passed, 43 assertions**.

Kết quả chạy full suite (xác nhận không regression): `php artisan test` → **379 tests, 363 passed, 16 skipped (baseline có sẵn, không liên quan), 0 failed, 4599 assertions**.

`vendor/bin/pint --test` cho 2 file controller đã sửa + file test mới → pass, không cần auto-fix.

### 6. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-204 / HN-INV-001) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "tồn kho backend" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát toàn diện, phát hiện và sửa 2 bug thật (`item_instances.status`, `ILIKE` không portable), loại bỏ 1 false-positive trước khi sửa nhầm | Mục 1-4 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — các endpoint là read-only (GET) + 1 CLI command; permission đã có middleware đúng theo route table ở mục "Phạm vi"; `inventory:reconcile` xác nhận idempotent bằng test mới (case 5) | Mục 5, test case 5 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — sau khi sửa 2 bug, không còn 500 lộ exception; 401/403/404 dùng `ApiResponse`/`CheckPermission` chuẩn (đã xác nhận ở WWHN-190/191 trước đó, không đổi) | Mục 2 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 6 test case HTTP end-to-end mới; **không cần cập nhật OpenAPI/Postman** vì cả 2 fix đều không đổi response JSON shape (chỉ sửa nguồn dữ liệu nội bộ/driver-compat của filter) | `tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php` |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần QA xác nhận độc lập.** Lưu ý quan trọng: Bug `item_instances.status` là P0 thật (crash 500 trên `GET /inventory/balances` bất kỳ khi nào SKU có container subledger — tình huống phổ biến trong vận hành thật) đã tồn tại từ sau fix WWHN-191; nếu QA/FE đã test tính năng này với dữ liệu có container trước `2026-08-15`, cần re-test sau khi merge. Bug `ILIKE` chỉ ảnh hưởng môi trường non-Postgres (dev/test SQLite), không ảnh hưởng production (Postgres) | Mục 2, 5 |

### 7. Phạm vi đã xác nhận và điểm còn mở (không tự ý mở rộng)

- **Không sửa** `StockLedger.php` (dead code, không route nào gọi) — đã xác nhận lại theo đúng phát hiện của WWHN-190/191, quyết định retire thuộc phạm vi rộng hơn cần BA/Tech Lead.
- **Không sửa** các dead code khác đã ghi nhận trước đó (`InboundDocumentController`, `StockDocumentService`, `PutawayController::registerInboundBox/storeTask`, `SafeStockConfig*`, `ReportController`/`ReportService` không có route) — ngoài phạm vi output "tồn kho backend" của task này.
- Không phát hiện blocker kỹ thuật P0/P1 nào khác còn mở phía BE2 sau khi đã sửa 2 bug trên.
- Đã cẩn trọng loại bỏ 1 false-positive (`products` table) trước khi đưa vào fix — tránh thay đổi không cần thiết ngoài phạm vi bug thật, đúng theo nguyên tắc "không tự ý mở rộng scope".

### 8. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: rà soát toàn diện toàn bộ output "tồn kho backend" (balance list/subledger, stock-movements list/show/trace-source/trace-by-code/reversible, reports inventory-balance/xnt/reconciliation/dashboard-quick, CLI reconcile); phát hiện và sửa đúng 2 bug thật (`InventoryBalanceController::buildSubledgerCounts()` sai cột, `StockMovementController::reversible()` dùng `ILIKE` không portable); chủ động điều tra và loại bỏ 1 false-positive trước khi sửa nhầm; 6 test case HTTP end-to-end mới, toàn bộ pass; full suite 363/363 pass (16 skip baseline không liên quan) tại HEAD `fad424283638254f0b8ccb441fc49e88e9e1a692` + thay đổi của task này; `pint` pass không cần auto-fix.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence mục 5-6 đáp ứng đúng Acceptance Criteria, đặc biệt re-test `GET /inventory/balances` với dữ liệu có container subledger sau khi merge fix.
- FE1 xác nhận response shape của `inventory/balances`, `stock-movements/reversible` đủ để tích hợp UI đối soát/reversal (shape không đổi so với trước, chỉ sửa lỗi nội bộ).
- BA/Tech Lead xác nhận (nhắc lại từ WWHN-190/191) có cần task riêng dọn dẹp toàn bộ dead code đã ghi nhận qua các task hay giữ nguyên hiện trạng.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
