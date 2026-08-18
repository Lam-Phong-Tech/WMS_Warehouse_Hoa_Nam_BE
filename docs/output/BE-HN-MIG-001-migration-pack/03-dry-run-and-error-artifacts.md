# 3. Dry-run report và artifact số liệu — DEV260-P1-04

## 3.1 Bốn trường định danh nguồn

Mọi run ghi đủ, cả trong DB lẫn trong báo cáo JSON:

| Trường | Ở DB | Ở báo cáo |
|---|---|---|
| Tên file nguồn | `migration_runs.source_file_name` | `source_file_name` |
| Phiên bản package | `migration_runs.package_version` | `package_version` (+ `mapping_version`) |
| SHA-256 | `migration_runs.source_checksum` | `source_checksum` + `file_checksums` từng dataset |
| Định danh run | `migration_runs.id` | `migration_run_id` + `migration_run_code` |

`source_checksum` phủ cả manifest lẫn nội dung từng file, nên đổi bất kỳ thứ gì trong package
sau dry-run đều làm COMMIT dừng bằng `CHECKSUM_CHANGED`.

## 3.2 Bất biến ba số của §13.12

Tiêu chí `valid + invalid + duplicate = input row count` trước đây **không kiểm được**: dòng
trùng bị gộp vào `error_rows`. Đã tách:

- `MigrationErrorBag::duplicateRowKeys()` — dòng chỉ bị lỗi `DUPLICATE*`;
- `invalidRowKeys()` — dòng bị lỗi khác (một dòng vừa trùng vừa sai xếp vào `invalid`, vì
  remediation của nó là sửa dữ liệu chứ không phải khử trùng);
- `migration_runs.duplicate_rows` (`ERD-AMD-007`) để kiểm lại được từ DB.

Đo thật trên package có lỗi cố ý (`08-dry-run-rejected.json`):

```
total 15 = valid 11 + invalid 3 + duplicate 1     invariant_holds = true
```

Và `migration:verify-run` đếm lại từ `migration_errors` rồi so với số đã lưu
(`STORED_COUNTS_MATCH_ERROR_TABLE`) — sửa tay con số trong `migration_runs` sẽ bị bắt.

## 3.3 Error file

`migration:verify-run --error-file=<path>` xuất đúng thứ tự cột §13.12 rồi mới tới cột mở rộng:

```
source_file, sheet, row_no, field, error_code, message, raw_value, severity,
suggested_action, data_owner, migration_run_code
```

- `raw_value` là giá trị **đã mask** với các trường PII (`phone`, `address`, `serial_number`,
  `hardware_uid`, `owner_confirmation`, `contact_name`).
- `suggested_action` là remediation.
- `data_owner` lấy từ mapping đã duyệt — dòng bị loại luôn có người chịu trách nhiệm.

Mẫu thật: `docs/evidence/WWHN-260/09-rejected-rows.csv` (6 dòng, 5 mã lỗi khác nhau).

## 3.4 Tiêu chí PASS đang được cưỡng chế

| Tiêu chí §13.12 | Cơ chế |
|---|---|
| `critical_error = 0` | `hasBlocking()` chặn COMMIT |
| duplicate active item/code/hardware_uid = 0 | `DUPLICATE_CODE` mức CRITICAL |
| unknown required FK = 0 | `UNKNOWN_FK` / `UNKNOWN_SKU` mức CRITICAL |
| required/enum/type = 0 | `REQUIRED` / `INVALID_ENUM` / `INVALID_TYPE` mức ERROR |
| checksum/version/run_id đã ghi | `RUN_RECORDS_SOURCE_VERSION_CHECKSUM_AND_ID` |
| valid+invalid+duplicate = input | `VALID_PLUS_INVALID_PLUS_DUPLICATE_EQUALS_INPUT` |
