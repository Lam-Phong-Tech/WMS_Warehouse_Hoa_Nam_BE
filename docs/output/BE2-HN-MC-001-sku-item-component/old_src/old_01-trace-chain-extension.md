# Mở rộng trace chain sang stock_movements (BE2-P1-01)

## Hiện trạng trước khi sửa

`GET /api/v1/item-instances/{id}/trace` (`Modules/Warehouse/app/Http/Controllers/ItemInstanceController.php`) chỉ trả `item, sku, physical_code, container, parent_container, import_batch` — chưa trace tới `stock_movements`/`stock_documents` như AC gốc của WWHN-37 yêu cầu.

Khảo sát schema: `stock_movements.product_id` FK vào `sku.sku_id` (migration `2026_01_01_000035_create_stock_movements_table.php`), **không có** cột `item_instance_id`/`physical_code_id` trên bảng này — đây là thiết kế ERD gốc: movement được ghi nhận ở granularity SKU (tổng số lượng theo kho), không theo từng item vật lý riêng lẻ. Vì vậy phần "trace tới stock movement" chỉ có thể đi qua `sku_id` của item, không thể trace theo `item_instance_id` trực tiếp — không phải lỗi code, mà là giới hạn của schema hiện có (đổi sang trace item-level sẽ cần thêm cột/bảng liên kết mới, ngoài phạm vi 1 flag P1).

## Thay đổi

`ItemInstanceController::trace()` bổ sung bước cuối: query `stock_movements` theo `product_id = item->sku_id`, sắp theo `occurred_at` giảm dần, giới hạn 50 bản ghi gần nhất, trả về kèm `stock_document_id` (để FE/QA tự trỏ tiếp sang `stock_documents` nếu cần chi tiết chứng từ). Response thêm key `stock_movements` bên cạnh 6 key cũ. OpenAPI `summary`/response `description` cập nhật để phản ánh chuỗi trace mới: `sku -> physical_code -> container (cha) -> import_batch -> stock_movements`.

Module Warehouse đã được xác nhận là legacy/grandfathered (theo CLAUDE.md) nên gọi Eloquent (`StockMovement::query()...`) trực tiếp trong controller là nhất quán với style hiện tại của module, không vi phạm convention.

## Test evidence

`tests/Feature/ItemInstanceTest.php` — thêm `test_trace_endpoint_includes_stock_movements_for_the_same_sku`: tạo 1 sku, 1 item_instance, 1 `stock_movements` cùng `sku_id`, gọi `/trace`, assert `data.stock_movements` có đúng 1 phần tử, đúng `id`/`movement_type`.

```
php artisan test tests/Feature/ItemInstanceTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":27}
```

`vendor/bin/pint --test` sạch cho `ItemInstanceController.php` và `ItemInstanceTest.php`.
