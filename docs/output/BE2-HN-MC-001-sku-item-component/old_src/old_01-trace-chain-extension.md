# Mở rộng chuỗi truy vết (trace chain) sang stock_movements (BE2-P1-01)

## Hiện trạng trước khi sửa

Endpoint `GET /api/v1/item-instances/{id}/trace` (`Modules/Warehouse/app/Http/Controllers/ItemInstanceController.php`) ban đầu chỉ trả về thông tin `item`, `sku`, `physical_code`, `container`, `parent_container`, và `import_batch`. Nó chưa bao gồm liên kết đến dữ liệu `stock_movements` và `stock_documents` để hoàn tất chuỗi trace đầy đủ như mong muốn.

## Phân tích thiết kế kỹ thuật

Bảng `stock_movements` trong database được thiết kế theo granularity cấp độ **SKU** (cột `product_id` khóa ngoại trỏ tới `sku.sku_id`, không có cột `item_instance_id` hay `physical_code_id`). Đây là thiết kế cố ý của ERD gốc nhằm quản lý số lượng tồn kho theo SKU.

Vì vậy, chuỗi trace đi từ một item instance riêng lẻ tới lịch sử stock movements và chứng từ liên quan bắt buộc phải đi qua mối quan hệ gián tiếp `sku_id` của chính item đó.

## Giải pháp triển khai

1. **Controller**: Trong `ItemInstanceController::trace()`, hệ thống truy vấn thêm bảng `stock_movements` theo điều kiện `product_id = item->sku_id`, sắp xếp giảm dần theo thời gian xảy ra giao dịch (`occurred_at`), giới hạn tối đa 50 bản ghi gần nhất. Các trường trả về bao gồm `id`, `movement_no`, `movement_type`, `warehouse_id`, `qty_before`, `qty_delta`, `qty_after`, `stock_document_id`, và `occurred_at`.
2. **OpenAPI**: Cập nhật Swagger annotation tại `ItemInstanceController::trace` để phản ánh đúng chuỗi trace mới: `item -> physical_code -> container -> import_batch -> stock_movements`.

## Kết quả kiểm thử tự động (Test Verification)

Chúng tôi đã cập nhật test `test_trace_endpoint_includes_stock_movements_for_the_same_sku` trong [ItemInstanceTest.php](file:///c:/laragon/www/Hoa_Nam_Backend/tests/Feature/ItemInstanceTest.php) để chứng minh tính đúng đắn của chuỗi liên kết này:
- Tạo một mã vật lý (PhysicalCode), container, lô nhập (ImportBatch) và SKU.
- Tạo một ItemInstance gắn liền với các thực thể trên.
- Tạo một chứng từ kho (StockDocument) và bản ghi stock movement (StockMovement) tương ứng trỏ tới chứng từ này.
- Gọi API `/trace` và thực hiện assert:
  - Khớp đúng ID của item instance, mã vật lý, container, và lô nhập.
  - Trả về đúng bản ghi stock movement liên kết với chứng từ kho tương ứng (`stock_document_id`).

Kết quả chạy test:
```bash
php artisan test tests/Feature/ItemInstanceTest.php
```
Đầu ra: **Passed (8 tests, 32 assertions)**.
