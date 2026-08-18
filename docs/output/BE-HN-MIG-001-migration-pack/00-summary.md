# Migration Pack v1.0 — WWHN-260

## Traceability

- Jira: `WWHN-260` — Migration/nạp dữ liệu cutover (register P0/P1/P2 ngày 18/08/2026)
- Ticket liên quan: `WWHN-69` (AC #1–4), `WWHN-225` (nguồn/mapping), `WWHN-299` (job),
  `WWHN-261` (UAT/ops), `WWHN-262` (QA độc lập), `WWHN-263` (cleansing log), `WWHN-333` (package)
- Output: **Migration Pack** — `BE-HN-MIG-001`
- Repository/branch: `WMS_Warehouse_Hoa_Nam_BE` @ `m1` (không tạo nhánh riêng)
- Phiên bản pack: **1.0** · Ngày phát hành: 18/08/2026
- Trạng thái: `IMPLEMENTED / EVIDENCE READY — PENDING SIGN-OFF`. BE không tự chuyển `Done`.

## Nguồn chuẩn đã đọc và đối chiếu

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — §13.12 (template/sample/error file/dry-run/reconciliation),
   §13.13 (DoD migration/cutover ticket), §14 Data Dictionary, `REQ-SRS-021`, `REQ-SRS-034`,
   `NFR-SRS-017`, `AC-SRS-021`, `AC-GWT-MIG-001`, `DEC-MIG-API-001`.
2. `docs/HoaNam_ERD_Moi_Nhat.md` (`HN-WMS-ERD-DD-001`) — bảng 58 `migration_runs`,
   59 `migration_errors`, 19/20/22 (`migration_run_id`), 43/44 (ledger/balance),
   `movement_type = OPENING`, `ERD-AMD-007`.
3. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — **không có** màn hình
   migration/import master, đúng với quyết định operational-only.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — console job, transaction boundary,
   audit, migration/seed convention.

## Nội dung pack

| Phần | File | Đóng ID nào |
|---|---|---|
| 0 | `00-summary.md` (file này) | DEV260-P1-01 |
| 1 | `01-data-mapping.md` | DEV260-P1-02 |
| 2 | `02-scripts-and-coverage.md` | DEV260-P1-03 |
| 3 | `03-dry-run-and-error-artifacts.md` | DEV260-P1-04 |
| 4 | `04-reconciliation-evidence.md` | DEV260-P1-05 |
| 5 | `05-repeatability-and-timing.md` | DEV260-P1-06 |
| 6 | `06-rejected-rows-and-cleansing-log.md` | DEV260-P1-11 |
| 7 | `07-source-and-build-disposition.md` | DEV260-P1-07, DEV260-P1-08 |
| 8 | `08-ops-uat-and-qa-handover.md` | DEV260-P1-09, DEV260-P1-10 |
| — | `MANIFEST.json` | Danh mục + SHA-256 của từng phần |

Artifact chạy thật nằm ở `docs/evidence/WWHN-260/` (11 file + 2 package diễn tập).

## Thay đổi mã nguồn thuộc pack này

| File | Vai trò |
|---|---|
| `app/Support/Migration/MigrationErrorFile.php` | Sinh error file đúng field spec §13.12 + cột `data_owner` |
| `app/Console/Commands/MigrationVerifyRun.php` | Xác minh độc lập (`migration:verify-run`) cho QA/Data Owner |
| `app/Support/Migration/FinalDataLoadService.php` | Tách `duplicate` khỏi `invalid`; chặn nạp trùng package; đối soát container; map brand/category/model; cache tra cứu; đo thời lượng thật |
| `app/Support/Migration/MigrationErrorBag.php` | Phân nhóm dòng trùng / dòng sai |
| `app/Support/Migration/ImportPackage.php` | Cột tuỳ chọn brand/category/model; số dòng theo dataset; tên file nguồn theo dataset |
| `config/migration_mapping.php` | Mapping version + chủ dữ liệu theo dataset + ngưỡng capacity |
| `Modules/Warehouse/database/migrations/2026_08_18_193000_add_duplicate_row_count_to_migration_runs.php` | `migration_runs.duplicate_rows` (ERD-AMD-007) |
| `docs/HoaNam_ERD_Moi_Nhat.md` (BE + FE) | ERD bảng 58 + Amendment Register `ERD-AMD-007` |
| `tests/Feature/Wwhn260MigrationPackClosureTest.php` | 10 test phủ toàn bộ tiêu chí trên |

## Ảnh hưởng contract

Không có thay đổi `/api/v1`, OpenAPI, Postman, error code hay màn hình — đúng `DEC-MIG-API-001`.
FE chỉ thêm một guard test khẳng định **không** tồn tại đường migration trên UI.

## Gate còn lại (không tự ký)

| Gate | Owner | Ticket |
|---|---|---|
| Duyệt mapping v1.0 và chủ dữ liệu từng nguồn | BAS / Data Owner | `WWHN-260`, `WWHN-225` |
| Import Package v1.2 production + checksum đã duyệt | BA / Data Owner | `WWHN-333` |
| Ký duyệt tồn đầu kỳ trên dữ liệu production | QL kho | `WWHN-260` (`--approved-by`) |
| Xác nhận diễn giải rollback append-only | Tech Lead | `WWHN-260` |
| Chạy UAT/backup/restore/rollback trước lần nạp cuối | DevOps | `WWHN-261` |
| Xác minh độc lập trên đúng SHA + checksum production | QA2 | `WWHN-262` |
| Cleansing log của dòng bị loại | BAS / Data Owner | `WWHN-263` |
