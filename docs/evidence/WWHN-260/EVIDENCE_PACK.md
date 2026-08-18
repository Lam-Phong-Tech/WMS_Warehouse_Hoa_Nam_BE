# WWHN-260 — Evidence pack lần nạp dữ liệu cuối

- Ticket: `WWHN-260` — Migration/nạp dữ liệu cutover (register P0/P1/P2 ngày 18/08/2026)
- Ticket thực thi liên quan: `WWHN-299` (job), `WWHN-225` (nguồn dữ liệu), `WWHN-69` (AC #1–4),
  `WWHN-261` (UAT/ops), `WWHN-262` (QA độc lập), `WWHN-263` (cleansing log)
- Nhánh: `m1` (BE + FE). Không tạo nhánh riêng.
- Ngày diễn tập: 18/08/2026
- Baseline: SRS/FRD v1.12 §13.12 + `REQ-SRS-021/034` + `NFR-SRS-017` + `AC-GWT-MIG-001`;
  ERD `HN-WMS-ERD-DD-001` bảng 58/59 (+ `ERD-AMD-007`); `DEC-MIG-API-001`

> Đây là bằng chứng **năng lực và cổng chặn** của quy trình nạp dữ liệu, chạy trên
> package diễn tập. Nó không tuyên bố đã nạp dữ liệu production — cổng đó thuộc
> `WWHN-261`/`WWHN-298` và cần package thật + chữ ký QL kho.

---

## 1. Cách tái lập

```bash
php artisan migrate:fresh --seed --force
P=docs/evidence/WWHN-260/package
R=docs/evidence/WWHN-260/package-rejected
E=docs/evidence/WWHN-260

php artisan migration:final-load --mode=DRY_RUN  --package=$P --run-code=WWHN260-REH   --executed-by=keeper01 --output=$E/01-dry-run.json
php artisan migration:final-load --mode=DRY_RUN  --package=$P --run-code=WWHN260-REH   --executed-by=keeper01 --output=$E/02-dry-run-replay.json
php artisan migration:final-load --mode=COMMIT   --package=$P --run-code=WWHN260-REH   --executed-by=keeper01 --approved-by=manager01 --approval-ref=WWHN-260-SIGNOFF-001 --output=$E/03-commit.json
php artisan migration:verify-run --run-code=WWHN260-REH --mode=COMMIT --package=$P --output=$E/04-verify-commit.json
php artisan migration:final-load --mode=DRY_RUN  --package=$P --run-code=WWHN260-REH-2 --executed-by=keeper01
php artisan migration:final-load --mode=COMMIT   --package=$P --run-code=WWHN260-REH-2 --executed-by=keeper01 --approved-by=manager01 --approval-ref=WWHN-260-SIGNOFF-001 --output=$E/05-double-load-blocked.json
php artisan migration:final-load --mode=ROLLBACK --run-code=WWHN260-REH   --executed-by=keeper01 --output=$E/06-rollback.json
php artisan migration:final-load --mode=COMMIT   --package=$P --run-code=WWHN260-REH-2 --executed-by=keeper01 --approved-by=manager01 --approval-ref=WWHN-260-SIGNOFF-001 --output=$E/07-recommit-after-rollback.json
php artisan migration:final-load --mode=DRY_RUN  --package=$R --run-code=WWHN260-REJ   --executed-by=keeper01 --output=$E/08-dry-run-rejected.json
php artisan migration:verify-run --run-code=WWHN260-REJ --mode=DRY_RUN --package=$R --error-file=$E/09-rejected-rows.csv --output=$E/10-verify-rejected.json
```

`keeper01` (QL kho vận hành) và `manager01` (QL kho ký duyệt) là user của seed baseline;
separation of duty cấm hai vai trùng nhau.

---

## 2. Artifact và kết quả thực đo

| # | File | Nội dung | Exit | Kết quả then chốt |
|---|---|---|---|---|
| 01 | `01-dry-run.json` | Dry-run lần 1 | 0 | `PASSED`; total 11 = valid 11 + invalid 0 + duplicate 0; `duration_ms = 17` |
| 02 | `02-dry-run-replay.json` | Dry-run lặp lại cùng run code | 0 | `replayed = true`, không tạo run thứ hai — rerun idempotent |
| 03 | `03-commit.json` | Commit | 0 | `COMMITTED`; reconciliation `PASS` đủ 5 nhóm check; `duration_ms = 54` |
| 04 | `04-verify-commit.json` | Xác minh độc lập | 0 | `verdict = PASS`; checksum băm lại khớp; balance = ledger |
| 05 | `05-double-load-blocked.json` | Nạp lại cùng package dưới run code khác | 1 | `BLOCKED / PACKAGE_ALREADY_COMMITTED` — không cộng tồn lần hai |
| 06 | `06-rollback.json` | Rollback | 0 | `ROLLED_BACK`; `reversed_movements = 2`; `residual_opening_qty = 0` |
| 07 | `07-recommit-after-rollback.json` | Nạp lại sau khi đã rollback | 0 | `COMMITTED` — clean reset chạy được, `duration_ms = 52` |
| 08 | `08-dry-run-rejected.json` | Dry-run trên package có lỗi cố ý | 1 | `FAILED`; total 15 = valid 11 + invalid 3 + duplicate 1 |
| 09 | `09-rejected-rows.csv` | Error file §13.12 | — | 6 dòng lỗi, đủ cột + `data_owner`, PII đã mask |
| 10 | `10-verify-rejected.json` | Xác minh run FAILED | 1 | `verdict = FAIL` đúng như mong đợi — cổng chặn cutover |
| 11 | `11-capacity-dry-run.json` | Capacity `NFR-SRS-017` | 0 | 30.000 dòng (3 file × 10.000) `PASSED` trong **353 ms** |
| 12 | `12-postgres-column-check.txt` | Kiểm `ERD-AMD-007` trên PostgreSQL thật | — | `duplicate_rows integer NOT NULL DEFAULT 0` — khớp ERD; kèm mô tả lỗi PG đang chặn suite (thuộc `WWHN-252`) |

Package diễn tập nằm ngay trong evidence (`package/`, `package-rejected/`) để bất kỳ ai cũng
băm lại được checksum mà không cần xin file.

---

## 3. Ánh xạ sang register P0/P1/P2 của WWHN-260

| ID register | Đóng bằng |
|---|---|
| DEV260-P1-01 | `docs/output/BE-HN-MIG-001-migration-pack/` (Migration Pack v1.0, có MANIFEST + SHA-256) |
| DEV260-P1-02 | `docs/migration/DATA_MAPPING_v1.0.md` + `config/migration_mapping.php` (mapping + chủ dữ liệu, máy đọc được) |
| DEV260-P1-03 | `migration:final-load` phủ đủ 6 dataset §13.12 + cột phân loại brand/category/model; `ImportService.php` **không** phải đường migration (xem §4) |
| DEV260-P1-04 | Artifact 01/03/08 ghi `source_file_name` + `package_version` + `source_checksum` + `migration_run_id`; bất biến `valid+invalid+duplicate=total`; artifact 09 là file dòng bị loại có lý do/chủ/cách xử lý |
| DEV260-P1-05 | Artifact 03/04: `SOURCE_QTY_EQUALS_LEDGER`, `BALANCE_EQUALS_LEDGER`, `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT`, `CONTAINER_MAPPING_NO_EXTRA_STOCK`, `RUN_WRITES_ONLY_OPENING_MOVEMENTS` |
| DEV260-P1-06 | Artifact 02 (rerun), 05 (chặn double load), 06+07 (clean reset), 11 (runtime ở quy mô 30.000 dòng) |
| DEV260-P1-07 | `docs/output/BE-HN-MIG-001-migration-pack/07-source-and-build-disposition.md` |
| DEV260-P1-08 | `docs/decision-records/DEC-MIG-SRC-001-migration-source-disposition.md` |
| DEV260-P1-09 | `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md` (runbook UAT/backup/restore/rollback cho `WWHN-261`) |
| DEV260-P1-10 | `migration:verify-run` — QA2 dựng lại kết luận từ DB, không tin báo cáo của job |
| DEV260-P1-11 | Artifact 09 + `docs/output/BE-HN-MIG-001-migration-pack/06-rejected-rows-and-cleansing-log.md` |
| DEV260-P2-01 | Ghi trong `docs/docs_task_jira/WWHN-260.md` §Governance |

---

## 4. `ImportService.php` không phải đường migration

Register ghi "code quan sát được là SKU generic". Đúng — `Modules/Warehouse/app/Services/ImportService.php`
là chức năng **import SKU qua Excel trong vận hành thường ngày** (`WWHN-330/331`), có endpoint,
có quyền, chạy lặp lại. Nó **không** ghi `migration_runs`, không ghi movement `OPENING`,
không có reconciliation và không có rollback — nên không thể dùng làm bằng chứng cho
`REQ-SRS-021`.

Đường migration duy nhất là console job `migration:final-load` (+ `migration:verify-run`),
đúng `DEC-MIG-API-001` "no public/admin import endpoint". Hai luồng được tách bằng test:
`tests/Feature/Wwhn260MigrationPackClosureTest.php` (migration) và
`tests/Feature/Wwhn330ImportR3ClosureTest.php` (import vận hành).

---

## 5. Test tự động

| Bộ test | Phạm vi | Kết quả |
|---|---|---|
| `tests/Feature/Wwhn260MigrationPackClosureTest.php` | 10 test: bất biến 3 số, chặn nạp trùng, container không sinh tồn, map brand/category/model, `UNKNOWN_FK`, verifier độc lập, phát hiện số liệu bị sửa, error file có chủ dữ liệu, evidence pin nguồn/checksum/thời lượng, capacity 10.000 dòng/file | 10/10 PASS (sqlite) |
| `tests/Feature/MigrationFinalDataLoadTest.php` | AC-SRS-021 của `WWHN-299` | không hồi quy |
| `tests/Feature/Erd*ParityTest.php` | ERD ↔ schema sau `ERD-AMD-007` | không hồi quy trên sqlite. Bản PostgreSQL **chưa chạy được**: chuỗi migration PG đang hỏng vì `hn_erd_mirror_pv_industries()` thiếu CAST uuid (migration `2026_08_19_000100` của `WWHN-252`), chặn mọi test PG. Cột `duplicate_rows` vì thế được kiểm tách riêng trên PostgreSQL thật — artifact 12. |
