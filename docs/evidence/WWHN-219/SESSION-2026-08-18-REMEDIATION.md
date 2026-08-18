# WWHN-219 — Khắc phục P1/P2 phiên 2026-08-18

Đối chiếu với đánh giá mới nhất trên Jira (Wuangg, 72/100, "8 hours ago" tại thời điểm bắt đầu phiên). Base SHA trước phiên: `92a9a41c8eddd64df5856cbaa2cb583aee39d036` (branch `m1`). **Toàn bộ thay đổi dưới đây hiện chưa commit** — còn trong working tree, chờ BE owner review + commit + đẩy CI thật.

## Đã sửa tận gốc, có test/evidence xác minh

### DEV219-P2-01R — `$created` đếm sai khi dry-run
- File: [`app/Console/Commands/ReconcileInventoryBalances.php`](../../../app/Console/Commands/ReconcileInventoryBalances.php)
- Bug: dòng 91 cũ `$created++` nằm **ngoài** `if (!$dryRun)` nên dry-run vẫn tăng biến "incident mới" dù không ghi gì.
- Fix: tách `$newIncidentCandidates` (đếm ứng viên, luôn tăng) khỏi `$created` (chỉ tăng sau `recordIncident()` thật sự chạy). Output dry-run đổi thành "N incident sẽ được tạo nếu chạy thật" thay vì "N incident mới".

### DEV219-P2-02R — Test không assert CLI output
- File: [`tests/Feature/Wwhn219InventoryReconcileCommandTest.php`](../../../tests/Feature/Wwhn219InventoryReconcileCommandTest.php)
- Thêm `expectsOutputToContain(...)` cho cả nhánh ghi thật và dry-run, cộng thêm assert `AuditLog` không bị ghi khi dry-run.
- Kết quả: `php vendor/bin/phpunit tests/Feature/Wwhn219InventoryReconcileCommandTest.php` → **3/3 PASS, 10 assertions** (sqlite).

### DEV219-P1-06R — `best_effort` audit policy không có nhánh riêng
- File: [`Modules/AuditLog/app/Services/AuditLogger.php`](../../../Modules/AuditLog/app/Services/AuditLogger.php)
- Bug: khi cả `audit_log` lẫn `audit_outbox` đều ghi lỗi, code luôn `throw` bất kể policy — `best_effort` hành xử giống hệt `outbox_required`, trái với comment mô tả "không làm hỏng nghiệp vụ".
- Fix: thêm nhánh `if ($policy !== 'best_effort') throw ...` — chỉ `best_effort` mới `return` êm khi mất toàn bộ traceability (kèm `Log::critical`).
- Quyết định ghi lại tại [`docs/decision-records/ADR-AUDIT-001-audit-write-policy.md`](../../decision-records/ADR-AUDIT-001-audit-write-policy.md) *(lưu ý: `/docs/` bị gitignore toàn repo, phải `git add -f` file này khi commit)*.
- Test mới: [`tests/Unit/AuditLoggerWritePolicyTest.php`](../../../tests/Unit/AuditLoggerWritePolicyTest.php) — 5 test, ép cả audit_log lẫn audit_outbox cùng lỗi bằng Eloquent `creating` event, verify đúng throw/không-throw cho cả 3 policy.
- Kết quả: **5/5 PASS**; test cũ `Wwhn172AuditFailClosedTest` (fail_closed qua HTTP) vẫn **PASS**, không hồi quy.

### DEV219-P1-04R — `audit:reconcile` không có lịch + không bằng chứng hạ tầng
- File: [`routes/console.php`](../../../routes/console.php) — thêm `Schedule::command('audit:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->runInBackground()`.
- File: [`docker-compose.yml`](../../../docker-compose.yml) — thêm service `scheduler` chạy `php artisan schedule:work` (bằng chứng hạ tầng thật, không chỉ định nghĩa Schedule suông).
- **Bằng chứng thực thi tự động thật** (không phải giả định): dựng container thật bằng `docker compose up -d --build scheduler`, đợi tới đúng chu kỳ, bắt được log:
  ```
  2026-08-18 08:25:00 Running ['artisan' audit:reconcile] in background  7.41ms DONE
  ```
  Lưu tại [`docs/evidence/WWHN-219/DEV219-P1-04R/scheduler-execution-log.txt`](DEV219-P1-04R/scheduler-execution-log.txt) và [`schedule-list.txt`](DEV219-P1-04R/schedule-list.txt) *(cũng gitignore, cần `-f` khi add)*.

## P1-03R — sửa được một phần, phát hiện thêm 1 bug chặn CI

### Bug mới phát hiện: `migrate:fresh` fail trên PostgreSQL sạch 100%
- Tái hiện được **độc lập với mọi thay đổi của tôi** (revert hết vẫn fail): migration `2026_08_10_0007675_prepare_defect_catalog_canonical_key.php` giả định bảng `defect_catalog` đã có cột `id`, nhưng migration tạo bảng (`2026_01_01_000044`) chỉ có `defect_id, defect_code, defect_name, severity, status, timestamps`. `2026_08_10_000768_sync_defect_catalog_runtime_to_erd.php` cũng cần `description/created_by/updated_by/version` không tồn tại.
- Đây rất có thể là lý do thật của **DEV219-P1-07R** ("Final Gate ... không chấp nhận workflow chỉ fail trước test") — nếu CI từng chạy trên DB đã có sẵn state cũ (không hoàn toàn `--fresh`), bug này không lộ ra; agent kiểm B1/B3 độc lập của tôi cũng tự tái hiện đúng bug này trên DB test riêng của nó.
- **Đã fix**: migration mới [`2026_08_10_0007674_add_missing_defect_catalog_runtime_columns.php`](../../../Modules/Warehouse/database/migrations/2026_08_10_0007674_add_missing_defect_catalog_runtime_columns.php) bù đúng 5 cột thiếu, chạy trước `0007675` theo thứ tự file.
- Xác minh: `DROP DATABASE` + `CREATE DATABASE` + `migrate:fresh --seed --force` trên DB hoàn toàn mới → **chạy hết, không lỗi** (đã lặp lại 2 lần để loại trừ flaky).

### Đã sửa: 9 chỗ tên bảng/cột lệch giữa contract và schema PG thật
File [`docs/contracts/HoaNam_Current_Baseline_Contract.json`](../../contracts/HoaNam_Current_Baseline_Contract.json) mục `reference_db_relationships` còn dùng tên bảng số ít (view, không có FK constraint riêng) thay vì tên vật lý số nhiều — cùng loại lỗi mà `reversal_documents` đã từng được sửa trước đây. Đã đối chiếu từng cái với `pg_constraint` thật (0 orphan) rồi sửa:
- `scan_event` → `scan_events` (physical_code_id, và 3 chỗ làm target cho scan_event_id → cột thật là `id`)
- `warranty_case` → `warranty_cases` (2 chỗ nguồn, 2 chỗ đích → cột thật là `id`)
- `reversal_document`/`reversal_line` → `reversal_documents`/`reversal_lines` (3 chỗ)
- `sku` → `skus` (3 chỗ, cột thật `id`)
- `pv_product_profile` → `pv_product_profiles` (1 chỗ, cột thật `id`)

### Đã sửa: `defect_catalogs` thiếu cột/index/constraint thật so với Data Dictionary
Migration mới [`2026_08_18_000100_align_defect_catalogs_with_erd_dictionary.php`](../../../Modules/Warehouse/database/migrations/2026_08_18_000100_align_defect_catalogs_with_erd_dictionary.php): thêm `created_by`/`updated_by` (hoàn toàn chưa tồn tại), siết `created_at`/`updated_at` thành `NOT NULL DEFAULT now()` (đã xác nhận 0 hàng NULL trước khi siết), thêm `CHECK status IN ('ACTIVE','INACTIVE')`, thêm `IDX(status)`, `IDX(defect_name)`.

### Thử nhưng phải revert: đổi tên `defect_catalog` → `defect_catalogs` trong ERD doc
Đã thử đổi heading + tên field (`id`→`defect_id`) trong `docs/HoaNam_ERD_Moi_Nhat.md` để test parity xanh hẳn (đúng pattern đã dùng cho `reversal_documents`). **Phải revert** vì phát hiện: `docs/HoaNam_ERD_Moi_Nhat.md` không chỉ là tài liệu — nó bị **7 migration khác đọc trực tiếp lúc chạy** qua `App\Support\HoaNamErdSchema::parse()` (`2026_08_10_000750/000760/000770/0007695/000772/000783`, `2026_08_17_000100_materialize_erd_columns_on_sqlite`) để quyết định backfill cột nào trên bảng nào. Đổi tên ngay lập tức làm `2026_08_10_000760` ngừng tạo cột `id` mà `0007675` cần → tái gãy migration. Việc đổi tên `defect_catalog` theo ERD vì vậy **phải làm cùng lúc với audit cả 7 migration đó**, đúng như `SUITE-TRIAGE.md` đã khuyến nghị từ trước ("C — ERD-versus-implementation reconciliation. Scope as its own ticket, not WWHN-219") — không an toàn để làm rời trong đêm nay.

### Kết quả đo được (không phải suy đoán)
Chạy `migrate:fresh --seed --force` + `php vendor/bin/phpunit --testsuite Feature,Unit` trên PostgreSQL 16 sạch (`wwhn219_check`, không phải DB dev đã cũ):

**541/553 pass, 6 fail** (trước đây theo `SUITE-TRIAGE.md` 2026-08-16 là 177/455 đỏ — con số đó đã stale, phần lớn đã được các phiên trước đóng).

6 fail còn lại:
| # | Test | Nguyên nhân |
|---|---|---|
| 1 | `DocumentationBaselineContractTest::test_reference_database_foreign_keys_match_documented_relationships` | Đã đào tới cùng: FK thật `fk_erd_reversal_lines_original_movement_id`/`fk_erd_reversal_lines_posted_movement_id` trỏ tới **`inventory_movements(id)`**, không phải `stock_movements(id)` như contract khai. Cả hai đều là bảng vật lý thật (`relkind='r'`), KHÔNG phải cặp view/bảng-vật-lý như 9 chỗ đã sửa ở trên — đây là câu hỏi nghiệp vụ "bảng nào mới là ledger canonical", không phải lỗi đặt tên. `inventory_movements` cũng được liệt trong `append_only_storage` của chính contract này. **Không tự sửa** — cần Tech Lead xác nhận `stock_movements` hay `inventory_movements` là canonical trước khi sửa contract hoặc FK. |
| 2-4 | `ErdPhysicalSchemaParityTest` ×3 | `defect_catalog` vẫn là view (xem mục "phải revert" ở trên) — cố ý chưa đóng |
| 5 | `ErdPhysicalSchemaParityTest::test_documented_primary_unique_and_foreign_keys_are_enforced` | Message báo `user_roles(user_id)` nhưng **debug độc lập xác nhận user_roles PK đã đúng** (composite `user_id,role_id`, pass khi chạy riêng lẻ) — nghi là artifact của cách chạy test kết hợp nhiều file, KHÔNG phải bug schema thật. Cần Tech Lead xác nhận lại trên CI thật, không nên dùng message này để quy trách nhiệm cho user_roles. |
| 6 | 3 test Flow01/Flow02/Flow05 (đã giảm còn 1 khi chạy cô lập) | Không liên quan gì tới các bảng tôi đụng vào (`inbound_lines`/`ux_erd_doc_inbound_lines_f891eba6e3`); Flow01/Flow05 **pass khi chạy tách riêng** (flaky/order-dependent có sẵn), Flow02 tái hiện được nhưng thuộc luồng inbound hoàn toàn khác — không thuộc phạm vi WWHN-219 phiên này |

**Việc còn lại cho P1-03R xanh 100%:** (1) Tech Lead chốt `stock_movements` hay `inventory_movements` là bảng ledger canonical cho reversal_lines rồi sửa 1 dòng contract tương ứng; (2) quyết định dứt điểm hướng xử lý `defect_catalog` (đổi ERD + audit 7 migration phụ thuộc, HOẶC chấp nhận giữ nguyên và loại nó khỏi gate với lý do ghi rõ).

## B1/B3 (agent kiểm độc lập, không phải tôi)
- **B3** (`chk_stock_movement_positive_quantity`, `chk_stock_document_line_integer_positive`): chạy 72 test liên quan → **PASS 100%**, fixture đã đúng từ trước, không cần sửa gì.
- **B1** (`chk_erd_physical_codes_2`): constraint hoạt động đúng trên PG, nhưng **0 test nào cover nó** — không phải bug, là lỗ hổng test coverage, cần BA/Tech quyết định có bổ sung test hay không.
- Agent này **độc lập tái hiện đúng bug `migrate:fresh` fail** ở trên trên DB test riêng của nó — càng củng cố đây là bug thật, không phải do môi trường của tôi.
