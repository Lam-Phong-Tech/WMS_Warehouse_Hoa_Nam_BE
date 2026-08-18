# 2. Script migration và độ phủ dataset — DEV260-P1-03

## 2.1 Đường nạp dữ liệu duy nhất

| Thành phần | Vai trò |
|---|---|
| `php artisan migration:final-load` | DRY_RUN → COMMIT → ROLLBACK, có kiểm soát |
| `php artisan migration:verify-run` | Xác minh độc lập một run đã chạy |
| `App\Support\Migration\ImportPackage` | Đọc + băm Import Package v1.2 |
| `App\Support\Migration\FinalDataLoadService` | Validate, ghi master/tồn đầu, đối soát, rollback |
| `App\Support\Migration\MigrationErrorBag` | Sinh dòng lỗi đúng field spec, mask PII |
| `App\Support\Migration\MigrationErrorFile` | Xuất error file CSV |

Không có endpoint public/admin nào cho migration (`DEC-MIG-API-001`).

## 2.2 `ImportService.php` không phải migration

Register nhận xét đúng rằng code quan sát được là "SKU generic". Đó là
`Modules/Warehouse/app/Services/ImportService.php` — import SKU qua Excel trong **vận hành
thường ngày** (`WWHN-330/331`): có endpoint, có quyền, chạy lặp lại, không ghi `migration_runs`,
không ghi movement `OPENING`, không có đối soát, không có rollback.

Suy diễn nó là "full migration" sẽ sai ở bốn chỗ đo được: không có `migration_run_id` để trace
(`REQ-SRS-034`), không có checksum để chặn "file đổi sau dry-run", không có tiêu chí PASS của
§13.12, và không có đường quay lại.

## 2.3 Độ phủ 6 dataset của §13.12

| Dataset | Cột bắt buộc §13.12 | Đã hiện thực | Ghi chú |
|---|---|---|---|
| SKU | 7 cột + brand/category/model | Đủ | Cột phân loại là tuỳ chọn nhưng khai thì phải giải được FK |
| PHYSICAL_CODE | 6 cột | Đủ | RFID bắt buộc `hardware_uid` |
| ITEM | 6 cột | Đủ | Serial bắt buộc khi SKU serial-tracked |
| CONTAINER | 7 cột | Đủ | Không sinh movement |
| OPENING_BALANCE | 6 cột | Đủ | Ghi qua `OPENING` + cập nhật balance trong cùng transaction |
| AGENCY | 5 cột | Đủ | `phone`/`address` là PII |

Thiếu cột bắt buộc ⇒ reader ném `PACKAGE_HEADER_MISMATCH` **trước** khi tạo run, nên không thể
nạp một package thiếu dataset mà vẫn báo PASS.

## 2.4 Bằng chứng chạy

- `docs/evidence/WWHN-260/01-dry-run.json` — 6 dataset, 11 dòng, PASSED.
- `docs/evidence/WWHN-260/11-capacity-dry-run.json` — 3 file × 10.000 dòng, PASSED, 353 ms.
- `tests/Feature/Wwhn260MigrationPackClosureTest.php::test_sku_brand_category_model_columns_are_mapped_to_master_foreign_keys`.
