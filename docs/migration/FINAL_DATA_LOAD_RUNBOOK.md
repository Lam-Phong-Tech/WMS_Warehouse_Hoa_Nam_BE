# Runbook — Lần nạp dữ liệu cuối (Final data load)

- Jira: `WWHN-299` — `[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`; parent `WWHN-80` (GOLIVE cutover).
- Baseline: SRS/FRD v1.12 `REQ-SRS-021` / `AC-SRS-021` / `NFR-SRS-017` / §13.12; `DEC-MIG-API-001`; `DEC-REV-001`; ERD bảng 7 `audit_logs`, 58 `migration_runs`, 59 `migration_errors`.
- Branch: `m1` (backend). Không có endpoint public/admin — chỉ console job (`DEC-MIG-API-001`).

## 1. Lệnh

```bash
php artisan migration:final-load --mode=DRY_RUN --package=<thư-mục-package> --run-code=<mã-run> --executed-by=<username-operator> --output=build/evidence/wwhn-299/01-dry-run.json
```

```bash
php artisan migration:final-load --mode=COMMIT --package=<thư-mục-package> --run-code=<mã-run> --executed-by=<username-operator> --approved-by=<username-data-owner> --approval-ref=<mã-quyết-định> --output=build/evidence/wwhn-299/02-commit.json
```

```bash
php artisan migration:final-load --mode=ROLLBACK --run-code=<mã-run> --executed-by=<username-operator> --output=build/evidence/wwhn-299/03-rollback.json
```

Exit code `0` = `PASSED`/`COMMITTED`/`ROLLED_BACK`; exit code `1` = `FAILED`/`BLOCKED`. Cutover gate dùng đúng exit code này.

## 2. Ai được chạy, ai được duyệt

Cấu hình ở `config/final_data_load.php` (override bằng env), mặc định bám ma trận SRS §3:

| Vai | Permission tối thiểu | Vì sao |
|---|---|---|
| `--executed-by` (cutover operator) | `warehouse.view`, `inventory.view` | Phải là tài khoản trong phạm vi kho, không phải account ngoài hệ thống. |
| `--approved-by` (Data Owner) | `warehouse.manage`, `inventory.view` | `warehouse.manage` chỉ Admin và QL kho được Allow ⇒ đúng tập ký duyệt opening balance (§13.12 *"QL kho signs result"*, ERD 58 `approved_by`). |

Ràng buộc bắt buộc khi COMMIT:

- **Separation of duty**: `--approved-by` không được trùng `--executed-by`.
- **`--approval-ref`**: mã quyết định/biên bản ký duyệt opening balance (ví dụ `WWHN-298#SIGNOFF-1`), tối đa 120 ký tự, được lưu vào `migration_runs.note` dạng `APPROVAL_REF=<id>`. Không có nó thì không biết *duyệt theo văn bản nào*, chỉ biết *ai bấm*.

Mọi từ chối quyền xảy ra **trước khi tạo `migration_runs`**, nên không để lại run rác:
`MIGRATION_OPERATOR_UNKNOWN`, `MIGRATION_OPERATOR_FORBIDDEN`, `MIGRATION_APPROVER_UNKNOWN`,
`MIGRATION_APPROVER_FORBIDDEN`, `MIGRATION_APPROVER_SAME_AS_OPERATOR`,
`MIGRATION_APPROVAL_REF_REQUIRED`, `MIGRATION_APPROVAL_REF_INVALID`.

## 3. Cấu trúc Import Package v1.2

Thư mục chứa `manifest.json` + một CSV cho mỗi dataset. Template: `docs/migration/import-package-v1.2-template/`.

| Dataset | Cột bắt buộc (SRS §13.12) |
|---|---|
| `AGENCY` | agency_code, agency_name, phone, address, status |
| `SKU` | sku_code, sku_name, sku_type, unit, is_serial_tracked, version_code, status |
| `PHYSICAL_CODE` | code_value, code_type, carrier_type, hardware_uid, target_legacy_key, status |
| `CONTAINER` | container_code, type, carrier, physical_code, primary_sku, parent_tray, status |
| `ITEM` | legacy_item_key, sku_code, serial_number, code_value, condition, import_batch |
| `OPENING_BALANCE` | warehouse_code, sku_code, stock_status, quantity, source, owner_confirmation |

Thứ tự nạp cố định: `AGENCY → SKU → PHYSICAL_CODE → CONTAINER → ITEM → OPENING_BALANCE`.

### Checksum nguồn

```
manifest_checksum = sha256( json(package_version, source_file_name, datasets đã ksort) )
source_checksum   = sha256( "manifest:" + manifest_checksum + LF + "DATASET:sha256(file)" đã sắp xếp )
```

- Đổi **bất kỳ file dữ liệu nào** sau dry-run → checksum khác → `CHECKSUM_CHANGED`.
- Đổi **metadata manifest** (`source_file_name`, `package_version`, ánh xạ dataset → file) cũng đổi checksum, kể cả khi CSV không đổi một byte.
- **Format lại** manifest (thụt lề, thứ tự khoá, xuống dòng) *không* đổi checksum, vì chỉ phần normative được băm.

## 4. Gate fail-closed đã hiện thực hoá

| Gate | Hành vi |
|---|---|
| Dry-run không đổi dữ liệu | `DRY_RUN` chỉ ghi `migration_runs` + `migration_errors`; không chạm bảng nghiệp vụ. |
| Commit cần dry-run PASS | Không có `DRY_RUN` `PASSED` cùng run code → `DRY_RUN_NOT_PASSED`, không tạo run COMMIT. |
| Commit cần đúng checksum | Package hoặc manifest đổi sau dry-run → `CHECKSUM_CHANGED`. |
| Commit cần Data Owner thật | Xem §2 — không đủ quyền, tự duyệt, hoặc thiếu `--approval-ref` đều bị chặn. |
| Reconciliation chặn cutover | Reconciliation `FAIL` ném exception trong transaction → rollback toàn bộ master/opening/movement, run = `FAILED`. |
| Rerun idempotent | Cùng run code + cùng checksum → replay báo cáo đã lưu; opening movement khoá bằng `business_key = OPENING|<run_id>|<wh>|<sku>|<status>`. |
| Snapshot bắt buộc | Mỗi lần chạm master phải ghi được snapshot vào `audit_log` với policy `fail_closed`; ghi audit hỏng ⇒ COMMIT rollback. |
| Không lộ secret | Lỗi hệ thống trả error code + hướng dẫn tra log; không in stack trace. PII (`phone`, `address`, `serial_number`, `hardware_uid`, `owner_confirmation`) được mask trong `migration_errors.raw_value_masked`. |

## 5. Tiêu chí PASS

**Dry-run PASS** (SRS §13.12): `critical_error = 0`, `error = 0` (REQUIRED/INVALID_ENUM/INVALID_TYPE), duplicate code/serial/hardware_uid = 0, unknown FK = 0, và reconciliation nguồn PASS. `valid_rows + error_rows = total_rows`.

**Reconciliation PASS**:
1. `SOURCE_QTY_EQUALS_LEDGER` — mỗi dòng opening khớp 100% tổng `qty_delta` của movement do run tạo ra.
2. `BALANCE_EQUALS_LEDGER` — `inventory_balances.on_hand_qty` khớp tổng ledger của cùng chiều (kho/SKU/stock_status).
3. `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT` — SKU serial-tracked có số lượng opening bằng số Item duy nhất.

## 6. Rollback

Ledger là append-only (`stock_movements` chặn update/delete, `DEC-REV-001`), nên rollback **đảo** hiệu ứng bằng movement `REVERSAL` đối ứng thay vì xoá. Master thì được khôi phục từ nhật ký snapshot ghi lúc COMMIT.

### 6.1 Cơ chế

- Mỗi `OPENING` movement của run sinh đúng một `REVERSAL` (khoá `REVERSAL|<rollback_run_id>|<movement_id>`), balance trả về giá trị trước run.
- Master được xử lý theo thứ tự **ngược** với lúc ghi (balance → item → container → physical code → SKU → agency):

| Loại snapshot | Xử lý |
|---|---|
| `UPDATE` (master có trước cutover, bị package ghi đè) | Ghi lại toàn bộ cột về đúng giá trị trước run. |
| `CREATE` xoá được | Xoá cứng. |
| `CREATE` bị ledger giữ khoá ngoại | Disposition: `sku`/`agencies`/`physical_codes` → `INACTIVE`; `containers`/`import_batches` → `CLOSED`; `item_instances` → `ADJUSTED_OUT`. Ghi `reason = LEDGER_PINNED` vào evidence. |
| `CREATE` không xoá và không disposition được | `retained_rows` ⇒ rollback `FAIL`, cần chủ dữ liệu xử lý tay. |
| `inventory_balances` do run tạo | Chỉ xoá khi mọi cột số lượng đã về 0; còn số dư ⇒ `NON_ZERO_STATE` ⇒ `FAIL`. |

> Vì sao SKU thường không xoá cứng được: chính `OPENING` và `REVERSAL` của run đang
> giữ khoá ngoại tới nó, mà ledger thì không được xoá. Đây là ràng buộc thật của
> baseline, nên rollback báo `LEDGER_PINNED` thay vì im lặng để lại một SKU `ACTIVE`
> do cutover sinh ra.

> Cột master trùng khoá nhạy cảm của `config/audit.php` (hôm nay: `agencies.phone`) không
> nằm dạng rõ trong snapshot `MIGRATION_MASTER_SNAPSHOT`: `MasterStateJournal` niêm phong
> chúng bằng lớp mã hoá PII WWHN-SEC-06 vào khoá `sealed_state` và chỉ để lại tên cột.
> Hệ quả vận hành:
>
> - COMMIT và ROLLBACK cần PII keyring (`PII_ENCRYPTION_KEYS`) như mọi môi trường ngoài dev.
> - **Không gỡ key id** khỏi keyring khi còn run `COMMITTED` có thể phải rollback. Thiếu khoá,
>   ciphertext hỏng hoặc bị chép sang snapshot khác đều làm rollback dừng fail-closed với
>   `MIGRATION_SNAPSHOT_SEAL_INVALID`, không ghi gì vào master.
> - Không "chữa" lỗi cutover bằng cách bỏ `phone` khỏi `audit.sensitive_keys` hay bỏ chốt
>   `MIGRATION_SNAPSHOT_REDACTED`: làm vậy là đưa SĐT dạng rõ vào `audit_log`.

### 6.2 Bằng chứng "không còn tồn dư"

`reconciliation_result` của run `#ROLLBACK` phải thoả **cả bốn**:

```
residual_opening_qty                  == 0
residual_master_rows                  == 0
master_rollback.retained_rows         == []
master_rollback.missing_rows          == []
```

`residual_master_rows` đo bằng hai kênh độc lập: đếm lại snapshot `CREATE` còn tồn
tại, và đếm hàng còn mang `migration_run_id` của run trong `containers` /
`item_instances` / `import_batches`.

- Run COMMIT gốc chuyển `ROLLED_BACK`.
- Nếu tồn đã bị tiêu thụ sau commit → `MIGRATION_ROLLBACK_NEGATIVE_STOCK`, dừng fail-closed để xử lý thủ công có kiểm soát.

## 7. Evidence phải nộp cho HN-DEVOPS-005

1. `01-dry-run.json` (status `PASSED`, `source_checksum`, `manifest_checksum`, `file_checksums`, counts, reconciliation checks).
2. `02-commit.json` (status `COMMITTED`, reconciliation `PASS`, cùng `source_checksum` và `manifest_checksum` với dry-run).
3. `03-rollback.json` của lần diễn tập rollback (bốn điều kiện ở §6.2).
4. `MANIFEST.json` — driver DB, run id/run code, checksum nguồn, sha256 từng file.
5. Bản ghi `migration_runs`/`migration_errors` tương ứng (`migration_run_code` = `<run-code>#DRY_RUN|#COMMIT|#ROLLBACK`), và `migration_runs.note` chứa `APPROVAL_REF=<id>`.
6. Audit log `MIGRATION_DRY_RUN_COMPLETED` / `MIGRATION_COMMIT_COMPLETED` / `MIGRATION_ROLLBACK_COMPLETED`, và snapshot `MIGRATION_MASTER_SNAPSHOT` theo `request_id = migration_run_id`.

Diễn tập tái lập được bằng:

```bash
php artisan test --filter Wwhn299CutoverRehearsalEvidenceTest
```

## 8. Thứ tự chạy trong cutover window

1. Freeze nghiệp vụ + backup DB (DevOps, `WWHN-298`).
2. `DRY_RUN` với package đã duyệt → phải `PASSED`, ghi lại `source_checksum`.
3. Data Owner ký duyệt opening balance → lấy mã quyết định cho `--approval-ref`.
4. `COMMIT` với đúng package đó (checksum không đổi) → phải `COMMITTED` + reconciliation `PASS`.
5. Smoke: `php artisan wms:golive-check` (WWHN-300).
6. Nếu No-Go: `ROLLBACK` cùng run code → kiểm bốn điều kiện §6.2, rồi restore backup nếu cần đưa master về nguyên trạng tuyệt đối.

## 9. Phạm vi không thay đổi

- Không thêm/sửa endpoint `/api/v1` nào — OpenAPI không đổi, FE không phải adapt.
- Không thêm bảng: nhật ký snapshot dùng `audit_log` (ERD bảng 7), ERD vẫn đúng 59 bảng canonical.
- Không đổi công thức báo cáo, không đổi semantics tồn kho hiện có; `OPENING` và `REVERSAL` là movement_type đã có sẵn trong ERD/CHECK constraint.
