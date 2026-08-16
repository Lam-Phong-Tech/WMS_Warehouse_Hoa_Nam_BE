# Migration Guard An Toàn Cho Việc Đổi Tên products -> sku (BE2-P1-04)

## Phân tích rủi ro và giải pháp Migration Guard

Tập tin migration cũ `2026_01_01_000031_create_products_table.php` đã bị chỉnh sửa trực tiếp nội dung mã nguồn từ `Schema::create('products', ...)` sang `Schema::create('sku', ...)` thay vì viết một file migration thay đổi cấu trúc độc lập. 

Đối với bất kỳ môi trường nào (như UAT hoặc production) đã chạy cấu trúc cũ, việc nâng cấp mã nguồn mới sẽ gây ra lỗi thiếu bảng `sku` do Laravel coi migration đó đã chạy. Để khắc phục triệt để, chúng tôi đã viết một migration bổ sung tại [2026_08_02_000002_safe_guard_products_to_sku_rename.php](file:///c:/laragon/www/Hoa_Nam_Backend/Modules/Warehouse/database/migrations/2026_08_02_000002_safe_guard_products_to_sku_rename.php) hoạt động như một lớp phòng thủ tự động (Migration Guard):
* **Điều kiện kích hoạt**: Chỉ chạy khi phát hiện môi trường bị kẹt ở schema cũ: Bảng `products` vẫn tồn tại và bảng `sku` chưa được tạo.
* **Quy trình chuyển đổi**: Bọc trong Database Transaction:
  - Đổi tên bảng: `Schema::rename('products', 'sku')`.
  - Đổi tên cột: `id -> sku_id`, `code -> sku_code`, `name -> sku_name`.
  - Bổ sung thêm các cột mới phục vụ nghiệp vụ (như `brand_id`, `category_id`, `model_id`, `power_or_voltage`, `package_variant`, `version_code`, `warranty_applicable`, `specification`, `published_to_app`) kèm theo giá trị mặc định thích hợp.
* **Môi trường Fresh Install**: Nếu bảng `sku` đã tồn tại sẵn, migration này sẽ tự động bỏ qua (no-op), đảm bảo không gây ra lỗi chạy lại hai lần.

## Quy trình xác minh và đảm bảo an toàn dữ liệu

Để bảo vệ dữ liệu tuyệt đối khi thực hiện cập nhật cấu trúc cơ sở dữ liệu trên môi trường thực tế, chúng tôi áp dụng quy trình kiểm soát kỹ thuật như sau:

### 1. Giả lập thử nghiệm (Dry-Run Verification)
* Trước khi chạy migration thực tế, thực hiện giả lập để in ra toàn bộ các câu lệnh SQL sẽ được thực thi trên Database engine:
  ```bash
  php artisan migrate --pretend
  ```
* Xem xét kỹ danh sách các câu lệnh SQL được giả lập để đảm bảo không có câu lệnh `DROP` hoặc câu lệnh gây mất dữ liệu ngoài ý muốn.

### 2. Kiểm tra tính toàn vẹn (Count & Checksum Verification)
* Chạy truy vấn đếm tổng số dòng sản phẩm trước khi cập nhật:
  ```sql
  SELECT COUNT(*) FROM products;
  ```
* Chạy truy vấn đối chiếu sau khi kết thúc migration:
  ```sql
  SELECT COUNT(*) FROM sku;
  ```
* So sánh hai giá trị số lượng dòng để đảm bảo không bị mất mát hay nhân bản bất kỳ bản ghi nào trong quá trình đổi tên bảng.

### 3. Phương án Sao lưu và Phục hồi (Backup & Rollback Strategy)
* **Sao lưu (Backup)**: Trước khi tiến hành áp dụng migration, bắt buộc phải tạo bản sao lưu vật lý toàn vẹn của cơ sở dữ liệu:
  ```bash
  pg_dump -U postgres -d wms_hoanam -F c -b -v -f "/backups/pre_rename_wms_hoanam.backup"
  ```
* **Phục hồi (Rollback)**: Nếu quá trình chạy migration phát sinh lỗi bất ngờ, Database transaction tự động rollback các thay đổi chưa hoàn tất về trạng thái ban đầu. Trong trường hợp nghiêm trọng cần khôi phục lại cơ sở dữ liệu từ tệp sao lưu:
  ```bash
  pg_restore -U postgres -d wms_hoanam -c -v "/backups/pre_rename_wms_hoanam.backup"
  ```

Quy trình xác minh này đảm bảo việc chuyển đổi schema từ `products` sang `sku` diễn ra an toàn, có khả năng phục hồi dữ liệu lập tức khi xảy ra sự cố.
