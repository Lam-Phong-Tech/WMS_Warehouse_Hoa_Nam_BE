# 02 — Migration, seed và nạp dữ liệu cuối

## 1. Kiểm kê migration

| Vị trí | Số file | Vai trò |
| --- | --- | --- |
| `Modules/Warehouse/database/migrations` | 134 | Toàn bộ schema nghiệp vụ: master, item/container, inbound/outbound, ledger, reversal, label, warranty, PV, migration_runs/errors |
| `Modules/Auth/database/migrations` | 6 | `app_user`, `role`, `permission`, `user_role`, `role_permission`, `refresh_tokens` |
| `Modules/AuditLog/database/migrations` | 2 | `audit_log`, `audit_outbox` |
| `database/migrations` | 5 | Hạ tầng Laravel (cache, jobs, sessions…) |

Chạy theo thứ tự timestamp mặc định của Laravel. Ba mốc phải hiểu trước khi đụng vào chuỗi:

| Migration | Chỉ chạy trên | Việc nó làm |
| --- | --- | --- |
| `2026_08_10_000750_materialize_erd_1_0_physical_objects` | pgsql | Đọc thẳng ERD rồi vật chất hoá đủ 59 bảng + thêm mọi cột còn thiếu. Kết thúc bằng assert: entity nào còn là view thì ném lỗi. |
| `2026_08_10_000791_retire_remaining_singular_legacy_tables` | pgsql | Đảo chiều tên: số nhiều thành bảng vật lý, số ít thành view + INSTEAD OF write bridge. `down()` cố ý là no-op (fail-forward). |
| `2026_08_17_000100_materialize_erd_columns_on_sqlite` | sqlite | Cùng nguồn sự thật với 000750 nhưng chỉ thêm cột nullable vào bảng vật lý của sqlite; bỏ qua cột `id` ở các bảng đang dùng khoá chính tên khác. |

Migration thêm trong WWHN-310:

| Migration | Nội dung |
| --- | --- |
| `2026_08_18_193000_align_defect_catalogs_to_erd_entity_15` | `id` canonical + trigger đồng bộ + PK, timestamptz, version DEFAULT 0, varchar(80)/varchar(200), CHECK status, FK `created_by`/`updated_by`, FK `warranty_cases.defect_id`, dựng lại view + write bridge |
| `2026_08_18_193100_add_canonical_ledger_fks_to_reversal_lines` | FK `original_movement_id`/`posted_movement_id` → `stock_movements(id)` |
| `2026_08_18_193200_add_documented_partial_unique_on_inbound_scan_entries` | `ux_erd_inbound_active_item` partial UNIQUE theo ERD bảng 28 |

### Quy ước bắt buộc cho migration mới

1. `down()` phải chịu được việc đối tượng đã biến mất: dùng `HoaNamErdSchema::dropRelation()`,
   `dropIndexIfExists()`, `dropColumnsCascade()`, `DROP ... IF EXISTS`. Một `down()` cứng làm
   `migrate:rollback` dừng giữa chừng và để lại schema không phục vụ được cũng không migrate tiếp
   được — đúng sự cố P0 của WWHN-300.
2. DDL phụ thuộc dữ liệu (thu hẹp kiểu, thêm UNIQUE/FK) phải **fail-closed có thông điệp hành động
   được**, không tự cắt/sửa dữ liệu. Với FK trên dữ liệu lịch sử có thể lệch, tạo `NOT VALID` để
   chặn dữ liệu mới mà không làm hỏng lần migrate của cutover.
3. Drop/recreate compatibility view thì phải dựng lại INSTEAD OF write bridge trong cùng bước.
4. Guard theo driver (`DB::getDriverName()`) khi migration chỉ đúng cho một nhánh schema.

## 2. Seed

| Seeder | Nội dung | Ghi chú |
| --- | --- | --- |
| `Database\Seeders\DatabaseSeeder` | Điểm vào, gọi seeder từng module | `php artisan db:seed` |
| `Modules\Auth\...\AuthDatabaseSeeder` | Permission catalog canonical + role + user quản trị | Nguồn chuẩn của permission key; FE đối chiếu theo đây |
| `Modules\Auth\...\WmsPermissionCompatibilitySeeder` | Bổ sung key tương thích (`scan.execute`, `putaway.*`, `packing_label.*`, `outbound.request.*`…) | Không thu hồi quyền legacy đang cấp |
| `Modules\AuditLog\...\AuditLogDatabaseSeeder` | Khởi tạo audit | |
| `Modules\Warehouse\...\WarehouseDatabaseSeeder` | Kho, master catalog nền | |
| `Modules\Warehouse\...\DongChengCatalogSeeder` | 992 mã hàng THẬT (catalog DongCheng 2026) + ảnh sản phẩm + hồ sơ App PV | Nguồn: `Modules/Warehouse/database/data/dongcheng_catalog.json` |
| `Modules\Warehouse\...\WarehouseBusinessDataSeeder` | Dữ liệu nghiệp vụ mẫu trên danh mục thật | |
| `Modules\Warehouse\...\UatScenarioSeeder` | Kịch bản UAT | Dùng cho QA/UAT |
| `Modules\Warehouse\...\RetireDemoCatalogSeeder` | Dọn mã/hãng/dòng máy demo đời trước | No-op trên CSDL dựng mới |
| `Modules\Warehouse\...\LabelSnapshotSeeder` | Snapshot nhãn mẫu | |
| `Modules\Warehouse\...\Wwhn172ContainerItemInvariantSeeder` | Bất biến container/item | |

Seed phải idempotent và không chứa secret thật; chi tiết ở `docs/output/BE2-HN-QA-002-seed-scripts/`.

## 3. Nạp dữ liệu cuối (final data load)

Không có endpoint public/admin nào để import dữ liệu đầu kỳ — chỉ console job (`DEC-MIG-API-001`).
Runbook đầy đủ: `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md`. Tóm tắt hợp đồng:

```bash
php artisan migration:final-load --mode=DRY_RUN --package=<thư-mục> --run-code=<mã> --executed-by=<user> --output=<file.json>
```

```bash
php artisan migration:final-load --mode=COMMIT --package=<thư-mục> --run-code=<mã> --executed-by=<user> --approved-by=<data-owner> --output=<file.json>
```

```bash
php artisan migration:final-load --mode=ROLLBACK --run-code=<mã> --executed-by=<user> --output=<file.json>
```

Exit code `0` = PASSED/COMMITTED/ROLLED_BACK; `1` = FAILED/BLOCKED. Cutover gate dùng đúng exit code.

- Import Package v1.2 gồm `manifest.json` + 6 CSV; thứ tự nạp cố định
  `AGENCY → SKU → PHYSICAL_CODE → CONTAINER → ITEM → OPENING_BALANCE` (§13.12).
  Template: `docs/migration/import-package-v1.2-template/`.
- `source_checksum` = sha256 trên danh sách `dataset:sha256(file)` đã sắp xếp. Đổi file sau dry-run
  ⇒ `CHECKSUM_CHANGED` ở COMMIT.
- Gate fail-closed: COMMIT cần DRY_RUN PASSED cùng run code, cần `--approved-by` hợp lệ, cần
  reconciliation PASS; operator phải là `app_user` ACTIVE.
- Rollback đảo hiệu ứng bằng movement `REVERSAL` đối ứng (ledger append-only), bằng chứng
  `residual_opening_qty = 0`; tồn đã bị tiêu thụ sau commit ⇒ `MIGRATION_ROLLBACK_NEGATIVE_STOCK`,
  dừng fail-closed.
- Bảng ghi nhận: `migration_runs` (58) và `migration_errors` (59). PII trong error file được mask
  (`raw_value_masked`): `phone`, `address`, `serial_number`, `hardware_uid`, `owner_confirmation`.
- Error code hợp lệ: `DUPLICATE_CODE`, `UNKNOWN_SKU`, `INVALID_ENUM`, `REQUIRED`, `RECON_MISMATCH`,
  `CHECKSUM_CHANGED`; severity `CRITICAL`/`ERROR`/`WARNING`.

## 4. Công cụ vận hành dữ liệu khác

| Lệnh | Dùng để |
| --- | --- |
| `php artisan wms:golive-check` | Cổng kiểm tra trước GoLive (WWHN-300) |
| `php artisan inventory:reconcile` | Đối soát ledger ↔ balance |
| `php artisan audit:reconcile` | Gom audit rơi vào outbox |
| `php artisan warranty:purge-pii` | Purge/anonymize PII bảo hành theo §13.8, có dry-run, loại trừ legal hold |
| `php artisan import:purge-error-files` | Dọn error file import quá hạn |
| `php artisan evidence:stock-snapshot` | Xuất snapshot tồn |
| `php artisan evidence:permission-catalog` | Xuất permission catalog cho FE/QA đối chiếu |
