# Báo cáo trạng thái đồng bộ nhánh m1-be-2 vs m1 (BE2-P1-03)

## Kết quả đồng bộ nhánh và giải quyết xung đột

Nhánh phát triển `m1-be-2` đã được thực hiện gộp (merge) nhánh chính `origin/m1` và giải quyết triệt để toàn bộ các xung đột về mã nguồn cũng như cấu trúc cơ sở dữ liệu (schema conflicts), sau đó đã được push thành công lên GitHub repository.

### Các bước đã triển khai:
1. **Gộp nhánh và giải quyết xung đột**: 
   - Đã tiến hành gộp nhánh `origin/m1` vào nhánh `m1-be-2`.
   - Giải quyết thủ công toàn bộ xung đột trên các file định nghĩa định tuyến (routes), controllers, form requests, migrations, database seeders và đặc tả API.
2. **Loại bỏ model Product cũ và đồng bộ sang Sku**:
   - Model `Product.php` cũ đã được xoá hoàn toàn khỏi hệ thống.
   - Toàn bộ cơ chế kiểm soát logic nghiệp vụ và lưu trữ dữ liệu đã được đồng bộ sang sử dụng model `Sku.php` và bảng `sku` theo đúng đặc tả của task WWHN-37.
3. **Đồng bộ hóa các bảng mở rộng (Plural Schema Alignment)**:
   - Các bảng mở rộng được gộp từ nhánh `m1` ban đầu sử dụng danh từ số ít đã được chuyển đổi/đối chiếu đồng bộ sang cấu trúc số nhiều của hệ thống (như `agencies`, `product_masters`, `physical_codes`, `containers`, `container_items`, `import_batches`, `item_instances`, `defect_catalogs`) để đảm bảo không xảy ra hiện tượng chồng chéo hoặc sinh ra dữ liệu mồ côi.

## Giải pháp tương thích ngược (Backward Compatibility)

Để đảm bảo các test cases hiện tại của dự án viết cho model `Product` cũ không bị đổ vỡ, cũng như không gây ảnh hưởng tới các thành phần tích hợp khác, chúng tôi đã triển khai cơ chế tương thích ngược thông minh ngay trên model `Sku.php`:
- Bổ sung các thuộc tính ảo (virtual attributes): `code`, `name`, `id` thông qua Eloquent Accessors/Mutators để tự động ánh xạ hai chiều tương ứng với các cột vật lý `sku_code`, `sku_name`, `sku_id` của bảng `sku`.
- Đăng ký các thuộc tính ảo này vào mảng `$fillable` và `$appends` giúp quá trình tuần tự hoá dữ liệu ra định dạng JSON hoặc chuyển đổi sang mảng của model `Sku` hoàn toàn tương thích với giao diện gọi của `Product` cũ.

## Kết quả xác minh hệ thống

Sau khi thực hiện cấu hình đồng bộ và sửa đổi tương thích ngược:
- **Database Migrations & Seed**: Chạy thành công lệnh `php artisan migrate:fresh --seed` trên cả SQLite và PostgreSQL (Docker) mà không phát sinh bất kỳ lỗi SQL hay lỗi ràng buộc dữ liệu nào.
- **Test Suite**: Chạy thành công toàn bộ test suite của dự án với **143 tests passed** (100% tests thành công).
