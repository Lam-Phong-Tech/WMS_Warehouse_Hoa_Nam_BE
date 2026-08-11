# Mở rộng chuỗi truy vết (trace chain) thực tế sang item/physical_code/document-level (BE2-P1-01)

## Hiện trạng thiết kế chuỗi truy vết

Endpoint `GET /api/v1/item-instances/{id}/trace` (`Modules/Warehouse/app/Http/Controllers/ItemInstanceController.php`) thực hiện cơ chế truy vết lai (hybrid traceability) kết hợp chặt chẽ giữa cấp độ vật lý (Item/Serial/Container) và cấp độ chứng từ kế toán (Ledger/Document):

1. **Truy vết cấp độ vật lý (Item & Physical Code Level)**:
   - Hệ thống truy vấn trực tiếp bản ghi `item_instances` cụ thể thông qua định danh `item_id`.
   - Lấy ra chính xác mã vạch/RFID vật lý (`physical_code_id` liên kết bảng `physical_codes`), số serial của nhà sản xuất (`serial_number`), vị trí lưu trữ thực tế (`container_id` liên kết bảng `containers`), và thông tin lô hàng nhập khẩu (`import_batch_id` liên kết bảng `import_batches`).

2. **Truy vết cấp độ chứng từ (Document & Ledger Level)**:
   - Do bảng lịch sử biến động kho (`stock_movements`) trong DB được thiết kế ở mức độ chi tiết SKU (`product_id` khóa ngoại trỏ tới `sku.sku_id`), hệ thống thực hiện truy vấn tất cả các giao dịch phát sinh liên quan đến `sku_id` của chính sản phẩm đó.
   - Các bản ghi `stock_movements` này chứa liên kết trực tiếp tới chứng từ kho gốc (`stock_document_id` trỏ tới `stock_documents`), cho phép ánh xạ ngược lại toàn bộ các chứng từ xuất/nhập (`doc_no`, `doc_type`) đã ảnh hưởng tới dòng chảy của SKU đó.

Chuỗi liên kết hoàn chỉnh được thiết lập:
`Item Instance (Serial) -> Physical Code -> Container -> Import Batch -> SKU Stock Movements -> Stock Documents`.

## Kết quả kiểm thử tự động (Test Verification)

Tính đúng đắn của chuỗi liên kết này được bảo vệ bởi test case `test_trace_endpoint_includes_stock_movements_for_the_same_sku` trong [ItemInstanceTest.php](file:///c:/laragon/www/Hoa_Nam_Backend/tests/Feature/ItemInstanceTest.php):
- Tạo một mã vật lý (PhysicalCode), container, lô nhập (ImportBatch) và SKU.
- Tạo một ItemInstance gắn liền với các thực thể trên.
- Tạo một chứng từ kho (StockDocument) và bản ghi stock movement (StockMovement) tương ứng trỏ tới chứng từ này.
- Gọi API `/trace` và thực hiện các asserts kiểm tra:
  - Khớp đúng ID của item instance, mã vật lý, container, và lô nhập.
  - Trả về đúng bản ghi stock movement liên kết với chứng từ kho tương ứng (`stock_document_id`).

Kết quả chạy test:
```bash
php artisan test tests/Feature/ItemInstanceTest.php
```
Đầu ra: **Passed (8 tests, 32 assertions)**.
