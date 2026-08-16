# Schema / Migrations

## Migration mới

| File | Bảng | Cột chính |
|---|---|---|
| `2026_01_01_000030_create_product_masters_table.php` | `product_masters` | `master_id` PK, `master_type` (BRAND/CATEGORY/MODEL/PACKAGE/POWER), `code` (UQ theo master_type), `name`, `parent_master_id` (self FK), `status` |
| `2026_01_01_000044_create_physical_codes_table.php` | `physical_codes` | `physical_code_id` PK, `code_value` (UQ), `code_type`, `carrier_type`, `hardware_uid` (UQ nullable), `status`, `replaced_by_code_id` (self FK) |
| `2026_01_01_000045_create_import_batches_table.php` | `import_batches` | `import_batch_id` PK, `import_batch_code` (UQ), `shipment_container_code`, `supplier_batch_no`, `import_period`, `supplier_name`, `status` |
| `2026_01_01_000046_create_containers_table.php` | `containers` | `container_id` PK, `container_code` (UQ), `container_type` (CARTON/BOX/TRAY), `physical_code_id` (FK, UQ), `primary_sku_id` (FK sku, nullable), `parent_container_id` (self FK), `zone_code`, `location_hint`, `status`, `version` |
| `2026_01_01_000047_create_item_instances_table.php` | `item_instances` | `item_id` PK, `sku_id` (FK sku, restrictOnDelete), `physical_code_id` (FK, UQ nullable), `serial_number` (UQ nullable), `container_id` (FK nullable), `item_status`, `item_condition`, `import_batch_id` (FK nullable), `version` |
| `2026_01_01_000048_create_container_items_table.php` | `container_items` | `container_item_id` PK, `container_id` (FK), `item_id` (FK nullable), `sku_id` (FK nullable), `quantity`, `status` (ACTIVE/REMOVED) |

## Migration sửa tại chỗ (đổi tên bảng theo ERD)

`2026_01_01_000031_create_products_table.php` — nội dung đổi từ tạo bảng `products` (stub) sang tạo bảng `sku` đúng ERD §5.1: `sku_id`, `sku_code`, `sku_name`, `sku_type`, `unit`, `is_serial_tracked`, `brand_id`/`category_id`/`model_id` (FK `product_masters`), `power_or_voltage`, `package_variant`, `version_code`, `warranty_applicable`, `specification` (JSON), `published_to_app`, `status`.

## Migration sửa 1 dòng (repoint FK sang `sku`, giữ nguyên cột `product_id`)

`->constrained('products')` → `->constrained('sku', 'sku_id')` tại 10 file:
`2026_01_01_000033_create_stock_document_lines_table.php`, `2026_01_01_000034_create_inventory_balances_table.php`, `2026_01_01_000035_create_stock_movements_table.php`, `2026_01_01_000041_create_safe_stock_configs_table.php`, `2026_01_01_000042_create_safe_stock_alerts_history_table.php`, `2026_01_01_000043_create_inventory_serials_table.php`, `2026_07_30_000002_create_inventory_reservations_table.php`, `2026_07_30_000003_create_inventory_report_snapshots_table.php`, `2026_07_30_000005_create_stock_request_lines_table.php`, `2026_07_30_000006_create_in_transit_ledger_table.php`.

Đi kèm: cập nhật quan hệ Eloquent `belongsTo(Product::class)` → `belongsTo(Sku::class, 'product_id', 'sku_id')` ở 8 model (`StockMovement`, `InventoryBalance`, `InventoryReportSnapshot`, `InventoryReservation`, `SafeStockConfig`, `StockDocumentLine`, `StockRequestLine`, `InTransitLedger`, `InventorySerial`), và `exists:products,id` → `exists:sku,sku_id` ở 10 FormRequest liên quan inbound/outbound/transfer/stock-request/safe-stock.

## Self-referencing FK — lưu ý kỹ thuật

`product_masters.parent_master_id`, `physical_codes.replaced_by_code_id`, `containers.parent_container_id` là FK tự tham chiếu. Postgres báo lỗi "no unique constraint matching given keys" nếu khai báo FK cùng lúc với PK trong 1 lệnh `Schema::create` — đã tách thành `Schema::table(...)->foreign(...)` chạy sau khi bảng đã tồn tại (fix phát hiện qua chạy thật trên Postgres, không chỉ SQLite test).

## Route ↔ Permission mới

| Route | Permission |
|---|---|
| `/product-masters` (GET/POST/GET id/PATCH) | `product_master.view` / `product_master.manage` |
| `/products` PATCH (mới, các GET/POST giữ nguyên `product.view`/`product.manage`) | `product.manage` |
| `/physical-codes` (GET/POST/GET id/PATCH) | `physical_code.view` / `physical_code.manage` |
| `/import-batches` (GET/POST/GET id/PATCH) | `import_batch.view` / `import_batch.manage` |
| `/containers` (GET/POST/GET id/PATCH), `/containers/{id}/items` (POST/DELETE) | `container.view` / `container.manage` |
| `/item-instances` (GET/POST/GET id/PATCH/trace) | `item_instance.view` / `item_instance.manage` |

Permission mới đã seed vào `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` và gán cho role demo (`.manage` → `WAREHOUSE_MANAGER`; `.view` → `WAREHOUSE_STAFF`/`WAREHOUSE_APPROVER`/`WAREHOUSE_KEEPER`/`VIEWER`; `WAREHOUSE_KEEPER` có thêm `physical_code.manage`/`container.manage`/`item_instance.manage` vì đây là người trực tiếp xử lý hàng vật lý).
