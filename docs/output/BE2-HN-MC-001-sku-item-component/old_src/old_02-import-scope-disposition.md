# Disposition phạm vi Import batch (BE2-P1-02)

## Hiện trạng

`ImportBatchController` (`Modules/Warehouse/app/Http/Controllers/ImportBatchController.php`) chỉ có CRUD cho **lô nhập khẩu** (header): `index/store/show/update`. Không có endpoint tải template file nhập, không có báo cáo dòng bị reject, không có validation report, không có checksum/count đối soát file.

## Disposition

Đối chiếu với Source & Traceability / Acceptance Criteria của WWHN-37 (task cha HN-MC-001): phạm vi được mô tả là "mô hình/API cho SKU, item, component, mã vật lý và container" — `import_batch` xuất hiện như một **entity metadata** (lô nhập, để `item_instances` FK vào `import_batch_id` phục vụ traceability), không có AC nào nêu rõ yêu cầu xử lý **file** nhập (upload/parse/validate/reject theo dòng). Tính năng "import file" (template, rejected rows, validation report, checksum) là một luồng nghiệp vụ khác — xử lý file thô, tách dòng, validate business rule theo dòng, sinh báo cáo lỗi — độ phức tạp tương đương một task riêng, không nằm trong AC đã chốt của WWHN-37.

**Kết luận: CRUD `import_batches` hiện tại đúng với phạm vi WWHN-37 đã chốt (quản lý metadata lô nhập để trace, không xử lý file).** Việc bổ sung endpoint xử lý file nhập (nếu nghiệp vụ thực sự cần) nên là một task/AC riêng, không tự ý mở rộng scope theo nguyên tắc "Phạm vi thực hiện" của task cha (không tự ý mở rộng scope hoặc thay đổi reference).

Không triển khai thêm code cho phần này trong lượt gỡ cờ này.
