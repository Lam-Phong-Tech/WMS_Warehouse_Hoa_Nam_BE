# WWHN-260 — Migration / nạp dữ liệu cutover

## Kiểm soát

- Jira: `WWHN-260`. Register đánh giá ngày 18/08/2026: **34/100**, 11 mục P1 + 1 mục P2.
- Ticket liên quan: `WWHN-69` (AC #1–4), `WWHN-225` (nguồn/mapping), `WWHN-299` (job nạp cuối),
  `WWHN-261` (UAT/ops), `WWHN-262` (QA độc lập), `WWHN-263` (cleansing log), `WWHN-333` (package).
- Output: **Migration Pack** — `docs/output/BE-HN-MIG-001-migration-pack/` (v1.0).
- Repo/branch: `WMS_Warehouse_Hoa_Nam_BE` + `WMS_Warehouse_Hoa_Nam_FE` @ `m1`. Không tạo nhánh riêng.
- Ngày: 18/08/2026.
- Status: `IMPLEMENTED / EVIDENCE READY — PENDING SIGN-OFF`. BE không tự chuyển `Done`.

## 1. Đọc gì trước khi sửa

`docs/HN_SRS_FRD_v1.12_FULL.md` §13.12 (template/sample/error file/tiêu chí dry-run và
reconciliation), §13.13 (DoD của ticket migration/cutover), §13.14 (nội dung waiver), §14 Data
Dictionary; `REQ-SRS-021`, `REQ-SRS-034`, `NFR-SRS-017`, `AC-SRS-021`, `AC-GWT-MIG-001`,
`DEC-MIG-API-001`. `docs/HoaNam_ERD_Moi_Nhat.md` bảng 58/59 + 19/20/22 + 43/44.
`docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — không có màn hình migration.
`docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — console job, transaction, audit.

## 2. Khoảng trống thật đã tìm ra (không phải suy đoán)

| # | Khoảng trống | Vì sao là lỗi | Đã đóng bằng |
|---|---|---|---|
| 1 | Bất biến `valid + invalid + duplicate = input` của §13.12 **không kiểm được**: dòng trùng bị gộp vào `error_rows`, và `migration_runs` không có ô cho số dòng trùng. | Tiêu chí dry-run PASS trở thành tuyên bố, không phải phép đo. | Tách nhóm ở `MigrationErrorBag`; thêm `migration_runs.duplicate_rows` (`ERD-AMD-007`). |
| 2 | Tiêu chí "container mapping creates no extra stock" **chưa có ai kiểm**. | Nếu mapping container sinh movement, tồn sai mà đối soát vẫn báo PASS. | 2 check mới `CONTAINER_MAPPING_NO_EXTRA_STOCK` + `RUN_WRITES_ONLY_OPENING_MOVEMENTS` chạy trong transaction COMMIT. |
| 3 | Idempotency chỉ neo theo `run_code`. Chạy lại **cùng package** dưới run code khác sẽ cộng tồn đầu **lần thứ hai**, không cảnh báo. | Đây là kịch bản có thật của một cutover phải chạy lại trong đêm. | Khoá theo `source_checksum`: `PACKAGE_ALREADY_COMMITTED`; khoá tự mở sau rollback. |
| 4 | Không có **error file**. Dòng bị loại chỉ nằm trong DB và trong JSON, không có lý do/chủ/cách xử lý ở dạng bàn giao được. | `WWHN-263` (cleansing log) không có đầu vào; `NFR-SRS-017` đòi "error file đầy đủ". | `MigrationErrorFile` + `migration:verify-run --error-file` (đúng thứ tự cột §13.12 + `data_owner`). |
| 5 | Không có đường **xác minh độc lập**: mọi kết luận đều do chính job tự báo cáo. | QA2 không thể ký một con số mà chỉ có bên bị kiểm tạo ra. | Lệnh `migration:verify-run` dựng lại toàn bộ kết luận từ DB + băm lại package. |
| 6 | Cột phân loại **brand/category/model** của dataset SKU (§13.12) bị bỏ qua khi ghi. | Catalog sau cutover mất phân loại; báo cáo/PV lọc theo hãng/nhóm sẽ rỗng. | Map sang FK master thật; khai mà không giải được thì loại dòng bằng `UNKNOWN_FK`. |
| 7 | Thời lượng run suy ra từ `started_at`/`finished_at` (độ phân giải **giây**) ⇒ mọi run dưới 1 giây báo `duration_ms = 0`. | Evidence "runtime phù hợp cutover window" vô nghĩa. | Đo bằng đồng hồ đơn điệu, báo kèm `duration_source`. |
| 8 | Mỗi dòng opening/item gọi lại `SELECT` cho cùng một mã kho/SKU ⇒ chi phí O(số dòng) truy vấn. | `NFR-SRS-017` yêu cầu ≥10.000 dòng/file. | Cache tra cứu theo run (chỉ nhớ kết quả tìm thấy). |
| 9 | Mapping nguồn→đích chỉ tồn tại ngầm trong code; không có version, không có chủ dữ liệu. | Không duyệt được thứ không viết ra; dòng bị loại không có người chịu trách nhiệm. | `docs/migration/DATA_MAPPING_v1.0.md` + `config/migration_mapping.php`; báo cáo in `mapping_version`. |
| 10 | Không có runbook backup/restore/rollback cho cutover. | `WWHN-261` bị chặn vì không có việc cụ thể để làm. | `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md`. |
| 11 | Không có quyết định nhánh nguồn; evidence pin theo tên nhánh chứ không theo SHA. | `m1` có nhiều phiên làm việc song song nên "HEAD của m1" không phải bằng chứng. | `DEC-MIG-SRC-001`. |

## 3. Đóng register P1/P2

| ID | Trạng thái | Bằng chứng |
|---|---|---|
| DEV260-P1-01 | ĐÓNG (chờ duyệt) | `docs/output/BE-HN-MIG-001-migration-pack/` v1.0 + `MANIFEST.json` (SHA-256 từng phần) |
| DEV260-P1-02 | ĐÃ SOẠN — **chờ BAS/Data Owner ký** | `docs/migration/DATA_MAPPING_v1.0.md`, `config/migration_mapping.php` |
| DEV260-P1-03 | ĐÓNG | Pack phần 2; `migration:final-load` phủ 6 dataset + cột phân loại; nêu rõ `ImportService.php` không phải đường migration |
| DEV260-P1-04 | ĐÓNG | Evidence 01/03/08 + error file 09; bất biến 3 số kiểm lại được từ DB |
| DEV260-P1-05 | ĐÓNG | Evidence 03/04 — 5 nhóm check, gồm 2 check container mới |
| DEV260-P1-06 | ĐÓNG | Evidence 02/05/06/07/11 — rerun, chặn nạp trùng, clean reset, thời lượng thật ở 30.000 dòng |
| DEV260-P1-07 | ĐÓNG | Pack phần 7 + `MANIFEST.json` → `implementation_sha` |
| DEV260-P1-08 | ĐÃ SOẠN — **chờ PM/Tech Lead duyệt** | `DEC-MIG-SRC-001` |
| DEV260-P1-09 | ĐÃ SOẠN — **chờ DevOps thực thi** | `docs/migration/CUTOVER_BACKUP_RESTORE_ROLLBACK.md` |
| DEV260-P1-10 | ĐÓNG (công cụ) — **chờ QA2 chạy trên package production** | `migration:verify-run` + evidence 04/10 |
| DEV260-P1-11 | ĐÓNG (công cụ + mẫu) — **chờ Data Owner điền** | Pack phần 6 + `09-rejected-rows.csv` |
| DEV260-P2-01 | ĐÓNG | §5 dưới đây |

## 4. Kết quả chạy

| Hạng mục | Kết quả |
|---|---|
| `tests/Feature/Wwhn260MigrationPackClosureTest.php` (sqlite) | 10/10 PASS, 54 assertions |
| `tests/Feature/MigrationFinalDataLoadTest.php` | không hồi quy |
| `tests/Feature/Erd*ParityTest.php` sau `ERD-AMD-007` | không hồi quy |
| `src/test/wwhn-260-migration-operational-only.test.ts` (FE) | 4/4 PASS |
| Diễn tập cutover thật (11 artifact) | `docs/evidence/WWHN-260/` |
| Capacity `NFR-SRS-017` | 30.000 dòng (3 file × 10.000) PASSED trong 353 ms |

Lưu ý khi chạy lại toàn bộ suite BE: nhánh `m1` đang có nhiều phiên làm việc song song
(WWHN-230/231/239/252/254/268/299/300…), nên một lần chạy full suite có thể đỏ ở các test thuộc
những ticket đó. Ba bộ test của `WWHN-260` ở trên chạy độc lập đều xanh.

## 5. Governance — DEV260-P2-01

Mô tả ticket vẫn ghi owner là **Duy Khánh**, trong khi assignee hiện tại là **Nguyễn Thanh Quang**.
Cách đọc chính thức: *Duy Khánh là owner lịch sử ở giai đoạn lập kế hoạch/đặc tả; Nguyễn Thanh
Quang là owner hiện hành chịu trách nhiệm thực thi và bàn giao.* Không sửa lịch sử mô tả; ghi chú
này là bản phân định để tránh nhầm khi truy vết trách nhiệm.

## 6. Ảnh hưởng contract

Không có thay đổi `/api/v1`, OpenAPI, Postman, error code hay màn hình — đúng `DEC-MIG-API-001`.
Thay đổi schema duy nhất là cột `migration_runs.duplicate_rows` (additive, NOT NULL DEFAULT 0,
có `down()` thật).

## 7. Blocker / Open point (không tự đóng)

| # | Nội dung | Owner |
|---|---|---|
| 1 | Duyệt mapping v1.0 + chủ dữ liệu từng dataset | BAS / Data Owner (`WWHN-225`) |
| 2 | Import Package v1.2 production thật + checksum đã duyệt | BA / Data Owner (`WWHN-333`) |
| 3 | Ký duyệt tồn đầu kỳ trên dữ liệu production | QL kho (`--approved-by` + `--approval-ref`) |
| 4 | Xác nhận diễn giải rollback append-only và `DEC-MIG-SRC-001` | Tech Lead |
| 5 | Chạy UAT + backup/restore/rollback trước lần nạp cuối | DevOps (`WWHN-261`) |
| 6 | Xác minh độc lập trên đúng SHA + checksum production | QA2 (`WWHN-262`) |
| 7 | Điền cleansing log cho dòng bị loại | BAS / Data Owner (`WWHN-263`) |
