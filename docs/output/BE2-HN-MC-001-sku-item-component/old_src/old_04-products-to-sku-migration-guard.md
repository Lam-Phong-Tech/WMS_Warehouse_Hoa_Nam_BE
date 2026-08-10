# Guard an toàn cho migration products→sku (BE2-P1-04)

## Hiện trạng trước khi sửa

`git diff` giữa 2 commit của cùng file `Modules/Warehouse/database/migrations/2026_01_01_000031_create_products_table.php` (`576d48e` → `6c13c2a`) xác nhận: migration này bị **sửa tại chỗ** — commit đầu tạo `Schema::create('products', ...)` (cột `id/code/name/unit/sku_type/is_serial_tracked/status`), commit sau ghi đè trực tiếp `up()`/`down()` của cùng file thành `Schema::create('sku', ...)` (cột `sku_id/sku_code/sku_name` + nhiều cột mới). Không có migration ALTER/RENAME riêng.

**Rủi ro thật**: môi trường nào đã từng `php artisan migrate` khi file còn ở bản `products` sẽ có dòng migration này trong bảng `migrations` (đánh dấu "đã chạy"). Chạy lại `migrate` bình thường sau khi code đổi sang bản `sku` **sẽ không** tự áp dụng lại — Laravel chỉ so theo tên file/class, không so nội dung. Môi trường đó cần `migrate:fresh` (mất toàn bộ dữ liệu) hoặc một cách nâng cấp tại chỗ an toàn.

## Thay đổi

Thêm migration mới `Modules/Warehouse/database/migrations/2026_08_02_000002_safe_guard_products_to_sku_rename.php`:

- Chỉ hành động khi phát hiện đúng tình huống "còn kẹt ở schema cũ": `Schema::hasTable('products')` = true **và** `Schema::hasTable('sku')` = false. Trường hợp này, thực hiện `Schema::rename('products', 'sku')`, `renameColumn` cho `id→sku_id`, `code→sku_code`, `name→sku_name`, rồi bổ sung các cột mới (`brand_id/category_id/model_id/power_or_voltage/package_variant/version_code/warranty_applicable/specification/published_to_app`) với default hợp lý — bọc trong 1 transaction.
- Nếu `sku` đã tồn tại (fresh install hoặc môi trường đã từng `migrate:fresh`) → no-op, không làm gì, tránh double-alter.
- `down()` cố tình để no-op có ghi chú — sau khi nâng cấp tại chỗ có thể đã phát sinh dữ liệu ở cột mới, hạ ngược schema sẽ mất dữ liệu, nên "an toàn" ở đây nghĩa là không tự động revert mù quáng.

Phần "DevOps/DBA xác nhận môi trường sprint hiện tại dùng fresh" theo yêu cầu gốc của flag nằm ngoài phạm vi tự làm một mình — không note thêm; migration guard này tự nó xử lý được cả 2 trường hợp (fresh hoặc không) mà không cần xác nhận trước.

## Test evidence

Test suite chạy trên SQLite in-memory (`RefreshDatabase`, `migrate:fresh` mỗi lần) nên luôn ở trạng thái "đã có `sku`, chưa từng có `products`" → nhánh no-op của guard được thực thi ở mọi lần chạy test, xác nhận qua toàn bộ suite không phát sinh lỗi:

```
php artisan test
{"tests":86,"passed":85,"failed":1}  # 1 fail pre-existing (StockDocumentIdempotencyTest::dashboard_quick), không liên quan
```

`vendor/bin/pint --test` sạch cho file migration mới.
