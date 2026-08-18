# WWHN-299 — Final data evidence

- Jira: `WWHN-299` — `[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`
- Parent: `WWHN-80` — `[GOLIVE] Cutover, migration cuối, deployment và kiểm soát rollback`
- Traceability: Parent Temp Key `HN-DEVOPS-005`; Epic `HN-EPIC-10`; Dependency `HN-DEVOPS-005-ST01`
- Output: **Final data evidence**
- Branch: `m1` (backend). Không tạo nhánh riêng. Pin nguồn: `WWHN-299_SOURCE_PIN.json`.
- Ngày: 2026-08-18 (bổ sung sau đánh giá 60/100).

> Gói này chứng minh **năng lực và gate của job**; nó **không** tuyên bố đã nạp dữ
> liệu production, và **không** tuyên bố QA1 PASS.

## 0. Đọc theo thứ tự nào

| File | Nội dung |
|---|---|
| `WWHN-299_DEFECT_FIX_REGISTER.md` | Từng mục P1/P2 của đánh giá 60/100: sai ở đâu, sửa thế nào, test nào chứng minh. |
| `WWHN-299_SOURCE_PIN.json` | Pin nguồn chính thức (DEV299-P1-04) — lý do các link evidence trước đó 404. |
| `SIGNOFF_COMMENTS.md` | Comment xác nhận soạn sẵn theo từng vai trò, mỗi vai một lần. |
| `01-dry-run.json` / `02-commit.json` / `03-rollback.json` / `MANIFEST.json` | Báo cáo JSON nguyên văn của job trong lần diễn tập, kèm digest. |
| `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` | Runbook vận hành cutover. |

## 1. Baseline đã đối chiếu

- `docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-021`, `AC-SRS-021`, `REQ-SRS-034`, `NFR-SRS-017`, §3 (ma trận permission), §13.12, §13.13, `DEC-MIG-API-001`
- `docs/HoaNam_ERD_Moi_Nhat.md` — bảng 7 `audit_logs`, 19 `import_batches`, 20 `item_instances`, 22 `containers`, 58 `migration_runs`, 59 `migration_errors`, `stock_movements.movement_type = OPENING/REVERSAL`
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — không có màn hình migration (đúng với operational-only)
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — console job, transaction boundary, audit

## 2. Khoảng trống đã đóng

### 2.1 Vòng đầu (dựng luồng)

| # | Khoảng trống | Xử lý |
|---|---|---|
| 1 | ERD có `migration_runs`/`migration_errors` nhưng **không có code nào ghi/đọc**. | Job `migration:final-load` + `FinalDataLoadService`. |
| 2 | Không có reader/checksum cho Import Package v1.2. | `ImportPackage` với sha256 theo từng file. |
| 3 | `movement_type = OPENING` có trong CHECK constraint nhưng **chưa từng được ghi**. | Opening balance ghi qua `OPENING` movement + cập nhật `inventory_balances` trong cùng transaction. |
| 4 | ERD bảng 19/20 khai `migration_run_id` nhưng schema thiếu cột. | Migration `2026_08_18_190000_add_migration_run_trace_to_erd_identity_tables.php`. |
| 5 | Không có error-file spec §13.12 dạng chạy được. | `MigrationErrorBag` + mask PII. |
| 6 | Không có runbook/template package. | `docs/migration/`. |

### 2.2 Vòng hai (đóng defect của đánh giá 60/100)

| ID | Khoảng trống | Xử lý |
|---|---|---|
| DEV299-P1-01 | Rollback không đảo được master: bản ghi bị ghi đè không có đường về, bản ghi mới ở lại vĩnh viễn. | `MasterStateJournal` — snapshot before/after vào `audit_log`, fail-closed; rollback khôi phục `UPDATE`, xoá `CREATE`, disposition khi ledger giữ khoá ngoại. |
| DEV299-P1-02 | Bất kỳ user ACTIVE nào cũng làm approver được, và được tự duyệt. | `CutoverAuthority` + `config/final_data_load.php` — permission Data Owner, separation of duty, bắt buộc `--approval-ref`. |
| DEV299-P1-03 | Manifest nằm ngoài checksum ⇒ đổi identity nguồn không bị phát hiện. | `manifest_checksum` (manifest đã chuẩn hoá) nằm trong `source_checksum`. |
| DEV299-P1-04/05 | Jira pin `m1-be-2` còn code ở `m1` ⇒ mọi đường dẫn evidence 404. | `WWHN-299_SOURCE_PIN.json` + evidence sinh bởi test + workflow upload artifact. |

## 3. AC-SRS-021 mapping (chạy được, không phải tuyên bố)

| AC | Cơ chế | Test |
|---|---|---|
| Dry-run không đổi dữ liệu | `dryRun()` chỉ ghi `migration_runs`/`migration_errors` | `test_dry_run_passes_and_writes_no_business_data` |
| Commit chỉ khi checksum/reconciliation PASS | Gate `DRY_RUN_NOT_PASSED` + `CHECKSUM_CHANGED` (phủ cả manifest); reconciliation FAIL ném exception trong transaction | `test_commit_is_blocked_without_passed_dry_run`, `test_changed_package_after_dry_run_blocks_commit`, `test_changed_manifest_metadata_after_dry_run_blocks_commit`, `test_reformatting_manifest_does_not_change_checksum` |
| Failed reconciliation chặn cutover | Exit code 1, run `FAILED`, `RECON_MISMATCH` | `test_serial_tracked_quantity_must_match_item_count` |
| Rerun idempotent theo migration_run_id | Replay báo cáo đã lưu; `business_key` khoá theo `run_id` | `test_commit_rerun_with_same_run_code_is_idempotent` |
| Sign-off của Data Owner | Permission Data Owner + separation of duty + `--approval-ref` lưu vào `migration_runs.note` | `test_commit_is_blocked_without_approver`, `test_commit_is_blocked_when_approver_is_not_a_data_owner`, `test_commit_is_blocked_when_approver_is_the_operator`, `test_commit_is_blocked_without_approval_reference` |
| Rollback không để lại tồn dư | REVERSAL append-only + khôi phục/loại bỏ/disposition master; 4 điều kiện PASS | `test_rollback_leaves_no_residual_opening_stock`, `test_rollback_removes_masters_created_by_the_run`, `test_rollback_disposes_ledger_pinned_masters_instead_of_leaving_them_active`, `test_rollback_restores_masters_overwritten_by_the_package` |
| Không tạo dữ liệu sai / không lộ secret | Operator phải đủ quyền; lỗi hệ thống trả error code, không stack trace; PII mask | `test_unknown_operator_is_rejected_before_any_run_is_created`, `test_operator_without_required_permission_is_rejected`, `test_dry_run_reports_row_level_errors_with_masked_pii` |

## 4. Kết quả chạy thực tế

**Test tự động**

| Bộ | Driver | Kết quả |
|---|---|---|
| `MigrationFinalDataLoadTest` (20 test) | sqlite | 20 passed / 120 assertions |
| `MigrationFinalDataLoadTest` (20 test) | PostgreSQL 16 | 20 passed / 120 assertions |
| `Wwhn299CutoverRehearsalEvidenceTest` | sqlite | passed / 24 assertions, sinh `build/evidence/wwhn-299/` |

Bản diễn tập kèm trong gói này được sinh trên **sqlite**. Lần sinh lại trên PostgreSQL
ở máy local chưa thực hiện được vì một phiên làm việc song song trên `m1` đang có
migration hỏng (`2026_08_19_000100_add_optimistic_lock_to_pv_master_tables.php` — mirror
`pv_industries` ép `created_by` uuid từ text), làm `migrate` trên PostgreSQL dừng giữa
chừng cho **mọi** test chứ không riêng WWHN-299. Vì vậy workflow
`.github/workflows/wwhn-299-final-data-load-gate.yml` bắt buộc sinh evidence trên
PostgreSQL 16 và **fail nếu `MANIFEST.json` không có `db_driver = pgsql`** — đó là nơi
bản PG chính thức được tạo theo exact SHA.

Đã kiểm chứng nguyên nhân là ở migration đó chứ không phải tranh chấp DB dùng chung:
chạy lại trên một database PostgreSQL **riêng** (`hoa_nam_wwhn299`) vẫn hỏng y hệt ở
cùng một trigger, cả 21 test đều dừng ngay trong `setUp`.

PostgreSQL quan trọng vì đó là nơi trigger `trg_erd_migration_commit_evidence` (COMMIT
bắt buộc reconciliation `PASS` + có checksum) và các CHECK constraint của ERD thực sự chạy.

**Diễn tập cutover** — `01-dry-run.json` → `02-commit.json` → `03-rollback.json`, digest
trong `MANIFEST.json`. Tái lập bằng:

```bash
php artisan test --filter Wwhn299CutoverRehearsalEvidenceTest
```

Ba file JSON trong thư mục này là bản sao nguyên văn của `build/evidence/wwhn-299/`; đối
chiếu bằng `sha256` ghi trong `MANIFEST.json`.

**Cảnh báo về full suite:** tại thời điểm chạy, nhánh `m1` đang có phiên làm việc song
song khác (WWHN-260/268/330, master data PV, route `pv-admin` mới) với nhiều file chưa
commit. Toàn bộ lỗi của full suite sqlite nằm ngoài phạm vi WWHN-299 — chi tiết ở §8 của
`WWHN-299_DEFECT_FIX_REGISTER.md`.

## 5. Ảnh hưởng contract / FE

- **Không có** thay đổi endpoint, payload, enum hay error code của `/api/v1` — đúng
  `DEC-MIG-API-001`.
- OpenAPI/Postman **không đổi** ⇒ FE1 không phải adapt gì. Đã soát repo FE: không có
  tham chiếu nào tới `migration_run` / `final-load` / `opening_balance`.
- **Không thêm bảng**: nhật ký snapshot dùng `audit_log` (ERD bảng 7); ERD giữ đúng 59
  bảng canonical (`ErdCompilerSourcePinTest`).
- Lần sửa này **không thêm migration mới**.

## 6. Gate còn lại (không tự ký)

| Gate | Owner | Jira |
|---|---|---|
| Import Package v1.2 production thật + checksum đã duyệt | BA / Data Owner | WWHN-333 |
| Ký duyệt opening balance của QL kho trên dữ liệu production (`--approved-by` + `--approval-ref`) | Data Owner | WWHN-299 |
| Thực thi cutover trong window đã duyệt sau freeze + backup | DevOps | WWHN-298 |
| QA verify dry-run/commit/rollback trên UAT/PROD | QA1 | WWHN-301/302 |
| Đổi pin nguồn trên Jira từ `m1-be-2` sang `m1` @ exact SHA | PM/Tech | WWHN-299 |
