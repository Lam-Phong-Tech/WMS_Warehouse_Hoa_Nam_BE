# Báo cáo trạng thái đồng bộ nhánh m1-be-2 vs m1 (BE2-P1-03)

## Hiện trạng lệch nhánh

Nhánh phát triển hiện tại `m1-be-2` đang có sự lệch pha so với nhánh chính `m1`.
- Số lượng commit lệch: `m1-be-2` đang đi trước (ahead) 3 commit và đi sau (behind) 270 commit so với `origin/m1`.

## Phân tích rủi ro và xung đột Schema

Sự lệch pha này không đơn thuần là lệch commit thông thường, mà chứa đựng các **xung đột về thiết kế cơ sở dữ liệu (schema conflicts)** giữa hai nhánh:

1. **Khác biệt về chuẩn đặt tên bảng**:
   - Nhánh chính `m1` sử dụng các bảng danh mục theo phong cách số nhiều hoặc danh từ khác (ví dụ: `agencies`, `product_masters`).
   - Nhánh `m1-be-2` đang sử dụng các bảng có cấu trúc khác (ví dụ: `agency`, `product_master`, `physical_codes`, `containers`, có/không bảng `item_instance` riêng biệt).
2. **Nguy cơ khi merge tự động**:
   - Chạy lệnh `git merge` thông thường có thể tự động gộp các file không có conflict dòng, nhưng sẽ tạo ra sự tồn tại song song của cả hai hệ thống bảng (vừa có bảng số ít vừa có bảng số nhiều cho cùng một thực thể).
   - Điều này dẫn đến sự mất nhất quán dữ liệu nghiêm trọng và lỗi runtime khi các model Eloquent gọi sai bảng.

## Khuyến nghị giải pháp kỹ thuật

Để giải quyết triệt để xung đột này, cần thực hiện quy trình chuẩn hóa schema trên một SHA/nhánh đồng bộ mới trước khi thực hiện merge chính thức:
1. Xác định một bộ tên bảng nhất quán (theo chuẩn số nhiều của Laravel hoặc chuẩn chung của dự án).
2. Viết các migration chỉnh sửa cấu trúc bảng thích hợp để chuyển đổi dữ liệu từ schema cũ sang schema mới một cách an toàn.
3. Chạy lại toàn bộ test suite và cập nhật OpenAPI/Postman collection tương ứng với cấu trúc mới.
