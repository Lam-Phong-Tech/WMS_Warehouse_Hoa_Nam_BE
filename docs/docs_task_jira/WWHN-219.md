# [BE2] WWHN-219 — Tăng cường audit/đối soát/ràng buộc dữ liệu

## Kiểm soát

- Parent Temp Key: `HN-SEC-001`; Epic Temp Key: `HN-EPIC-03`; Dependency: `HN-SEC-001-ST01`.
- Task cha: `WWHN-54` — [HARDEN] Phân quyền, idempotency, concurrency và audit tăng cường.
- Role: BE2 — Duy Khánh Nguyễn. Assignee Placeholder: `HN-BE-02`.
- Repo/branch: `Lam-Phong-Tech/Hoa_Nam_Backend` / `m1`.
- Ngày rà soát: 2026-08-14.
- Task chị em: `WWHN-218` (BE1 — phân quyền/idempotency/concurrency/audit cơ bản). Báo cáo này **không lặp lại** phần BE1 đã cover, chỉ bổ sung phần audit mở rộng, đối soát (reconciliation) và ràng buộc dữ liệu (constraint) thuộc phạm vi BE2.
- Status: `REVIEWED / FIXED PARTIAL` — đã rà soát toàn diện, phát hiện và sửa các gap thật (có bằng chứng test PASS/FAIL cụ thể), còn 1 số điểm cần disposition từ BA/QA/DevOps trước khi đóng hẳn `HN-SEC-001` (xem "Blocker / Open point").

BE2 không tự ghi `APPROVED`/`Done`/`100%`. Việc đóng task cần FE1/QA xác nhận đã nhận đủ contract/evidence theo đúng DoD của `WWHN-219`.

## Tóm tắt kết quả rà soát

Phương pháp: đọc trực tiếp source (Modules/AuditLog, Modules/Warehouse, Modules/Auth, app/Support, app/Console/Commands, database/migrations, routes), đối chiếu với route thật (`grep`/route file, không suy đoán), và **chạy PHPUnit thật** để xác nhận trạng thái PASS/FAIL trước khi kết luận — không chỉ đọc code tĩnh.

| Hạng mục | Trạng thái trước rà soát | Hành động | Trạng thái sau |
|---|---|---|---|
| Đối soát (API `reports/inventory-reconciliation`) | **2 test FAIL thật** (contract response thiếu field) | Sửa `OperationalReportController::buildInventoryBalance()` | 3/3 test PASS |
| Đối soát chủ động (ghi vết + cảnh báo) | Không tồn tại — chỉ on-demand qua API, không lưu vết | Thêm command `inventory:reconcile` + audit log | Có, 3 test mới PASS |
| Audit — `WarrantyController::tempOnly` | Route active thật, mutate dữ liệu, **không audit** | Thêm `AuditLogger::record()` | Có audit, 1 test mới PASS |
| Ràng buộc dữ liệu DB (CHECK/trigger/unique) | Đầy đủ trên Postgres, nhưng 40+ migration chỉ chạy trên `pgsql`, không được test bởi CI (SQLite) | Ghi nhận rủi ro, không sửa (ngoài phạm vi thay schema) | Ghi nhận vào Open point |
| Audit — dead code (CanonicalMvpController, SafeStockConfigController...) | Có mutation không audit nhưng không có route active | Xác nhận dead code qua route file, không sửa (tránh mở rộng scope) | Ghi nhận disposition |

## 1. Đối soát (Reconciliation) — phát hiện và sửa bug thật

### 1.1 Bug xác nhận bằng test thật (trước khi sửa)

Chạy `php artisan test --filter=InventoryReconciliationIntegrityTest` và `InventoryReconciliationSignedStatusTest` (2 file test đã có sẵn, viết trước bởi BE, dùng `RefreshDatabase` + HTTP call thật, không phải static assertion) cho kết quả **FAIL**:

```
InventoryReconciliationIntegrityTest: 2 tests, 0 passed — "Failed asserting that null is identical to false/true"
InventoryReconciliationSignedStatusTest: 1 test, 0 passed — "Undefined array key \"expected_qty\""
```

Nguyên nhân: `OperationalReportController::inventoryReconciliation()` (`Modules/Warehouse/app/Http/Controllers/OperationalReportController.php:239`) và hàm dùng chung `buildInventoryBalance()` (dòng 377) trả về field `on_hand_qty`/`ledger_qty`/`variance`, nhưng contract mà test (và OpenAPI tag `Reconciliation` trong `docs/api-contract/WWHN-180-posting-openapi.json`) mong đợi là `current_on_hand_qty`/`expected_qty`/`delta_qty`/`balance_exists`/`mismatch_reason`. Đối chiếu `git log` xác nhận 2 test này được thêm ở commit riêng, không có commit nào sau đó đồng bộ lại controller — đây là **contract drift thật**, không phải test sai.

### 1.2 Sửa

`Modules/Warehouse/app/Http/Controllers/OperationalReportController.php:420-460` (hàm `buildInventoryBalance`):

- Bổ sung field `expected_qty` (= ledger sum), `current_on_hand_qty` (= balance.on_hand_qty), `delta_qty` (alias của `variance`), `balance_exists` (bool), `mismatch_reason` (`BALANCE_MISSING_FOR_LEDGER` | `LEDGER_MISSING_FOR_BALANCE` | `QUANTITY_MISMATCH`) — giữ nguyên field cũ (`on_hand_qty`, `ledger_qty`, `variance`, `reconciliation_status`) để không phá vỡ consumer khác (dashboard, Excel/PDF export dùng chung hàm này).
- Đổi so sánh biến thiên từ `abs($variance) < 0.000001` (float) sang `bccomp($variance, '0', 3)` (chuỗi thập phân, `bcsub` để tính) — nhất quán với cách `InventoryBalanceController::index()` (dòng 76-78, đã có sẵn trước đó) tính `reconciliation_status`, tránh sai số dấu phẩy động giữa 2 endpoint cùng khái niệm nhưng khác cách tính.

**Kết quả sau sửa**: `php artisan test --filter="InventoryReconciliationIntegrityTest|InventoryReconciliationSignedStatusTest"` → 3/3 PASS. Chạy thêm `ReportFlow06ContractTest`, `ReportFlow06IntegrationTest`, `Wwhn128EvidenceContractTest` (42 test, các test khác dùng chung `buildInventoryBalance`) → không regression.

### 1.3 Đối soát chủ động — gap thật, đã bổ sung

Trước khi sửa, cơ chế đối soát tồn tại nhưng **hoàn toàn passive**:

- `GET /api/v1/reports/inventory-reconciliation` (permission `report.view`) — tính on-the-fly mỗi lần gọi, không lưu kết quả.
- Bảng `reconciliation_incidents` (canonical trên Postgres) / `reconciliation_incident` (view read-only trên Postgres, bảng thật trên baseline không có migration `2026_08_10_000791` áp dụng) đã có schema đầy đủ (`incident_no`, `divergence_type`, `severity CHECK IN ('WARNING','ERROR','CRITICAL')`, `status CHECK IN ('OPEN','INVESTIGATING','RESOLVED','WAIVED')` — `Modules/Warehouse/database/migrations/2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints.php:185`) nhưng **không có bất kỳ nơi nào trong toàn bộ codebase INSERT vào bảng này** (xác nhận bằng grep toàn repo, chỉ có 2 chỗ đọc: `InventoryBalanceController.php:289`, `WmsContractController.php:202`).
- Hệ quả cụ thể: `WmsContractController::inventorySnapshots()` (health-check endpoint, hiện không có route active) tính `open_incidents = count(...)` sẽ **luôn bằng 0**, khiến health check vô nghĩa nếu được bật lại.
- Không có scheduler nào (`bootstrap/app.php` không có `->withSchedule()`, `routes/console.php` chỉ có lệnh `inspire` mặc định) — kể cả `audit:reconcile` (command retry audit outbox đã có sẵn trước đây) cũng chưa từng được lên lịch chạy tự động.
- Script Node độc lập `scripts/verify-inventory-reconciliation.mjs` tồn tại nhưng **đã xác nhận không được gọi trong bất kỳ workflow nào** ở `.github/workflows/` (11 workflow liên quan đã kiểm tra, không có workflow nào reference tên script này).

**Đã bổ sung**: command `app/Console/Commands/ReconcileInventoryBalances.php` (`inventory:reconcile`, có `--dry-run`):

- Đối soát toàn bộ dimension `(warehouse_id, product_id, stock_status)` giữa `inventory_balances` (projection) và `SUM(stock_movements.qty_delta)` (ledger nguồn sự thật), dùng `bcsub`/`bccomp` để tránh sai số thập phân.
- Với mỗi dimension lệch, nếu **chưa có incident OPEN/INVESTIGATING** cho cùng `(warehouse_id, product_id)` thì tạo mới (tránh spam trùng lặp mỗi lần chạy cron); nếu đã có thì bỏ qua (chờ người xử lý đóng incident cũ trước).
- Ghi kèm `AuditLogger::record(action: 'RECONCILIATION_INCIDENT_DETECTED', ...)` — mọi lần phát hiện lệch số liệu đều có audit trail độc lập với bảng incident.
- **Driver-aware ghi bảng**: trên Postgres insert vào `reconciliation_incidents` (bảng canonical thật theo migration `000760`/`000770`); trên baseline không có view compatibility (ví dụ SQLite dùng trong test) insert vào `reconciliation_incident` — vì đã xác nhận bằng cách đọc migration `2026_08_10_000791_retire_remaining_singular_legacy_tables.php:450-462` rằng trên Postgres, bảng số ít là **view read-only có trigger chặn ghi** (`RAISE EXCEPTION '...compatibility view is read-only...'`), nên ghi nhầm bảng sẽ vỡ trên production dù pass trên test.
- Không tự động resolve/schedule — đây là công cụ đối soát chủ động chạy qua CLI; việc lên lịch cron thuộc trách nhiệm DevOps (xem Open point BE2-OP-02).

**Test mới**: `tests/Feature/Wwhn219InventoryReconcileCommandTest.php` — 3 test: (1) tạo incident + audit khi có mismatch, (2) không tạo trùng khi đã có incident OPEN, (3) `--dry-run` không ghi gì. Cả 3 PASS.

## 2. Audit tăng cường

### 2.1 Gap thật đã sửa: `WarrantyController::tempOnly`

Route `POST /api/v1/warranty-cases/{id}/temp-only` (`Modules/Warehouse/routes/governance.php:38`, permission `warranty.manage`, **route active thật** — có `IdempotencyKeyGuard`, `lockForUpdate()`, optimistic version) cập nhật `WarrantyCase` (xoá liên kết `inventory_serial_id`/`product_id`, đổi `status` → `TEMP_ONLY`) và tạo `WarrantyEvent`, nhưng **không gọi `AuditLogger::record()`** — khác với nhánh warranty status change khác (`CurrentBaselineWarrantyController.php:289`, có audit `WARRANTY_STATUS_CHANGE`).

Đã bổ sung `AuditLogger::record(action: 'WARRANTY_TEMP_ONLY', ...)` tại `Modules/Warehouse/app/Http/Controllers/WarrantyController.php:130-138`, theo đúng pattern `action/objectType/objectId/actorId/requestId/beforeData/afterData` đã dùng nhất quán toàn dự án. `beforeData` chụp `status`/`inventory_serial_id`/`product_id` **trước** khi `update()` (không dùng `getOriginal()` sau update vì Eloquent sync lại original sau `update()`, sẽ cho before-data sai).

**Test mới**: `tests/Feature/Wwhn219WarrantyTempOnlyAuditTest.php` — xác nhận audit log được ghi đúng `action`, `object_type`, `actor_id`, `before_data.status`, `after_data.status`/`temporary_item_code`. PASS.

### 2.2 Rà soát toàn bộ điểm gọi `AuditLogger::record()` — ~90 điểm

Đếm lại toàn bộ codebase production (loại tests): **~90 lời gọi** `AuditLogger::record()` trên toàn dự án (BE1 note "~42 điểm" — chênh lệch do cách đếm gồm cả các action lặp lại cùng file khác dòng và code dead/trùng lặp song song). Phân loại theo route active/inactive (xác nhận qua đối chiếu route file, không suy đoán):

**Dead code có mutation nhưng KHÔNG audit (không route active, KHÔNG sửa để tránh mở rộng scope ngoài phạm vi task):**

| Controller | Route active? | Ghi chú |
|---|---|---|
| `SafeStockConfigController` (store/update/destroy) | Không | 0 route trong toàn bộ `Modules/Warehouse/routes/*.php` |
| `DocumentStatusTransitionController::toggle()` | Không | Cùng tình trạng |
| `StockRequestController::destroy()` | Không | Các action khác (CREATE/UPDATE/APPROVE...) đi qua `StockRequestService` đã có audit đầy đủ; chỉ riêng `destroy()` gọi thẳng trong controller — nhưng cả controller không có route active |
| `CanonicalMvpController` (storeBrand/storeWarrantyCase/storeComponentIssueDocument/...) | Chỉ các method GET có route; toàn bộ ~15 method mutation KHÔNG route | Cùng nhóm dead-code pattern BE1 đã note với `StockDocumentService` |

**Đối chiếu để loại false-positive** (đã đọc kỹ, xác nhận KHÔNG phải gap):

- `ImportController` → gọi qua `ImportService` (có audit tại `ImportService.php:129,141`) → OK.
- `SkuGovernanceController`/`CurrentBaselineSkuController` → qua `SkuAggregateService::update()` (audit tại dòng 467) → OK.
- `TransferController` → toàn bộ qua `TransferService` (5 action đều có audit) → OK.

### 2.3 Ghi chú kỹ thuật khác (không phải bug, cần disposition)

1. **`config/audit.php` khai báo 3 policy** (`outbox_required`/`fail_closed`/`best_effort`) nhưng `AuditLogger.php:80-85` chỉ code 2 nhánh thực tế (`fail_closed` vs mặc định coi như `outbox_required`) — `best_effort` không có nhánh riêng biệt. Cần QA/Tech Lead xác nhận đây có đúng ý đồ thiết kế (chỉ 2 policy thật) hay code thiếu.
2. **`ReconcileAuditOutbox` (`audit:reconcile`) chưa từng được lên lịch chạy tự động** — cùng vấn đề với `inventory:reconcile` mới thêm (mục 1.3). Các bản ghi lỗi vào `audit_outbox` sẽ nằm mãi ở đó nếu không có ai chạy lệnh thủ công.
3. **`StockMovement` model đã có append-only guard** (`booted()` chặn `updating`/`deleting`, `Modules/Warehouse/app/Models/StockMovement.php:52-55`) — xác nhận ledger nguồn sự thật được bảo vệ ở tầng Eloquent tương tự `AuditLog`, không phải gap như nghi ngờ ban đầu.

## 3. Ràng buộc dữ liệu (Data constraints)

### 3.1 Rủi ro lớn nhất: constraint chỉ chạy trên Postgres, không được CI kiểm chứng

- `phpunit.xml:27-28`: test suite dùng `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- **31 file migration** trong `Modules/Warehouse/database/migrations/` có `if (DB::getDriverName() !== 'pgsql') { return; }` ở đầu `up()` — toàn bộ CHECK constraint, trigger business-rule, một số unique index trong các file này **hoàn toàn không chạy khi test trên SQLite**, nghĩa là CI hiện tại (PHPUnit) **không thể phát hiện regression trên các ràng buộc này** (ví dụ ai đó vô tình xoá một CHECK constraint trong migration mới mà không có test nào catch được vì test chạy trên driver khác).
- Đây không phải "thiếu ràng buộc" — ràng buộc business rule khá đầy đủ và chi tiết (ví dụ: `chk_issue_requires_outbound_request`, `hn_guard_stock_line_sku_scope` trigger chặn SKU không ACTIVE, `ux_erd_warehouses_one_active` chỉ cho 1 kho ACTIVE, XOR constraint `physical_codes` chỉ gắn item HOẶC container). Vấn đề là **thiếu test coverage tương ứng trên chính driver production dùng**.
- Khuyến nghị (không thuộc phạm vi code BE2 tự sửa — cần quyết định CI/DevOps): thêm 1 workflow CI riêng chạy PHPUnit hoặc script kiểm chứng constraint trên Postgres thật (`erd-postgres-diagnostic.yml` đã tồn tại trong `.github/workflows/` — cần xác nhận nó có cover đúng các CHECK constraint này hay chỉ kiểm tra schema drift).

### 3.2 Đã verify và loại trừ nghi ngờ ban đầu (false alarm)

Khi rà soát, có nghi vấn CHECK constraint `inventory_balances` (dòng `chk_inventory_balance_non_negative`) target cột `quantity` trong khi bảng dùng `on_hand_qty`. Đã đọc trực tiếp và xác nhận: **không phải bug** — migration `2026_08_09_000420_harden_latest_mvp_contract.php:39-55` đã thêm cột `quantity` như alias/ERD-compat, đồng bộ giá trị `quantity = on_hand_qty`; CHECK constraint thật (`2026_08_10_000600_enforce_current_erd_business_contract.php:390`) kiểm tra **cả 4 cột cùng lúc** (`quantity >= 0 AND on_hand_qty >= 0 AND available_qty >= 0 AND reserved_qty >= 0`), không có mismatch.

### 3.3 Ràng buộc bị thiếu nhỏ (không critical, có thể tăng cường nếu được duyệt scope riêng)

| # | Bảng/cột | Thiếu | Mức độ |
|---|---|---|---|
| 1 | `idempotency_keys.status` | Comment ghi rõ 3 giá trị hợp lệ (`processing/done/failed`) nhưng không có CHECK enum ở DB | Thấp — validate ở code |
| 2 | `idempotency_keys.user_id` | Không FK tới `app_user.user_id` (string tự do, default `'0'`) | Thấp — cố ý cho phép user chưa xác định |
| 3 | `audit_log.action`/`object_type` | String tự do, không CHECK enum ở DB | Chấp nhận được — cố ý polymorphic, validate bằng `strtoupper()` ở `AuditLogger::payload()` |
| 4 | `SafeStockConfig.min_qty`/`max_qty` | Chỉ validate ở FormRequest (`gte:0`), không có CHECK DB cho `min_qty <= max_qty` | Thấp — controller không có route active (mục 2.3) |

### 3.4 Validation tầng Application

- **`app/Rules/` và `Modules/*/Rules/` hoàn toàn không tồn tại** — dự án không có custom `Illuminate\Contracts\Validation\Rule`/`ValidationRule` class nào. Toàn bộ validate dùng rule built-in Laravel (`required`, `numeric`, `gt:0`, `gte:0`, `uuid`, `exists:`, `Rule::in([...])`) khai báo trực tiếp trong 28 FormRequest (`Modules/Warehouse/app/Http/Requests/*.php`) hoặc `$request->validate([...])` inline trong controller.
- Đã đọc trực tiếp một số ví dụ: `StoreStockRequestRequest.php:35-36` (`qty_planned` → `gt:0`), `StoreSafeStockConfigRequest.php:19-22` (`min_qty`/`max_qty` → `gte:0`, `effective_to` → `after_or_equal:effective_from`), `StoreItemInstanceRequest.php:31-47` (custom `withValidator()` logic cho serial-tracked SKU).
- Đây không phải bug — validate hiện có khá đầy đủ cho các field cơ bản — nhưng logic tương tự (`gte:0` cho quantity) lặp lại rải rác thay vì tập trung vào 1 Rule class dùng chung. Không sửa trong phiên này vì đây là refactor thuần (không có bug cụ thể để fix), nằm ngoài phạm vi "không tự ý mở rộng scope" của task — ghi nhận làm khuyến nghị cải tiến, không phải blocker.

## Test evidence

| Hạng mục | File | Kết quả |
|---|---|---|
| Đối soát API contract (đã sửa bug) | `InventoryReconciliationIntegrityTest.php`, `InventoryReconciliationSignedStatusTest.php` | FAIL → PASS (3/3) sau khi sửa `OperationalReportController` |
| Đối soát chủ động (mới) | `Wwhn219InventoryReconcileCommandTest.php` | 3/3 PASS |
| Audit warranty temp-only (mới) | `Wwhn219WarrantyTempOnlyAuditTest.php` | 1/1 PASS |
| Regression toàn bộ báo cáo/dashboard | `ReportFlow06ContractTest.php`, `ReportFlow06IntegrationTest.php`, `Wwhn128EvidenceContractTest.php` | 42/42 PASS, không regression |
| Regression warranty idempotency | `WarrantyIdempotencyKeyContractTest.php` | 5/5 PASS, không regression |
| Regression audit hiện có | `AuthRbacAuditHardeningTest.php`, `SecurityRbacAuditNegativeTest.php`, `Wwhn172AuditFailClosedTest.php`, `FoundationAuthRbacAuditTest.php` | 28/28 PASS |
| **Toàn bộ suite** | `php artisan test` | **299 tests, 283 passed, 16 skipped, 0 failed** (tăng từ baseline 295 tests do 4 test mới) |

Tất cả các con số PASS/FAIL trên được lấy từ chạy `php artisan test` thật trong phiên rà soát này (không suy đoán từ đọc code).

## File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `Modules/Warehouse/app/Http/Controllers/OperationalReportController.php` | Sửa | Bổ sung field contract đối soát còn thiếu, đổi so sánh biến thiên sang `bccomp` |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | Sửa | Bổ sung `AuditLogger::record()` cho `tempOnly()` |
| `app/Console/Commands/ReconcileInventoryBalances.php` | Mới | Command `inventory:reconcile` — đối soát chủ động, ghi incident + audit |
| `tests/Feature/Wwhn219InventoryReconcileCommandTest.php` | Mới | 3 test cho command mới |
| `tests/Feature/Wwhn219WarrantyTempOnlyAuditTest.php` | Mới | 1 test audit warranty temp-only |
| `storage/api-docs/api-docs.json`, `.yaml` | Tự động cập nhật | Regenerate bởi swagger annotation scan khi chạy test; đồng bộ lại với annotation hiện tại trong code (không phải thay đổi thủ công) |

## Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE2-OP-01 | Xác nhận với BA/QA: `inventory:reconcile` mới thêm có cần đưa vào seed/evidence chính thức của `HN-SEC-001` hay chỉ là công cụ nội bộ | BE2 + BA/QA | Mở |
| BE2-OP-02 | Lên lịch chạy tự động (cron) cho `inventory:reconcile` và `audit:reconcile` — hiện cả 2 đều chỉ chạy thủ công qua CLI, không có `->withSchedule()` nào trong `bootstrap/app.php` | DevOps | Mở |
| BE2-OP-03 | Xác nhận `scripts/verify-inventory-reconciliation.mjs` có nên được thêm vào một GitHub workflow hay giữ nguyên chạy thủ công | DevOps | Mở |
| BE2-OP-04 | Constraint CHECK/trigger Postgres-only (31 migration) không được CI (SQLite) kiểm chứng — cần xác nhận `erd-postgres-diagnostic.yml` có cover đủ hay cần bổ sung | DevOps/QA | Mở |
| BE2-OP-05 | Disposition dead code: `CanonicalMvpController` (mutation methods), `SafeStockConfigController`, `DocumentStatusTransitionController`, `StockRequestController::destroy()` — giữ, xoá hay archive | BE2 + Tech Lead | Mở, liên quan `BE1-OP-02` (cùng loại vấn đề BE1 đã nêu với `StockDocumentService`) |
| BE2-OP-06 | `config/audit.php` khai báo policy `best_effort` nhưng `AuditLogger.php` không có nhánh riêng — xác nhận có đúng thiết kế | BE2 + Tech Lead | Mở |

## Output đầu ra

Data tăng cường: đã phát hiện và sửa 1 bug thật (đối soát API trả sai contract, có test FAIL/PASS làm bằng chứng), bổ sung 1 audit gap thật (`WarrantyController::tempOnly`), và bổ sung năng lực đối soát chủ động (command `inventory:reconcile` ghi vết `reconciliation_incident`/`reconciliation_incidents` + audit log) mà trước đó hoàn toàn không tồn tại. Ràng buộc dữ liệu ở tầng DB đã khá đầy đủ từ trước (không phải xây mới), rủi ro chính là thiếu test coverage cho constraint Postgres-only trên CI SQLite — đã ghi nhận thành Open point cho DevOps/QA quyết định thay vì tự ý thay đổi cấu hình CI ngoài phạm vi BE2. Không phát hiện thêm lỗ hổng audit nào trên các route đang active khác ngoài `tempOnly`.

## Definition of Done

- Code, review, unit/integration test: **đã có** — 4 test mới, tất cả PASS; toàn bộ suite 299 test PASS, không regression.
- Contract/API: `inventory-reconciliation` response bổ sung field mới (backward-compatible, giữ field cũ) — cần FE1 xác nhận không có tác động nếu FE đang parse response này.
- Migration/seed: không có migration mới (chỉ dùng bảng đã tồn tại sẵn `reconciliation_incident(s)`).
- FE/QA nhận đủ đầu ra: **chưa** — cần disposition các Open point BE2-OP-01 đến BE2-OP-06 trước khi đóng chính thức, đặc biệt BE2-OP-02 (lên lịch cron) vì nếu không có cron, tính năng đối soát chủ động mới thêm sẽ không tự vận hành trong production.
