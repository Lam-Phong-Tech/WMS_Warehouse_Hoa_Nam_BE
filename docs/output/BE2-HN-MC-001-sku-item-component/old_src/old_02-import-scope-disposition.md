# Làm rõ phạm vi kỹ thuật của Import Batch (BE2-P1-02)

## Hiện trạng

Mã nguồn `ImportBatchController` (`Modules/Warehouse/app/Http/Controllers/ImportBatchController.php`) hiện tại cung cấp các API CRUD (`index`, `store`, `show`, `update`) để quản lý thông tin lô nhập khẩu dưới dạng các metadata phục vụ cho việc đối soát dữ liệu (traceability). 

Nó không bao gồm các logic nghiệp vụ phức tạp liên quan đến tập tin dữ liệu thô (như tải file mẫu template, danh sách các dòng bị lỗi khi import, báo cáo lỗi chi tiết, hoặc mã băm checksum/count kiểm tra tính toàn vẹn của file excel/csv).

## Phân tích thiết kế và Phân ranh giới tính năng

1. **Phạm vi của WWHN-37**: Nhiệm vụ chính của task là thiết lập mô hình dữ liệu và các API cho SKU, item, component, mã vật lý, container và liên kết lô nhập khẩu (`import_batches`) ở mức cấu trúc (structural metadata). Bảng `item_instances` lưu trữ khóa ngoại `import_batch_id` để biết mặt hàng đó thuộc lô nhập khẩu nào, từ đó đáp ứng yêu cầu traceability.
2. **Luồng import file thô**: Luồng xử lý tệp tin excel/csv thô, phân tích cú pháp (parsing), kiểm tra định dạng dòng, tổng hợp báo cáo lỗi dòng (validation/rejections) và kiểm tra checksum là một tính năng xử lý tệp tin (file-based ingestion) có độ phức tạp cao và thuộc một phân hệ nghiệp vụ khác. Việc tự ý nhồi nhét logic này vào CRUD lô nhập khẩu sẽ gây vi phạm quy tắc thiết kế đơn nhiệm (Single Responsibility Principle) và phá vỡ cấu trúc clean code.

## Giải pháp kỹ thuật

- Giữ nguyên thiết kế CRUD của `ImportBatchController` ở mức metadata quản lý lô nhập để làm điểm tựa liên kết dữ liệu cho item instance.
- Các yêu cầu nghiệp vụ về xử lý tệp tin nhập liệu thô (nếu phát sinh) cần được tách biệt thành một tính năng hoặc một API endpoint xử lý file chuyên biệt trong tương lai nhằm bảo toàn tính cô đọng của kiến trúc hiện tại.
