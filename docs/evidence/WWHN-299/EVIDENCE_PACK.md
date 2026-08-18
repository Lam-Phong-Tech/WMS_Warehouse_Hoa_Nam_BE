# WWHN-299 — Final data evidence

- Jira: `WWHN-299` — `[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`
- Parent: `WWHN-80` — `[GOLIVE] Cutover, migration cuối, deployment và kiểm soát rollback`
- Traceability: Parent Temp Key `HN-DEVOPS-005`; Epic `HN-EPIC-10`; Dependency `HN-DEVOPS-005-ST01`
- Output: **Final data evidence**
- Branch: `m1` (backend). Không tạo nhánh riêng.
- Ngày: 2026-08-18

## 1. Baseline đã đối chiếu

- `docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-021`, `AC-SRS-021`, `REQ-SRS-034`, `NFR-SRS-017`, `AC-GWT-MIG-001`, §13.12, `DEC-MIG-API-001`
- `docs/HoaNam_ERD_Moi_Nhat.md` — bảng 58 `migration_runs`, 59 `migration_errors`, 19 `import_batches`, 20 `item_instances`, 22 `containers`, `stock_movements.movement_type = OPENING`
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — không có màn hình migration (đúng với operational-only)
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — console job, transaction boundary, audit

## 2. Khoảng trống đã phát hiện và đã đóng

| # | Khoảng trống trước WWHN-299 | Xử lý |
|---|---|---|
| 1 | ERD có `migration_runs`/`migration_errors` nhưng **không có code nào ghi/đọc**; toàn bộ REQ-SRS-021 chưa có hiện thực. | Thêm job `migration:final-load` + `App\Support\Migration\FinalDataLoadService`. |
| 2 | Không có reader/checksum cho Import Package v1.2 → không thể chặn "file đổi sau dry-run". | `App\Support\Migration\ImportPackage` với sha256 theo từng file + checksum tổng hợp. |
| 3 | `movement_type = OPENING` tồn tại trong CHECK constraint nhưng **chưa từng được ghi** → tồn đầu không có đường ghi hợp lệ. | Opening balance ghi qua `OPENING` movement + cập nhật `inventory_balances` trong cùng transaction. |
| 4 | ERD bảng 19/20 khai báo `migration_run_id` nhưng schema **thiếu cột** ở `import_batches` và `item_instances` → không trace được nguồn migration (`REQ-SRS-034`) và rollback không xác định được tập bản ghi của run. | Migration `2026_08_18_190000_add_migration_run_trace_to_erd_identity_tables.php`. |
| 5 | Không có error-file field spec §13.12 dưới dạng chạy được; PII có nguy cơ lưu raw. | `MigrationErrorBag` sinh `dataset/row_no/field/error_code/severity/message/raw_value_masked/suggested_action`, mask PII. |
| 6 | Không có runbook/template package cho cutover. | `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` + `docs/migration/import-package-v1.2-template/`. |

## 3. AC-SRS-021 mapping (chạy được, không phải tuyên bố)

| AC | Cơ chế | Test |
|---|---|---|
| Dry-run không đổi dữ liệu | `dryRun()` chỉ ghi `migration_runs`/`migration_errors` | `test_dry_run_passes_and_writes_no_business_data` |
| Commit chỉ khi checksum/reconciliation PASS | Gate `DRY_RUN_NOT_PASSED` + `CHECKSUM_CHANGED`; reconciliation FAIL ném exception trong transaction | `test_commit_is_blocked_without_passed_dry_run`, `test_changed_package_after_dry_run_blocks_commit` |
| Failed reconciliation chặn cutover | Exit code 1, run `FAILED`, `reconciliation_status = FAIL`, `RECON_MISMATCH` error row | `test_serial_tracked_quantity_must_match_item_count` |
| Rerun idempotent theo migration_run_id | Replay báo cáo đã lưu; `business_key` khoá theo `run_id` | `test_commit_rerun_with_same_run_code_is_idempotent` |
| Sign-off của Data Owner | `--approved-by` bắt buộc, lưu `approved_by`/`approved_at` | `test_commit_is_blocked_without_approver` |
| Rollback không để lại tồn dư | REVERSAL append-only, `residual_opening_qty = 0`, master `CLOSED`/`ADJUSTED_OUT` | `test_rollback_leaves_no_residual_opening_stock` |
| Không tạo dữ liệu sai / không lộ secret | Operator phải là app_user ACTIVE; lỗi hệ thống trả error code, không stack trace; PII mask | `test_unknown_operator_is_rejected_before_any_run_is_created`, `test_dry_run_reports_row_level_errors_with_masked_pii` |

## 4. Kết quả chạy thực tế

**Test tự động** — `tests/Feature/MigrationFinalDataLoadTest.php`

| Driver | Kết quả |
|---|---|
| sqlite (`phpunit.xml`) | 11 tests / 11 passed / 55 assertions |
| PostgreSQL (`phpunit.pgsql.xml`) | 11 tests / 11 passed / 55 assertions |
| Toàn bộ suite sqlite (regression) | 659 tests / 637 passed / 22 skipped / 0 failed |

PostgreSQL quan trọng vì đó là nơi trigger `trg_erd_migration_commit_evidence` (COMMIT bắt buộc reconciliation `PASS` + có checksum) và các CHECK constraint của ERD thực sự chạy.

**Diễn tập cutover thực tế** (dry-run → commit → rollback trên DB sạch, dùng template package):

| Artifact | Kết quả |
|---|---|
| `01-dry-run.json` | `PASSED`, reconciliation `PASS`, total 6 / valid 6 / invalid 0, `source_checksum = 718247b3…50a1` |
| `02-commit.json` | `COMMITTED`, reconciliation `PASS` (`SOURCE_QTY_EQUALS_LEDGER`, `BALANCE_EQUALS_LEDGER`, `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT`) |
| `03-rollback.json` | `ROLLED_BACK`, `residual_opening_qty = 0`, 1 REVERSAL, 1 container `CLOSED`, 1 item `ADJUSTED_OUT` |

Exit code lần lượt `0/0/0`.

## 5. Ảnh hưởng contract / FE

- **Không có** thay đổi endpoint, payload, enum hay error code của `/api/v1` — đúng `DEC-MIG-API-001` (không có public/admin migration endpoint trong MVP).
- OpenAPI/Postman **không đổi** ⇒ FE1 không phải adapt gì cho WWHN-299.
- Thay đổi schema duy nhất là cột trace `migration_run_id` (nullable, FK `set null`) trên `import_batches`/`item_instances` — additive, không phá dữ liệu cũ, `down()` có sẵn.

## 6. Gate còn lại (không tự ký)

| Gate | Owner |
|---|---|
| Import Package v1.2 production thật (attachment WWHN-333) + checksum đã duyệt | BA / Data Owner |
| Ký duyệt opening balance của QL kho trên dữ liệu production | Data Owner (`--approved-by`) |
| Thực thi cutover trong window đã duyệt, gắn evidence vào `HN-DEVOPS-005` | DevOps (`WWHN-298`) |
| QA verify dry-run/commit/rollback trên môi trường UAT | QA1 |

Package này chứng minh **năng lực và gate của job**; nó không tuyên bố đã nạp dữ liệu production.
