# WWHN-299 — Sổ đóng defect P1/P2 (đánh giá 60/100)

- Jira: `WWHN-299` — `[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`; parent `WWHN-80` (GOLIVE cutover).
- Baseline: SRS/FRD v1.12 `REQ-SRS-021` / `AC-SRS-021` / `NFR-SRS-017` / §13.12 / §13.13; `DEC-MIG-API-001`; `DEC-REV-001`; ERD `HN-WMS-ERD-DD-001` bảng 7, 19, 20, 22, 58, 59.
- Nhánh: `m1` (backend). Không tạo nhánh riêng.
- Ngày: 2026-08-18.

> Tài liệu này **không** tuyên bố QA1 PASS, cũng **không** tuyên bố đã nạp dữ liệu
> production. Nó ghi đúng những gì DEV đã đóng được và những gì vẫn thuộc gate của
> Data Owner / DevOps / QA1 / BA.

---

## 1. Trạng thái từng mục

| ID | Sev | Trạng thái | Bằng chứng chạy được |
|---|---|---|---|
| DEV299-P1-01 | P1 | **ĐÃ ĐÓNG (code + test)** | `MasterStateJournal`; 3 test rollback |
| DEV299-P1-02 | P1 | **ĐÃ ĐÓNG (code + test)** | `CutoverAuthority`; 4 test negative |
| DEV299-P1-03 | P1 | **ĐÃ ĐÓNG (code + test)** | `ImportPackage::normalizeManifest()`; 2 test |
| DEV299-P1-04 | P1 | **ĐÃ ĐÓNG PHẦN DEV** — chờ PM/Tech đổi pin trên Jira | `WWHN-299_SOURCE_PIN.json` |
| DEV299-P1-05 | P1 | **ĐÃ ĐÓNG PHẦN DEV** — file có thật, tái lập được | `Wwhn299CutoverRehearsalEvidenceTest` + `build/evidence/wwhn-299/` |
| DEV299-P1-06 | P1 | **KHÔNG THUỘC DEV** — diễn tập đã có, cutover thật vẫn Blocked | mục 4 |
| DEV299-P2-01 | P2 | **ĐÃ SOẠN comment** | `SIGNOFF_COMMENTS.md` §PM |
| DEV299-P2-02 | P2 | **ĐÃ SOẠN comment** | `SIGNOFF_COMMENTS.md` §FE |

---

## 2. DEV299-P1-01 — Rollback không đảo ngược được master

### Hiện trạng đã sai ở đâu

`writeMasters()` ghi Agency/SKU/Physical_Code bằng `updateOrCreate`. Rollback chỉ:
đảo `OPENING` bằng `REVERSAL`, rồi `containers → CLOSED`, `item_instances →
ADJUSTED_OUT` theo `migration_run_id`. Hệ quả:

1. Master **đã tồn tại trước cutover** bị package ghi đè (tên, đơn vị,
   `version_code`, `status`) và **không có đường quay lại** — rollback giữ nguyên
   giá trị của package.
2. Master **mới tạo** (Agency, SKU, Physical_Code) ở lại vĩnh viễn, không có trạng
   thái đóng, không phân biệt được với dữ liệu thật.

Cả hai vi phạm SRS §13.12: *"Rollback test must prove no subset master/opening/
movement remains"*.

### Đã sửa thế nào

`app/Support/Migration/MasterStateJournal.php` (mới):

- Trước **mỗi** lần ghi master, chụp nguyên trạng bản ghi (`CREATE` nếu chưa có,
  `UPDATE` + `state` đầy đủ nếu đã có) vào `audit_log` — bảng ERD 7, append-only,
  đúng ngữ nghĩa `before_data`/`after_data` — với `request_id = migration_run_id`.
- **Không thêm bảng mới**: ERD vẫn đúng 59 bảng canonical, `ErdCompilerSourcePinTest`
  và `HoaNamErdSchema::TABLE_COUNT` không bị đụng.
- Snapshot ghi **trong cùng transaction** của COMMIT và ép `audit.write_policy =
  fail_closed`: ghi audit hỏng ⇒ ném lỗi ⇒ COMMIT rollback ⇒ **không tồn tại master
  nào không có snapshot**.
- Chống redaction: `AuditLogger::sanitize()` có thể thay giá trị của khoá nhạy cảm
  bằng `[REDACTED]`. Nếu một cột master trùng tên khoá nhạy cảm thì phục hồi sẽ ghi
  đè dữ liệu thật bằng `[REDACTED]`; nên payload phải đi qua sanitize mà không đổi,
  sai thì dừng cutover (`MIGRATION_SNAPSHOT_REDACTED`).

`rollback()` bây giờ:

| Loại | Xử lý |
|---|---|
| `UPDATE` | Ghi lại toàn bộ cột về đúng giá trị trước run. |
| `CREATE` xoá được | Xoá cứng theo thứ tự ngược (`seq` giảm dần: balance → item → container → physical code → SKU → agency), mỗi lần xoá trong một SAVEPOINT riêng. |
| `CREATE` bị ledger giữ | `stock_movements` là append-only (`DEC-REV-001`) nên OPENING/REVERSAL của run vẫn giữ khoá ngoại tới SKU ⇒ **không xoá cứng được**. Chuyển sang disposition: `sku/agencies/physical_codes → INACTIVE`, `containers/import_batches → CLOSED`, `item_instances → ADJUSTED_OUT`, và ghi rõ `reason = LEDGER_PINNED` vào evidence. |
| `CREATE` không xoá và không disposition được | Tính là tồn dư thật ⇒ rollback `FAIL`. |
| `inventory_balances` do run tạo | Chỉ xoá khi mọi cột số lượng đã về 0; còn số dư ⇒ giữ lại và báo `NON_ZERO_STATE` ⇒ `FAIL`. |

Tiêu chí PASS của rollback siết lại thành **bốn** điều kiện đồng thời:

```
residual_opening_qty == 0
&& residual_master_rows == 0
&& master_rollback.retained_rows == []
&& master_rollback.missing_rows == []
```

`residual_master_rows` được đo bằng **hai kênh độc lập**: đếm lại từng snapshot
`CREATE` còn tồn tại, và đếm hàng còn mang `migration_run_id` của run trong
`containers`/`item_instances`/`import_batches`. Nhật ký sai hoặc thiếu thì kênh
`migration_run_id` vẫn tố cáo.

### Test

| Test | Chứng minh |
|---|---|
| `test_rollback_removes_masters_created_by_the_run` | Agency/Physical_Code/Container/Item/Import_Batch/Balance do run tạo **biến mất**; `residual_master_rows = 0`; `retained_rows = []`; `missing_rows = []`. |
| `test_rollback_disposes_ledger_pinned_masters_instead_of_leaving_them_active` | SKU bị ledger giữ → `INACTIVE`, `reason = LEDGER_PINNED`, không còn SKU `ACTIVE` nào do run sinh ra. |
| `test_rollback_restores_masters_overwritten_by_the_package` | SKU/Agency có trước cutover trở lại **đúng** `sku_name`/`unit`/`version_code`/`status` cũ, không bị xoá nhầm. |
| `test_rollback_leaves_no_residual_opening_stock` | Ledger append-only, hiệu ứng ròng `qty_delta = 0`, balance `on_hand = 0`. |

---

## 3. DEV299-P1-02 — Ai cũng làm approver được

### Hiện trạng đã sai ở đâu

`MigrationFinalDataLoad::resolveUser()` chỉ kiểm `app_user.status = ACTIVE`. Nghĩa là:

- một tài khoản **không có thẩm quyền dữ liệu** vẫn mở được COMMIT ghi tồn đầu;
- operator **tự điền chính mình** vào `--approved-by` là hợp lệ;
- `migration_runs.approved_by` (ERD bảng 58 — *"Data Owner/approver evidence"*)
  không chứng minh được điều gì;
- không có chỗ nào lưu **duyệt theo văn bản nào**.

### Đã sửa thế nào

`app/Support/Migration/CutoverAuthority.php` + `config/final_data_load.php`:

| Cổng | Quy tắc | Error code |
|---|---|---|
| Operator | ACTIVE **và** đủ `warehouse.view` + `inventory.view` | `MIGRATION_OPERATOR_UNKNOWN` / `MIGRATION_OPERATOR_FORBIDDEN` |
| Approver | ACTIVE **và** đủ `warehouse.manage` + `inventory.view` | `MIGRATION_APPROVER_UNKNOWN` / `MIGRATION_APPROVER_FORBIDDEN` |
| Separation of duty | `approved_by != executed_by` | `MIGRATION_APPROVER_SAME_AS_OPERATOR` |
| Approval reference | `--approval-ref` bắt buộc, ≤ 120 ký tự, lưu vào `migration_runs.note` dạng `APPROVAL_REF=<id>` | `MIGRATION_APPROVAL_REF_REQUIRED` / `MIGRATION_APPROVAL_REF_INVALID` |

Vì sao đúng tập permission đó: ma trận SRS §3 cho `warehouse.manage` là **Allow chỉ
với Admin và QL kho** (NV kho/NV bảo hành/Lãnh đạo chỉ View), trùng khớp với §13.12
*"QL kho signs result"* và với Data Owner của tồn kho trong Annex A. Danh sách để ở
config nên môi trường khác đổi được mà không phải sửa code, và `SUPER_ADMIN` vẫn qua
được vì `permissionCodes()` trả toàn bộ permission ACTIVE.

Cổng chạy **trước khi tạo bất kỳ `migration_runs` nào**, nên từ chối quyền không để
lại run rác.

### Test

`test_commit_is_blocked_when_approver_is_not_a_data_owner`,
`test_commit_is_blocked_when_approver_is_the_operator`,
`test_commit_is_blocked_without_approval_reference`,
`test_operator_without_required_permission_is_rejected`,
`test_commit_writes_opening_balance_and_reconciles` (khẳng định
`APPROVAL_REF=WWHN-298#SIGNOFF-1` có thật trong `migration_runs.note`).

---

## 4. DEV299-P1-03 — Manifest nằm ngoài checksum

### Hiện trạng đã sai ở đâu

`source_checksum = sha256("DATASET:sha256(file)" đã sắp xếp)`. Manifest không được
băm, nên sau dry-run vẫn đổi được `source_file_name`, `package_version`, hoặc trỏ
`datasets` sang file khác có cùng nội dung mà checksum **không đổi** ⇒ COMMIT vẫn
chạy. Identity của nguồn không được bảo vệ như SRS §13.12 yêu cầu.

### Đã sửa thế nào

```
manifest_checksum = sha256( json(package_version, source_file_name, datasets đã ksort) )
source_checksum   = sha256( "manifest:" + manifest_checksum + LF + "DATASET:sha256(file)" đã sắp xếp )
```

Chuẩn hoá chỉ giữ phần normative, nên **format lại** manifest (thụt lề, thứ tự khoá,
xuống dòng) không làm hỏng checksum, còn **đổi nội dung** normative thì chắc chắn
làm hỏng. `manifest_checksum` và bản manifest đã chuẩn hoá được in ra evidence để
reviewer đối chiếu riêng phần identity.

### Test

`test_changed_manifest_metadata_after_dry_run_blocks_commit` (đổi
`source_file_name`, không đụng byte nào của CSV → `CHECKSUM_CHANGED`),
`test_reformatting_manifest_does_not_change_checksum` (đảo thứ tự khoá → vẫn
COMMIT được), và `test_changed_package_after_dry_run_blocks_commit` (đổi CSV).

---

## 5. DEV299-P1-04 / P1-05 — Pin nguồn và evidence 404

Hai mục này **cùng một gốc**: Jira pin nhánh `m1-be-2`, còn toàn bộ hiện thực nằm
trên `m1`. Reviewer clone theo pin của Jira nên mọi đường dẫn evidence đều 404 — file
có thật, nhưng không có trên nhánh được pin.

Đã làm:

- `WWHN-299_SOURCE_PIN.json` — khai báo formal supersede: nguồn canonical là `m1`,
  `m1-be-2` là ancestor lịch sử, kèm exact SHA của candidate và danh sách file.
- Evidence chuyển từ "viết tay" sang "sản phẩm của một lần chạy":
  `tests/Feature/Wwhn299CutoverRehearsalEvidenceTest.php` chạy đủ ba pha trên Import
  Package template và ghi nguyên văn báo cáo JSON của job vào
  `build/evidence/wwhn-299/` kèm `MANIFEST.json` (driver DB, run id, run code,
  `source_checksum`, `manifest_checksum`, sha256 từng file).
- Workflow `.github/workflows/wwhn-299-final-data-load-gate.yml` chạy lại đúng bộ
  test đó trên PostgreSQL 16 và upload artifact — evidence lấy được theo exact SHA
  thay vì theo lời kể.

Còn lại thuộc PM/Tech: đổi pin trên Jira và đính kèm artifact vào ticket.

---

## 6. DEV299-P1-06 — Lần nạp dữ liệu cuối thật

**Không đóng được ở tầng DEV và không được tuyên bố là đã đóng.** Cutover thật cần
freeze + backup production, Import Package v1.2 thật (attachment WWHN-333), chữ ký
opening balance của QL kho, và xác nhận độc lập của QA1/DO/BA.

DEV đã giao đủ phần của mình để gate chạy được:

| Yêu cầu của gate | Đã có |
|---|---|
| Package checksum cuối | `source_checksum` + `manifest_checksum` in ra evidence |
| Dry-run | `--mode=DRY_RUN`, exit code 0/1, `migration_errors` theo §13.12 |
| Approval | `--approved-by` + `--approval-ref` + separation of duty |
| Commit | Một transaction, reconciliation FAIL ⇒ rollback toàn bộ |
| Reconcile | 3 nhóm check, `PASS`/`FAIL` ghi vào `migration_runs` |
| Rollback rehearsal | Chạy được, có bằng chứng tồn dư = 0 |
| Smoke sau cutover | `php artisan wms:golive-check` (WWHN-300) |

Gate còn Blocked: `WWHN-298` (DevOps thực thi), `WWHN-301`/`WWHN-302`, parent
`WWHN-80`.

---

## 7. Phạm vi không đổi

- Không thêm/sửa endpoint `/api/v1` — đúng `DEC-MIG-API-001`. OpenAPI/Postman không
  đổi ⇒ **FE không phải adapt** (đã soát: repo FE không có tham chiếu nào tới
  `migration_run` / `final-load` / `opening_balance`).
- Không thêm bảng, không đổi ERD: 59 bảng canonical giữ nguyên.
- Không thêm migration mới trong lần sửa này.
- `movement_type = OPENING` và `REVERSAL` là giá trị đã có sẵn trong CHECK
  constraint của ERD.

## 8. Kết quả chạy

| Bộ | Driver | Kết quả |
|---|---|---|
| `MigrationFinalDataLoadTest` (20 test) | sqlite | 20 passed / 120 assertions |
| `MigrationFinalDataLoadTest` (20 test) | PostgreSQL 16 | 20 passed / 120 assertions |
| `Wwhn299CutoverRehearsalEvidenceTest` | sqlite | 1 passed / 24 assertions, sinh `build/evidence/wwhn-299/` |
| `Wwhn299CutoverRehearsalEvidenceTest` | PostgreSQL 16 | **chưa sinh lại được ở local** — xem cảnh báo bên dưới; workflow CI bắt buộc sinh trên PG |

**Cảnh báo về baseline chung:** tại thời điểm chạy, nhánh `m1` đang có phiên làm việc
song song khác (WWHN-260/268/330, master data PV, route `pv-admin` mới) với nhiều
file chưa commit. Full suite sqlite cho 828 test / 784 pass / 14 fail / 2 error, và
**toàn bộ 16 lỗi đó nằm ngoài phạm vi WWHN-299**: parity route Swagger 231 vs 226,
`docs/evidence/WWHN-268/BE_REGRESSION_SCOPE.json` chưa tồn tại, `ACCESS_DENIED` vs
`PERMISSION_DENIED` trên route mới, `wms:golive-check` đỏ vì migration ledger có file
chưa commit, permission catalog stale sau khi `AuthDatabaseSeeder` bị sửa, và unique
`pv_product_profile.product_id` từ migration PV mới. Không lỗi nào chạm
`app/Support/Migration/*`, `MigrationFinalDataLoad`, hay `config/final_data_load.php`.
Số liệu baseline sạch phải đo lại sau khi các phiên song song commit xong.
