# Runbook — Lần nạp dữ liệu cuối (Final data load)

- Jira: `WWHN-299` — `[BE] Thực hiện/xác minh lần nạp dữ liệu cuối`; parent `WWHN-80` (GOLIVE cutover).
- Baseline: SRS/FRD v1.12 `REQ-SRS-021` / `AC-SRS-021` / `NFR-SRS-017` / §13.12; `DEC-MIG-API-001`; ERD bảng 58 `migration_runs`, 59 `migration_errors`.
- Branch: `m1` (backend). Không có endpoint public/admin — chỉ console job (`DEC-MIG-API-001`).

## 1. Lệnh

```bash
php artisan migration:final-load --mode=DRY_RUN --package=<thư-mục-package> --run-code=<mã-run> --executed-by=<username> --output=build/evidence/wwhn-299/dry-run.json
```

```bash
php artisan migration:final-load --mode=COMMIT --package=<thư-mục-package> --run-code=<mã-run> --executed-by=<username> --approved-by=<username-data-owner> --output=build/evidence/wwhn-299/commit.json
```

```bash
php artisan migration:final-load --mode=ROLLBACK --run-code=<mã-run> --executed-by=<username> --output=build/evidence/wwhn-299/rollback.json
```

Exit code `0` = `PASSED`/`COMMITTED`/`ROLLED_BACK`; exit code `1` = `FAILED`/`BLOCKED`. Cutover gate dùng đúng exit code này.

## 2. Cấu trúc Import Package v1.2

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

`source_checksum` = sha256 trên danh sách `dataset:sha256(file)` đã sắp xếp. **Đổi bất kỳ file nào sau dry-run làm checksum khác và bị chặn ở COMMIT.**

## 3. Gate fail-closed đã hiện thực hoá

| Gate | Hành vi |
|---|---|
| Dry-run không đổi dữ liệu | `DRY_RUN` chỉ ghi `migration_runs` + `migration_errors`; không chạm bảng nghiệp vụ. |
| Commit cần dry-run PASS | Không có `DRY_RUN` `PASSED` cùng run code → `DRY_RUN_NOT_PASSED`, không tạo run COMMIT. |
| Commit cần đúng checksum | Package đổi sau dry-run → `CHECKSUM_CHANGED`. |
| Commit cần sign-off | Thiếu `--approved-by` hợp lệ → `MIGRATION_APPROVER_REQUIRED`; `approved_by`/`approved_at` được lưu vào run. |
| Reconciliation chặn cutover | Reconciliation `FAIL` ném exception trong transaction → rollback toàn bộ master/opening/movement, run = `FAILED`. |
| Rerun idempotent | Cùng run code + cùng checksum → replay báo cáo đã lưu; opening movement khoá bằng `business_key = OPENING|<run_id>|<wh>|<sku>|<status>`. |
| Operator phải hợp lệ | `--executed-by` không phải app_user `ACTIVE` → dừng trước khi tạo run. |
| Không lộ secret | Lỗi hệ thống trả error code + hướng dẫn tra log; không in stack trace. PII (`phone`, `address`, `serial_number`, `hardware_uid`, `owner_confirmation`) được mask trong `migration_errors.raw_value_masked`. |

## 4. Tiêu chí PASS

**Dry-run PASS** (SRS §13.12): `critical_error = 0`, `error = 0` (REQUIRED/INVALID_ENUM/INVALID_TYPE), duplicate code/serial/hardware_uid = 0, unknown FK = 0, và reconciliation nguồn PASS. `valid_rows + error_rows = total_rows`.

**Reconciliation PASS**:
1. `SOURCE_QTY_EQUALS_LEDGER` — mỗi dòng opening khớp 100% tổng `qty_delta` của movement do run tạo ra.
2. `BALANCE_EQUALS_LEDGER` — `inventory_balances.on_hand_qty` khớp tổng ledger của cùng chiều (kho/SKU/stock_status).
3. `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT` — SKU serial-tracked có số lượng opening bằng số Item duy nhất.

## 5. Rollback

Ledger là append-only (`stock_movements` chặn update/delete, DEC-REV-001), nên rollback **đảo** hiệu ứng bằng movement `REVERSAL` đối ứng thay vì xoá:

- Mỗi `OPENING` movement của run sinh đúng một `REVERSAL` (khoá `REVERSAL|<rollback_run_id>|<movement_id>`), balance trả về giá trị trước run.
- `containers` do run tạo → `CLOSED`; `item_instances` do run tạo → `ADJUSTED_OUT` (trace bằng `migration_run_id`).
- Bằng chứng "không còn tồn dư": `residual_opening_qty = 0` trong `reconciliation_result` của run ROLLBACK.
- Run COMMIT gốc chuyển `ROLLED_BACK`.
- Nếu tồn đã bị tiêu thụ sau commit → `MIGRATION_ROLLBACK_NEGATIVE_STOCK`, dừng fail-closed để xử lý thủ công có kiểm soát.

## 6. Evidence phải nộp cho HN-DEVOPS-005

1. `dry-run.json` (status `PASSED`, `source_checksum`, `file_checksums`, counts, reconciliation checks).
2. `commit.json` (status `COMMITTED`, reconciliation `PASS`, cùng `source_checksum` với dry-run).
3. Bản ghi `migration_runs`/`migration_errors` tương ứng (`migration_run_code` = `<run-code>#DRY_RUN|#COMMIT|#ROLLBACK`).
4. `rollback.json` của lần diễn tập rollback (`residual_opening_qty = 0`).
5. Audit log `MIGRATION_DRY_RUN_COMPLETED` / `MIGRATION_COMMIT_COMPLETED` / `MIGRATION_ROLLBACK_COMPLETED`.

## 7. Phạm vi không thay đổi

- Không thêm/sửa endpoint `/api/v1` nào — OpenAPI không đổi, FE không phải adapt.
- Không đổi công thức báo cáo, không đổi semantics tồn kho hiện có; `OPENING` là movement_type đã có sẵn trong ERD/CHECK constraint.
