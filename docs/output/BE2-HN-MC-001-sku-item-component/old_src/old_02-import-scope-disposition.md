# Phân định phạm vi kiến trúc kỹ thuật của tính năng Import (BE2-P1-02)

## Ranh giới thiết kế hệ thống của tính năng Import

Để đảm bảo tính độc lập, hiệu năng và khả năng bảo trì của lõi nghiệp vụ WMS, tính năng Import trong phạm vi task WWHN-37 được phân định rõ ràng thành hai lớp kiến trúc kỹ thuật riêng biệt:

1. **Lớp dữ liệu và truy vết giao dịch (Transaction & Traceability Layer)**:
   - Mô hình dữ liệu lô nhập khẩu được quản lý tập trung thông qua bảng `import_batches`. 
   - Bảng này lưu trữ toàn bộ thông tin siêu dữ liệu (metadata) cần thiết để truy vết: mã lô nhập (`import_batch_code`), mã container vận chuyển (`shipment_container_code`), số lô của nhà cung cấp (`supplier_batch_no`), kỳ nhập hàng (`import_period`), và tên nhà cung cấp (`supplier_name`).
   - Mỗi thực thể `item_instances` được tạo ra bắt buộc phải liên kết trực tiếp với một `import_batch_id` tương ứng, đảm bảo khả năng truy vết nguồn gốc xuất xứ của từng thiết bị/linh kiện vật lý.

2. **Lớp xử lý tệp tin và nạp dữ liệu hàng loạt (Batch Loading Layer)**:
   - Tiến trình tải lên tệp tin nghiệp vụ (CSV, Excel) và phân tách dữ liệu thô (raw data parsing) được chuyển giao toàn bộ cho dịch vụ nạp dữ liệu độc lập (`ImportService`).
   - Việc chuyển đổi từ tệp dữ liệu thô sang các bản ghi SKU/Item trong cơ sở dữ liệu được thực hiện thông qua các Job bất đồng bộ (Laravel Queue). Thiết kế này giúp bảo vệ hệ thống khỏi các rủi ro về cạn kiệt tài nguyên bộ nhớ (Memory Exhaustion) hoặc nghẽn kết nối khi xử lý các tệp tin có dung lượng lớn, đồng thời cô lập hoàn toàn tiến trình nạp dữ liệu hàng loạt ra khỏi luồng xử lý giao dịch kho trực tuyến (Online Transaction Processing).

Sự phân tách kiến trúc này đảm bảo ranh giới hệ thống rõ ràng giữa quản trị vòng đời dữ liệu giao dịch kho và cơ chế hỗ trợ kỹ thuật nạp dữ liệu hàng loạt từ bên ngoài.
