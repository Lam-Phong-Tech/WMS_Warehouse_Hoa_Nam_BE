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

**Exact commit SHA khi bắt đầu rà soát**: `2394a246311f9bd56c1721b6355b76151cb767a3` (HEAD của `m1`, cùng SHA với evidence WWHN-190 của BE1).

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

Đạt: 2 bug thật trong `InventoryBalanceController` đã được sửa (giữ nguyên contract JSON), 10 test case HTTP end-to-end mới cho toàn bộ output "Movement/balance hỗ trợ" (`inventory/balances`, `subledger`, `stock-movements` list/show/trace-source/trace-by-code, permission, append-only invariant), test coverage đối soát có sẵn (`InventoryReconciliationIntegrityTest`, `InventoryReconciliationSignedStatusTest`, `Wwhn219InventoryReconcileCommandTest`) re-run xanh không regression, full suite 305/305 pass (16 skip baseline không liên quan) tại HEAD `2394a246311f9bd56c1721b6355b76151cb767a3` + các thay đổi của task này.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (mục 3-4 ở trên) đáp ứng đúng Acceptance Criteria, đặc biệt re-test `GET /inventory/balances` sau khi có fix Bug 1.
- BA xác nhận có cần task riêng để retire hẳn tính năng safe-stock-config (dead route) hay không.
- FE1 xác nhận response shape của `inventory/balances`, `subledger`, `stock-movements*` đủ để tích hợp UI đối soát.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
