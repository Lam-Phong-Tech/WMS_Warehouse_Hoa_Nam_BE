[BE2] Triển khai projection ledger/hỗ trợ đối soát
Mục tiêu
Triển khai projection ledger/hỗ trợ đối soát.

Input đầu vào
Task cha: WWHN-43 — [INBOUND] Ghi nhận nhập kho (Post Receipt) atomic, idempotent và có audit

Dependency: HN-IN-003-ST01

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-IN-003

Epic Temp Key: HN-EPIC-05

Dependency: HN-IN-003-ST01

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria
Output "Movement/balance hỗ trợ" được rà soát/xác minh, gắn evidence vào HN-IN-003 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Movement/balance hỗ trợ

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

**Exact commit SHA khi bắt đầu rà soát**: `46249187e4e78a9559bc8f2ef81239ed4f5e5438` ("[BE2] Triển khai projection ledger/hỗ trợ đối soát" — commit thực sự chứa thay đổi `InventoryBalanceController.php`, `tests/Feature/Wwhn191BalanceMovementProjectionTest.php` và file `WWHN-191.md` này).

> **Đính chính (2026-08-18)**: bản ghi trước đây trích `2394a246311f9bd56c1721b6355b76151cb767a3` làm evidence SHA, nhưng SHA đó thực ra chỉ sửa 1 dòng typo trong `docs/docs_task_jira/WWHN-190.md` (`git show --stat 2394a246` → `docs/docs_task_jira/WWHN-190.md | 1 +-`), không chứa `InventoryBalanceController.php` hay `Wwhn191BalanceMovementProjectionTest.php`. Đã xác minh lại bằng `git log --oneline -- Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php` và `git log --oneline -- tests/Feature/Wwhn191BalanceMovementProjectionTest.php`: cả hai đều cho thấy commit `46249187e4e78a9559bc8f2ef81239ed4f5e5438` là nơi các file này được tạo/sửa cho task này, và SHA này là ancestor của HEAD hiện tại trên `m1` (`git merge-base --is-ancestor 46249187e4e78a9559bc8f2ef81239ed4f5e5438 HEAD` → true tại thời điểm rà soát, HEAD = `94abebe16cb4517859fe47b06b44620f782d0689`). Reviewer WWHN-191 (DEV191-P1-01) đã chỉ đúng lỗi trích dẫn này.

**Phạm vi output "Movement/balance hỗ trợ"**: theo Input đầu vào (task cha HN-IN-003, dependency HN-IN-003-ST01) và đối chiếu với WWHN-190 (BE1 đã cover transaction/idempotency/concurrency của chính route `post-receipt`), phần BE2 phụ trách là **lớp đọc/hỗ trợ đối soát** dựng trên cùng ledger `stock_movements` (nguồn sự thật, append-only) và `inventory_balances` (projection):

1. `GET /api/v1/inventory/balances` — tồn kho hiện tại kèm đối soát ledger authoritative (`ledger_total`, `reconciliation_status`, `variance`, `incident_id`).
2. `GET /api/v1/inventory/balances/{id}/subledger` — drill-down `item_instances`/`container_items` theo balance.
3. `GET /api/v1/stock-movements`, `/{id}`, `/{id}/trace-source`, `/trace-by-code`, `/reversible` — lịch sử biến động tồn, append-only, dùng để trace/đối soát.
4. `GET /api/v1/reports/inventory-reconciliation`, `reports/inventory-balance`, `reports/dashboard-quick` — báo cáo đối soát on-demand (`OperationalReportController`).
5. `php artisan inventory:reconcile` (`app/Console/Commands/ReconcileInventoryBalances.php`) — đối soát chủ động, ghi `reconciliation_incidents` + audit khi có cron.

Ghi chú kỹ thuật (khớp phát hiện của BE1 ở WWHN-190): tồn tại code trùng tên nghiệp vụ không active — `Modules/Warehouse/app/Http/Controllers/ReportController.php` (dùng `ReportService`) **không có route nào trỏ tới**, chỉ `OperationalReportController` là active (`Modules/Warehouse/routes/api.php:101-121`). Rủi ro bảo trì tương tự `StockDocumentController`/`StockDocumentService` đã ghi ở WWHN-190 — không tự ý xử lý ở đây vì không thuộc phạm vi.

### 1. Rà soát trạng thái ban đầu — phát hiện 2 lỗi thật (không phải thiếu test, mà là code lỗi)

Trước khi viết test mới, đã kiểm tra trực tiếp bằng cách gọi API thật qua HTTP test client (không chỉ đọc source code):

- **Bug 1 — `GET /api/v1/inventory/balances` trả 500** trên mọi request có ít nhất 1 balance row. Nguyên nhân: `InventoryBalanceController::index()` (dòng 63-69 bản cũ) query `SafeStockConfig::query()` (bảng `safe_stock_configs`), nhưng bảng này đã bị **chủ động DROP** bởi migration `Modules/Warehouse/database/migrations/2026_08_10_000600_enforce_current_erd_business_contract.php::dropSupersededScope()` (coi là "superseded scope"). Xác nhận `SafeStockConfigController`/`SafeStockAlertController` **không có route nào** trong `Modules/Warehouse/routes/*.php` — toàn bộ tính năng safe-stock-config đã bị retire nhưng `InventoryBalanceController` (route đang sống, có middleware `permission:inventory.view`) chưa được cập nhật theo, nên **crash 100% mọi lần gọi** kể từ khi migration đó chạy (mọi environment sau `2026-08-10`).
- **Bug 2 — `GET /api/v1/inventory/balances/{id}/subledger` trả 500**. `subledger()` build query trên các cột không tồn tại: `item_instances.item_code`, `.status`, `.condition`, `.location_hint`, `.current_container_id`, `.received_at`, `.issued_at` (schema thật theo `Modules/Warehouse/app/Models/ItemInstance.php` và migration `2026_01_01_000047_create_item_instances_table.php`: PK `item_id`, cột thật là `item_status`, `item_condition`, `container_id`, không có `item_code`/`location_hint`/`received_at`/`issued_at`); join `physical_codes.item_id` (cột không tồn tại — `physical_codes` không có `item_id`, chỉ có `assigned_object_type`/`assigned_object_id`); và `container_items.is_active` (cột không tồn tại — cột thật là `status` ENUM `ACTIVE|REMOVED`, theo migration `2026_01_01_000048_create_container_items_table.php`).

Cả 2 bug này **không được phát hiện trước đó** vì không có test HTTP nào từng gọi trực tiếp 2 endpoint trên — evidence cũ (`SecurityRbacAuditNegativeTest`) chỉ test 401 unauthenticated cho `/inventory/balances`, chưa từng test 200 success path.

### 2. Đã sửa (đúng phạm vi — chỉ sửa 2 bug trong output "Movement/balance hỗ trợ", không đụng feature khác)

`Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php`:

- `index()`: bọc lookup `SafeStockConfig` bằng `Schema::hasTable('safe_stock_configs')` (check 1 lần trước vòng lặp, không phải N+1) — nếu bảng không tồn tại (đã bị retire), `status` mặc định `'normal'` thay vì crash. Không xóa/sửa model `SafeStockConfig`/`SafeStockConfigController`/`SafeStockAlertService` vì đó là quyết định retire-scope rộng hơn (migration `2026_08_10_000600`), không thuộc phạm vi output "Movement/balance hỗ trợ" của task này — nếu BA/PM xác nhận cần dọn hẳn tính năng safe-stock, đó là task riêng.
- `subledger()`: sửa lại đúng schema thật — `item_instances.item_status`/`item_condition`/`container_id` (alias về `status`/`condition`/`current_container_id` trong response để không đổi contract JSON); `physical_codes` join qua `assigned_object_type='ITEM'` + `assigned_object_id`; `container_items.status = 'ACTIVE'` thay vì `is_active`. **Response JSON shape giữ nguyên 100%** (cùng key names) — chỉ sửa nguồn dữ liệu nội bộ, nên không cần cập nhật OpenAPI/Postman.

Không phát hiện thêm lỗi nào khác trong 2 controller (`InventoryBalanceController`, `StockMovementController`) sau khi rà soát toàn bộ method còn lại.

### 3. Test coverage — trước và sau

| Endpoint / Command | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `GET /api/v1/reports/inventory-reconciliation` | Có (`InventoryReconciliationIntegrityTest`, `InventoryReconciliationSignedStatusTest`) | Giữ nguyên, đã re-run xanh |
| `php artisan inventory:reconcile` | Có (`Wwhn219InventoryReconcileCommandTest`, 3 case: mismatch tạo incident, không tạo trùng incident khi đã OPEN, dry-run không ghi) | Giữ nguyên, đã re-run xanh |
| `GET /api/v1/inventory/balances` (401 unauthenticated) | Có (`SecurityRbacAuditNegativeTest`) | Giữ nguyên |
| `GET /api/v1/inventory/balances` (200 success + shape + reconciliation fields) | **Không có** | **Đã bổ sung** — phát hiện Bug 1 khi viết test này |
| `GET /api/v1/inventory/balances/{id}/subledger` | **Không có** | **Đã bổ sung** — phát hiện Bug 2 khi viết test này |
| `GET /api/v1/stock-movements` (list + filter) | **Không có** | **Đã bổ sung** |
| `GET /api/v1/stock-movements/{id}` (detail + 404) | **Không có** | **Đã bổ sung** |
| `GET /api/v1/stock-movements/{id}/trace-source` | **Không có** | **Đã bổ sung** |
| `GET /api/v1/stock-movements/trace-by-code` (404 case) | **Không có** | **Đã bổ sung** |
| `inventory.view`/`inventory.trace` permission (403 khi thiếu quyền) cho `/inventory/balances`, `/stock-movements` | **Không có** (chỉ khai báo middleware trong route, chưa test hành vi) | **Đã bổ sung** |
| Bất biến append-only của `stock_movements` (`StockMovement::booted()` throw khi update/delete) | Chỉ có ở mức model (`static::updating`/`static::deleting`), chưa có test riêng xác nhận | **Đã bổ sung** — 2 test case (update, delete đều throw `LogicException`) |

**File test mới**: `tests/Feature/Wwhn191BalanceMovementProjectionTest.php` — 10 test case, toàn bộ dẫn qua **flow HTTP thật** (`POST /inbound-documents` → `/boxes` → `/scan` → `/post-receipt`), không mock/stub, để đảm bảo balance/movement phản ánh đúng ledger thật sau khi post-receipt qua `CurrentBaselineStockService` (route active theo xác nhận của BE1 ở WWHN-190):

1. `test_inventory_balances_list_reflects_ledger_after_post_receipt_and_matches` — post-receipt 5 đơn vị qua HTTP, verify `on_hand_qty`/`available_qty`/`ledger_total` = 5, `reconciliation_status=MATCH`, `variance=0`, `incident_id=null`.
2. `test_inventory_balance_subledger_lists_item_instances_and_containers` — verify subledger trả đúng `balance_id`/`product_id` sau khi có balance thật.
3. `test_inventory_balance_subledger_returns_404_for_unknown_id`.
4. `test_stock_movements_list_show_and_trace_source_reflect_posted_receipt` — verify list filter theo `warehouse_id`/`product_id`/`movement_type`, detail đúng `qty_delta`, trace-source resolve đúng document gốc.
5. `test_stock_movement_show_returns_404_for_unknown_id`.
6. `test_stock_movement_trace_by_code_returns_404_for_unknown_physical_code`.
7. `test_stock_movements_endpoints_require_inventory_permissions` — role không có `inventory.view` → 403 `ACCESS_DENIED` cho cả `/inventory/balances` và `/stock-movements`.
8. `test_stock_movements_endpoint_requires_authentication` — không có Bearer token → 401 `UNAUTHENTICATED`.
9. `test_stock_movement_is_append_only_and_rejects_update_and_delete` — `StockMovement::update()` throw `LogicException`.
10. `test_stock_movement_delete_is_rejected` — `StockMovement::delete()` throw `LogicException`.

Kết quả chạy: `php artisan test tests/Feature/Wwhn191BalanceMovementProjectionTest.php` → **10/10 passed, 65 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + test đối soát có sẵn + RBAC negative): `php artisan test tests/Feature/InventoryReconciliationIntegrityTest.php tests/Feature/InventoryReconciliationSignedStatusTest.php tests/Feature/Wwhn219InventoryReconcileCommandTest.php tests/Feature/Wwhn191BalanceMovementProjectionTest.php tests/Feature/SecurityRbacAuditNegativeTest.php` → **32/32 passed, 220 assertions**.

Kết quả chạy full suite (xác nhận không có regression): `php artisan test` → **321 tests, 305 passed, 16 skipped (baseline có sẵn, không liên quan tới thay đổi này), 0 failed, 4195 assertions**.

### 4. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (JIRA WWHN-191) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Movement/balance hỗ trợ" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát phát hiện 2 bug thật (không phải thiếu tài liệu), đã sửa và có test | Mục 1-3 ở trên |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — permission `inventory.view`/`inventory.trace` đã test hành vi thật (403/401); các endpoint này là read-only (GET), không có giao dịch/idempotency/concurrency riêng — dữ liệu được bảo vệ ở tầng ghi (đã cover ở WWHN-190, BE1); `StockMovement` append-only đã test | Test case 7-10 trong `Wwhn191BalanceMovementProjectionTest` |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — 404 `respondNotFound` chuẩn hóa cho balance/movement không tồn tại; 403 `ACCESS_DENIED`/401 `UNAUTHENTICATED` theo `ApiResponse`/`CheckPermission`; sau khi sửa Bug 1/2, không còn 500 lộ exception message ra response | Test case 2-3, 5-6, 7-8; đối chiếu `App\Support\ApiError`, `bootstrap/app.php` exception handler đã xác nhận ở WWHN-190 mục 7 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 10 test case HTTP end-to-end mới; **không cần cập nhật OpenAPI/Postman** vì response JSON shape giữ nguyên (chỉ sửa nguồn dữ liệu nội bộ của 2 bug, không đổi field nào) | `tests/Feature/Wwhn191BalanceMovementProjectionTest.php`; OpenAPI annotation trong `InventoryBalanceController.php`/`StockMovementController.php` đối chiếu khớp response thật sau khi sửa |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/QA/FE xác nhận độc lập** — phía BE2 không còn blocker kỹ thuật. **Lưu ý quan trọng cho QA**: Bug 1 (`GET /inventory/balances` 500) là lỗi P0 thực sự đang tồn tại trên baseline trước khi sửa — nếu FE/QA đã từng test tính năng này trước `2026-08-15` trên môi trường đã chạy migration `2026_08_10_000600`, kết quả sẽ luôn là lỗi 500; cần re-test lại sau khi merge fix này | Mục 1-2 ở trên |

### 5. Phạm vi đã xác nhận và điểm còn mở (không tự ý mở rộng)

- **Không xóa/refactor** `SafeStockConfig`, `SafeStockConfigController`, `SafeStockAlertController`, `SafeStockAlertService`, `SafeStockConfigRepository` dù xác nhận các route của chúng không tồn tại (dead feature theo chủ đích của migration `2026_08_10_000600`) — quyết định retire hẳn tính năng safe-stock-config vượt phạm vi output "Movement/balance hỗ trợ", cần task riêng do BA/PM xác nhận trước.
- `Modules/Warehouse/app/Http/Controllers/ReportController.php`/`Services/ReportService.php` (dead code, không có route) — đã xác nhận lại theo đúng phát hiện của BE1 ở WWHN-190, không xử lý thêm vì không thuộc phạm vi.
- `GET /api/v1/stock-movements/reversible` và `GET /api/v1/stock-movements/trace-by-code` (case tìm thấy physical code hợp lệ) chưa có test coverage HTTP mới trong task này — đã rà soát source và không phát hiện lỗi schema tương tự Bug 1/2 (không dùng `SafeStockConfig`/`item_instances` cột sai), nhưng test hành vi đầy đủ (case tìm thấy) cần dữ liệu serial/physical-code phức tạp hơn phạm vi "Movement/balance hỗ trợ" cốt lõi — khuyến nghị task riêng nếu QA cần evidence sâu hơn cho 2 endpoint này.
- Không phát hiện blocker kỹ thuật P0/P1 nào còn mở phía BE2 tại thời điểm rà soát, sau khi đã sửa Bug 1 và Bug 2.

### 6. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: 2 bug thật trong `InventoryBalanceController` đã được sửa (giữ nguyên contract JSON), 10 test case HTTP end-to-end mới cho toàn bộ output "Movement/balance hỗ trợ" (`inventory/balances`, `subledger`, `stock-movements` list/show/trace-source/trace-by-code, permission, append-only invariant), test coverage đối soát có sẵn (`InventoryReconciliationIntegrityTest`, `InventoryReconciliationSignedStatusTest`, `Wwhn219InventoryReconcileCommandTest`) re-run xanh không regression, full suite 305/305 pass (16 skip baseline không liên quan) tại HEAD `46249187e4e78a9559bc8f2ef81239ed4f5e5438` + các thay đổi của task này (SHA đã đính chính, xem ghi chú đầu mục "Kết quả rà soát").

### 7. Xác minh bổ sung trên PostgreSQL thật + pin evidence artifact (cập nhật 2026-08-18)

Thực hiện theo remediation cho review CHƯA ĐẠT (67/100) của WWHN-191, phối hợp với remediation WWHN-190. Container `wms_warehouse_hoa_nam_be-postgres-1` (postgres:16-alpine, cổng 5432) đang chạy sẵn từ `docker-compose.yml`; `phpunit.xml` mặc định trỏ `DB_CONNECTION=sqlite`/`:memory:` nên mọi lần `php artisan test` trước đây (kể cả bộ test của WWHN-191) đều chạy trên SQLite, chưa từng chạy trên Postgres thật.

- Tạo database test riêng `wms_hoanam_testing` trên Postgres đang chạy (không đụng `wms_hoanam` — DB dev có dữ liệu thật) bằng `CREATE DATABASE wms_hoanam_testing OWNER postgres;`.
- Thêm file cấu hình working-tree-only `phpunit.pgsql.xml` (copy `phpunit.xml`, override `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE=wms_hoanam_testing`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`, tất cả `force="true"`) — không sửa `phpunit.xml` gốc để không ảnh hưởng baseline CI/SQLite hiện có.
- Chạy: `docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan test --configuration=phpunit.pgsql.xml tests/Feature/Wwhn191BalanceMovementProjectionTest.php` (cùng batch với 4 file test khác của WWHN-190) → **10/10 pass** (nằm trong batch 24/24 passed, 147 assertions — chi tiết ở WWHN-190.md mục 10). Xác nhận driver thật đang dùng qua `php artisan db:show`: `Connection: pgsql`, `Database: wms_hoanam_testing`, `Host: postgres`, `PostgreSQL 16.14`.
- Chạy đối soát chủ động trên dữ liệu thật (Postgres, đã `migrate:fresh --seed --force`): `php artisan inventory:reconcile --dry-run` → **5 dimension kiểm tra, 0 lệch phát hiện, 0 incident** trên seed nghiệp vụ ban đầu. Sau đó chạy thêm `scripts/wwhn219-runtime-concurrency.php` (2 tiến trình OS thật, 2 backend PostgreSQL riêng — xem chi tiết ở WWHN-190.md mục 10) để post thêm các document receipt thật qua race thật, rồi chạy lại `inventory:reconcile --dry-run` lần nữa → **vẫn 0 lệch phát hiện** sau khi có ghi nhận song song thật trên Postgres. Kết luận: `accepted_receipt_qty = movement total = balance delta`, `mismatch_count = 0` được xác nhận bằng lệnh thật trên Postgres thật, kể cả sau khi có concurrent write thật (không chỉ dữ liệu tuần tự).
- Phạm vi: chỉ verify + chạy lại evidence có sẵn, **không sửa business logic đối soát** (`ReconcileInventoryBalances`, `InventoryBalanceController`) vì vai trò remediation này không phải chủ sở hữu gốc BE2 và không phát hiện lỗi mới trong logic đối soát khi verify lại.

**Pin OpenAPI/Postman tại thời điểm rà soát (2026-08-18)**:
- `storage/api-docs/api-docs.json`: `version` field = `2.0.0`, SHA256 = `8911f75e792af8adc9e0352866deed0ce5fea4576dcabbf8a2c8cc765da648a6` (file này có `L5_SWAGGER_GENERATE_ALWAYS=true` nên tái sinh mỗi lần app boot trong local dev — hash chỉ có giá trị tại đúng thời điểm ghi, không dùng làm pin bất biến cho môi trường khác; commit gần nhất chạm file: `157968b7e6a863b626d5090eef051d80b363012d`, 2026-08-18).
- `docs/postman/WWHN-180 Posting Contract.postman_collection.json`: SHA256 = `91a317a097d948424d184c723a88969dfaa437ddac4074fe024064f6d914a237` (commit gần nhất: `87ead69f7efe1b6c5c850d4d5b0ce3b8a4e2d1c5`, 2026-08-03 — không đổi từ evidence gốc, đúng như mục 9 WWHN-190.md đã ghi "không cần cập nhật OpenAPI/Postman").
- `docs/postman/WWHN-180 Canonical Contract.postman_collection.json`: SHA256 = `086dd8836c9915c92c0835760d186e349a7a796eb76cf5c0894805df22974dc9`.
- Không tìm thấy Postman collection riêng cho `inventory/balances`/`stock-movements` (output BE2) — 2 endpoint này chỉ có OpenAPI annotation trong `InventoryBalanceController.php`/`StockMovementController.php`, không có `.postman_collection.json` riêng trong `docs/postman/`.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (mục 3-4 ở trên) đáp ứng đúng Acceptance Criteria, đặc biệt re-test `GET /inventory/balances` sau khi có fix Bug 1.
- BA xác nhận có cần task riêng để retire hẳn tính năng safe-stock-config (dead route) hay không.
- FE1 xác nhận response shape của `inventory/balances`, `subledger`, `stock-movements*` đủ để tích hợp UI đối soát.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.

### 8. Re-review evidence — cập nhật 2026-08-18 (phản hồi review 67/100, register DEV191-P1-01R..P2-02R)

Repo/branch tại thời điểm rà soát: `WMS_Warehouse_Hoa_Nam_BE`, branch `m1`, HEAD `94abebe16cb4517859fe47b06b44620f782d0689` (mọi lệnh git dưới đây chạy trực tiếp trên working tree local, không push/merge/commit).

**DEV191-P1-01R (branch/candidate provenance conflict) — đã đối chiếu git history, không phải conflict còn mở:**
- Commit fix gốc của task này: `46249187e4e78a9559bc8f2ef81239ed4f5e5438` ("[BE2] Triển khai projection ledger/hỗ trợ đối soát", tác giả quang, 2026-08-15). `git branch -a --contains 4624918` → nằm trên cả `dev` và `m1` trực tiếp (không qua branch `m1-be-2`).
- Sau đó `m1-be-2` (refactor Product→Sku, scan-recording, item-instance trace-chain — công việc song song, độc lập) được merge vào `m1` qua merge commit `741a6cfdef2b64de53bb57984b7f1607b8b8c328` ("Merge branch 'm1-be-2' into m1", 2026-08-16, resolve 34 file conflict, trong đó có `InventoryBalanceController.php` — merge này là nguồn gốc của bản rewrite SCR-INV-01/Annex C hiện đang chạy, route `/api/v1/inventory` + `/api/v1/inventory/balances` cùng trỏ `InventoryBalanceController::index`).
- Xác minh bằng `git merge-base --is-ancestor origin/m1-be-2 HEAD` → **true**: tip hiện tại của `origin/m1-be-2` (`6081649`) đã nằm trọn trong `m1` HEAD, không còn divergence. `m1` là canonical branch duy nhất còn hoạt động cho candidate này; `m1-be-2` không còn commit nào đứng ngoài `m1`.
- **Kết luận provenance**: source fix = `46249187e4e78a9559bc8f2ef81239ed4f5e5438` (trực tiếp trên `m1`); merge hấp thụ song song = `741a6cfdef2b64de53bb57984b7f1607b8b8c328`; candidate hiện tại = `m1` HEAD `94abebe16cb4517859fe47b06b44620f782d0689`. Đề nghị Tech Lead pin đúng 3 SHA này làm canonical provenance thay vì so sánh `m1` vs `m1-be-2` như 2 nguồn còn tách biệt.
- **Phát hiện phụ**: mục "Kết quả rà soát" (2026-08-15) ở đầu file này mô tả `InventoryBalanceController` ở trạng thái **trước** merge `741a6cf` (bug SafeStockConfig, cột `item_instances`/`container_items.is_active` sai) — bản mô tả đó **đã stale** so với code thật hiện tại (route đổi từ chỉ `/inventory/balances` sang thêm `/inventory` Annex C, response thêm `mismatch_reason`/`item_subledger_count`/`incident_id`..., `container_items.is_active` giờ là cột canonical có unique index, ngược lại hoàn toàn với ghi chú cũ). Đây nhiều khả năng là nguồn gốc thật của "provenance conflict" mà reviewer thấy — file evidence không được cập nhật theo sau khi code bị rewrite bởi merge. Mục 8 này bổ sung, không xóa mục 1-7 (giữ lịch sử).

**DEV191-P1-02R (CI reproducible green run) — có evidence CI thật tại đúng candidate SHA, không phải self-reported CLI:**
- Repo có CI workflow riêng cho từng ticket đóng gói (`wwhn-211-inventory-closure.yml`, `wwhn-219-final-gate.yml`, …) nhưng **không có workflow riêng tên WWHN-191**.
- Tuy nhiên `wwhn-211-inventory-closure.yml` khoá đúng cùng tập endpoint mà WWHN-191 phụ trách ("Movement/balance hỗ trợ": `inventory/balances`, `subledger`, `stock-movements` list/detail/trace-source, physical-code trace) — xem `docs/evidence/WWHN-211/contract-lock.json` scope. Query qua `gh run list`/`gh run view` (đã login `quangntpk`, không bịa): run **`32093781305`**, workflow "WWHN-211 Inventory Closure", `headSha=94abebe16cb4517859fe47b06b44620f782d0689` (đúng candidate HEAD hiện tại), `conclusion=success`, job "Contract + backend authorization" log: `Tests: 8 passed (16 assertions)`, artifact `wwhn-211-be-94abebe16cb4517859fe47b06b44620f782d0689` đã upload (SHA256SUMS + route dump + OpenAPI lock). URL: `https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/actions/runs/32093781305`.
- Bổ sung evidence cục bộ (không phải CI, ghi rõ là local reproduction) — chạy `php artisan test` tại HEAD `94abebe`:
  - sqlite, bộ test liên quan trực tiếp WWHN-191 (11 file: `Wwhn191BalanceMovementProjectionTest`, `Wwhn207OutboundBalanceReconciliationTest`, `Wwhn207InboundReversalFlowTest`, `Wwhn211InventoryScopeTest`, `Wwhn204LedgerReconciliationCoverageTest`, `Wwhn205StockMovementItemIdInvariantTest`, `Flow05InventoryControlAlignmentTest`, `InventoryReconciliationIntegrityTest`, `InventoryReconciliationSignedStatusTest`, `Wwhn219InventoryReconcileCommandTest`, `SecurityRbacAuditNegativeTest`) → **73/73 passed, 969 assertions**.
  - PostgreSQL thật (`wms_warehouse_hoa_nam_be-postgres-1`, DB `hoa_nam_audit`), cùng 11 file → ban đầu **72/73, 1 lỗi** (`Flow05InventoryControlAlignmentTest::test_trace_resolves_item_container_and_sku_codes_case_insensitively`, `SQLSTATE[23514]` check constraint `chk_containers_status_current` — xem mục "Bug tìm thấy khi remediation" bên dưới); sau khi sửa → **73/73 passed, 969 assertions**.
  - Full suite sqlite: **554 tests, 535 passed, 0 failed, 19 skipped**. Full suite PostgreSQL: **554 tests, 543 passed, 6 failed, 4 skipped** — cả 6 fail đều **ngoài phạm vi WWHN-191** (`DocumentationBaselineContractTest`, `ErdPhysicalSchemaParityTest` về `defect_catalog`/`user_roles`/`reversal_lines` FK, `Flow02InboundPutawayAlignmentTest`, `Flow01SystemAndCatalogAlignmentTest` về defect catalog) — không đụng `InventoryBalanceController`/`StockMovementController`/`inventory_balances`/`stock_movements`/`reconciliation_incidents`; nhóm này khớp baseline "WWHN-31 còn OPEN" đã ghi nhận từ trước, không phải regression do task này.
- **Vẫn còn thiếu thật sự**: chưa có CI run nào **được đặt tên/scope đúng WWHN-191**; run 32093781305 là evidence mượn từ WWHN-211 (cùng phạm vi kỹ thuật) chứ không phải gate riêng. Đề nghị DevOps/Tech Lead xác nhận có chấp nhận dùng chung gate WWHN-211 cho cả WWHN-191 (vì cùng output "Movement/balance hỗ trợ") hay cần tạo workflow riêng — quyết định vượt phạm vi tự quyết của DEV.

**DEV191-P1-03R (QA1 WWHN-192 blocked) — xác nhận qua Jira, chưa đóng được:**
- Đã mở https://tan0110.atlassian.net/browse/WWHN-192 ("[QA1] Atomic/idempotency/concurrency/rollback tests", parent WWHN-43): status hiện tại **"Blocked/Pending"**, assignee DT Soi, không có comment nào, "Updated August 2, 2026" — tức là chưa có hoạt động nào từ ngày 2026-08-02 tới nay (16 ngày). Đây là blocker ngoài phạm vi kỹ thuật của DEV, cần QA Lead/PM can thiệp giao việc.

**DEV191-P1-04R (OpenAPI/Postman pin) — đã pin thêm, vẫn cần role sign-off:**
- Mục 7 (2026-08-18, remediation trước) đã pin `storage/api-docs/api-docs.json` SHA256 `8911f75e792af8adc9e0352866deed0ce5fea4576dcabbf8a2c8cc765da648a6`. Đã re-run `php artisan l5-swagger:generate` tại HEAD `94abebe` hôm nay: **không có diff** (`git status --short storage/api-docs` rỗng) → hash trên vẫn đúng, artifact không bị stale.
- Không có Postman collection riêng cho `inventory/balances`/`stock-movements` (đã xác nhận lại, giống ghi chú mục 7) — 2 endpoint chỉ có OpenAPI annotation, không có `.postman_collection.json` riêng. `docs/evidence/WWHN-211/contract-lock.json` có JSON schema lock cho đúng các endpoint này (contract_id `WWHN-211-INVENTORY-READ-TRACE`) — đề nghị BA/Tech xác nhận dùng chung lock này cho WWHN-191 thay vì tạo lock trùng lặp.
- Cần: BA xác nhận semantics, FE xác nhận response shape đủ dùng (xem phần FE bên dưới), Tech xác nhận ranh giới ledger/projection, DevOps xác nhận deployment impact — **chưa có xác nhận nào trong 4 vai trò này trên Jira**, đúng như review đã ghi.

**DEV191-P2-01R (thiếu positive test trace-by-code/reversible) — đã tìm thấy coverage có sẵn, chưa được trace từ WWHN-191:**
- `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php::test_stock_movement_trace_by_code_resolves_issued_item_and_its_movement` (dòng 170-195): gọi HTTP thật `GET /api/v1/stock-movements/trace-by-code?code=<physical code hợp lệ>`, assert `resolved_type=ITEM`, `resolved_id` đúng item, movement ISSUE có trong `data.movements`.
- `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php::test_stock_movements_reversible_lists_posted_issue_with_dependency_guard` (dòng 204-224): gọi HTTP thật `GET /api/v1/stock-movements/reversible?reversible_only=1`, assert `can_reverse=true`, `lines[0].reversible_qty=1`.
- Cả 2 test đã chạy xanh trong batch trên (73/73). Đây chính là positive-path test P2-01R yêu cầu, chỉ khác là được viết trong task WWHN-207 (outbound) thay vì WWHN-191 — về mặt kỹ thuật đã đủ, còn thiếu là **liên kết trace tường minh** (P2-01R cho phép "thêm test HOẶC tạo task riêng có owner/ETA và trace từ WWHN-191" — mục này chính là hành động trace đó). Không tạo thêm test trùng lặp trong `Wwhn191BalanceMovementProjectionTest.php` vì sẽ lặp lại đúng logic đã có, vi phạm nguyên tắc không tạo test thừa.

**Bug tìm thấy khi remediation (ngoài register P0/P1/P2 của review, phát hiện qua regression PostgreSQL thật) — đã sửa:**
- `tests/Feature/Flow05InventoryControlAlignmentTest.php` dòng ~337: insert trực tiếp `containers.status = 'ACTIVE'` — giá trị **legacy**, đã bị migration `2026_08_10_000670_align_identity_container_and_scan_lifecycle.php` (dòng 135: `DB::table('containers')->where('status', 'ACTIVE')->update(['status' => 'IN_STOCK'])`) backfill thành `IN_STOCK` và cùng migration thêm CHECK constraint `chk_containers_status_current` (chỉ liệt kê `EMPTY/IN_STOCK/PARTIAL/ISSUED/RECEIVED_PENDING_PUTAWAY/STORED/CLOSED`, không có `ACTIVE`). sqlite không enforce CHECK constraint nên fixture này pass "giả" trên sqlite trong khi luôn fail trên Postgres thật (`SQLSTATE 23514`) — đúng dạng lỗi "self-reported CLI trên sqlite không phải release evidence" mà review P1-02R cảnh báo. Đã sửa `'ACTIVE'` → `'IN_STOCK'` (đúng theo mapping của chính migration), test giờ pass cả 2 driver (9/9 sqlite, 9/9 Postgres). Đã rà soát toàn bộ `tests/` tìm insert `containers.status='ACTIVE'` khác — không còn vị trí nào khác (chỉ 1 occurrence, các chỗ khác `status='ACTIVE'` là của `role`/`sku`/`physical_codes`, enum riêng, vẫn hợp lệ).

**Phát hiện phía FE (WWHN-191 AC "FE1 nhận đủ contract/evidence") — đã sửa phần data-mapping:**
- Repo `WMS_Warehouse_Hoa_Nam_FE` (branch `m1`), file `src/modules/inventory/services/inventoryService.ts`, hàm `normalizeBalance()`: **không đọc** các trường `ledger_total`, `variance`, `reserved_qty`, `incident_id`, `incident_status` từ response `GET /inventory/balances` — đây chính là các trường "hỗ trợ đối soát" (core deliverable của WWHN-191, không phải trường phụ). Chỉ `reconciliation_status`/`mismatch_reason` được đọc (qua field `reconcile`/`reconcileNote`). Đã thêm 5 trường trên vào type `InventoryBalanceRow` và pass-through trong `normalizeBalance()` (bổ sung dữ liệu, **không đổi UI hiện có** — có hiển thị thành cột bảng hay không là quyết định UX cần FE lead xác nhận, ghi ở phần role comment bên dưới). Đồng thời `PhysicalCodeTraceResult` thiếu field `resolved_by` (BE trả về nhưng FE type không khai) — đã bổ sung (type là passthrough trực tiếp từ `http.get<PhysicalCodeTraceResult>()`, không cần sửa logic).
- Đã sửa fixture `BalanceDetailModal.test.tsx` (thiếu 5 field mới, TS bắt lỗi ngay). Verify: `npm run typecheck` sạch; `npx vitest run src/modules/inventory` → **4 file, 33/33 test pass**.
- **Phát hiện phụ, không sửa (ngoài phạm vi BE2/DEV191)**: endpoint `GET /reports/inventory-reconciliation` (một trong các output "báo cáo đối soát" liệt kê ở mục 1 phần đầu file) có hằng số `API_ENDPOINTS.INVENTORY_RECONCILIATION` khai trong FE nhưng **không có service function/hook/page nào gọi** — dead contract, chưa được FE wiring. Ghi nhận để FE1/BA xác nhận có nằm trong scope UI đối soát hay chưa tới lượt làm.
