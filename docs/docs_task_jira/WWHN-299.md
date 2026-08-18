# WWHN-299 — [BE] Thực hiện/xác minh lần nạp dữ liệu cuối

## Kiểm soát

- Task cha: `WWHN-80` — `[GOLIVE] Cutover, migration cuối, deployment và kiểm soát rollback`.
- Parent Temp Key: `HN-DEVOPS-005`; Epic Temp Key: `HN-EPIC-10`; Dependency: `HN-DEVOPS-005-ST01`.
- Output đầu ra: **Final data evidence**.
- Repo/branch: `WMS_Warehouse_Hoa_Nam_BE` / `m1` (không tạo nhánh riêng).
- Ngày: 2026-08-18.
- Status: `IMPLEMENTED / EVIDENCE READY — PENDING INDEPENDENT SIGN-OFF`. BE không tự ghi `Done`.

## 1. Phát hiện chính

Trước task này, luồng "lần nạp dữ liệu cuối" **chưa tồn tại trong code**: `grep -ri "cutover|final.data|golive"` trên `app/`, `Modules/`, `routes/`, `scripts/`, `.github/` chỉ trả về file SRS. Hai bảng ERD `migration_runs` (58) và `migration_errors` (59) đã có schema, có CHECK constraint và trigger `trg_erd_migration_commit_evidence` trên PostgreSQL, nhưng **không có bất kỳ đoạn code nào ghi hoặc đọc chúng**. `movement_type = 'OPENING'` cũng đã nằm trong CHECK constraint nhưng chưa từng được ghi.

Nghĩa là `REQ-SRS-021` / `AC-SRS-021` / `AC-GWT-MIG-001` / `NFR-SRS-017` chưa có hiện thực — đây là khoảng trống thật, không phải suy đoán.

## 2. Đã bổ sung

| File | Vai trò |
|---|---|
| `app/Support/Migration/ImportPackage.php` | Đọc Import Package v1.2 (manifest + CSV theo dataset §13.12), sha256 từng file + checksum tổng hợp, kiểm tra header. |
| `app/Support/Migration/MigrationErrorBag.php` | Sinh error row đúng error-file field spec §13.12; mask PII (`phone`, `address`, `serial_number`, `hardware_uid`, `owner_confirmation`). |
| `app/Support/Migration/MigrationReconciliationException.php` | Ném trong transaction COMMIT để rollback toàn bộ khi reconciliation FAIL. |
| `app/Support/Migration/FinalDataLoadService.php` | Vòng đời DRY_RUN → COMMIT → reconcile → ROLLBACK, ghi `migration_runs`/`migration_errors`, ghi opening balance qua movement `OPENING`. |
| `app/Console/Commands/MigrationFinalDataLoad.php` | Console job `migration:final-load` (không có endpoint, đúng `DEC-MIG-API-001`). |
| `Modules/Warehouse/database/migrations/2026_08_18_190000_add_migration_run_trace_to_erd_identity_tables.php` | Thêm `migration_run_id` cho `import_batches`/`item_instances` theo ERD bảng 19/20 (trước đó chỉ `containers` có). |
| `tests/Feature/MigrationFinalDataLoadTest.php` | 11 test phủ toàn bộ AC-SRS-021. |
| `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` | Runbook cutover. |
| `docs/migration/import-package-v1.2-template/` | Template package chạy được. |
| `docs/evidence/WWHN-299/` | Evidence pack + 3 artifact JSON của lần diễn tập. |

## 3. Quyết định kỹ thuật cần ghi nhận

**Rollback dùng REVERSAL append-only, không xoá movement.** SRS §13.12 yêu cầu "rollback phải chứng minh không còn subset master/opening/movement". `stock_movements` là append-only theo ERD và `DEC-REV-001` (model chặn `update`/`delete`). Vì vậy rollback được hiện thực là:

- không tồn tại "subset" vì COMMIT nằm trọn trong một transaction — hoặc toàn bộ hoặc không có gì;
- hiệu ứng tồn kho của run được đưa về 0 bằng movement `REVERSAL` đối ứng, chứng minh bằng `residual_opening_qty = 0`;
- master do run tạo ra được đóng (`containers → CLOSED`, `item_instances → ADJUSTED_OUT`) và trace bằng `migration_run_id`, thay vì xoá cứng.

Điểm này cần **Tech Lead/Data Owner xác nhận một lần** là cách diễn giải đúng của yêu cầu "no subset remains".

## 4. Bằng chứng

| Hạng mục | Kết quả |
|---|---|
| `tests/Feature/MigrationFinalDataLoadTest.php` trên sqlite | 11/11 PASS, 55 assertions |
| Cùng test trên PostgreSQL (`phpunit.pgsql.xml`) | 11/11 PASS, 55 assertions |
| Toàn bộ suite sqlite | 659 tests, 637 passed, 22 skipped, 0 failed |
| Diễn tập dry-run/commit/rollback | exit `0/0/0`; `docs/evidence/WWHN-299/01-dry-run.json`, `02-commit.json`, `03-rollback.json` |

## 5. Ảnh hưởng contract

Không có thay đổi `/api/v1`, OpenAPI, Postman hay error code — FE1 không phải adapt. Thay đổi schema duy nhất là cột trace `migration_run_id` (nullable, FK `set null`, có `down()`).

## 6. Blocker / Open point (cần owner, không tự đóng)

| # | Nội dung | Owner |
|---|---|---|
| 1 | Import Package v1.2 production thật + checksum đã duyệt (attachment `WWHN-333`) chưa được cung cấp cho nhánh này. | BA / Data Owner |
| 2 | Ký duyệt opening balance của QL kho trên dữ liệu production (`--approved-by`). | Data Owner |
| 3 | Xác nhận diễn giải rollback ở §3. | Tech Lead |
| 4 | Thực thi cutover trong window và gắn evidence vào `HN-DEVOPS-005`. | DevOps (`WWHN-298`) |
| 5 | QA verify dry-run/commit/rollback trên UAT. | QA1 |
