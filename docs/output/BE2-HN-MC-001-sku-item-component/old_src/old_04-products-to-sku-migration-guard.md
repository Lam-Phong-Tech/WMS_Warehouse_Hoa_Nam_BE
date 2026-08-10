# Migration Guard An Toàn Cho Việc Đổi Tên products -> sku (BE2-P1-04)

## Phân tích rủi ro schema cũ

Tập tin migration cũ `2026_01_01_000031_create_products_table.php` đã bị chỉnh sửa trực tiếp nội dung mã nguồn từ `Schema::create('products', ...)` sang `Schema::create('sku', ...)` thay vì viết một file migration thay đổi cấu trúc độc lập. 

**Rủi ro phát sinh**: Đối với bất kỳ môi trường nào (như UAT hoặc máy dev khác) đã từng chạy lệnh `php artisan migrate` tại thời điểm file còn khai báo bảng `products`, Laravel đã lưu vết tên file này vào bảng hệ thống `migrations`. Khi cập nhật mã nguồn mới chứa bảng `sku`, Laravel sẽ coi migration này là "đã chạy" và **không** áp dụng lại cấu trúc mới nữa, dẫn đến việc thiếu bảng `sku` và thừa bảng `products`.

## Giải pháp kỹ thuật: Migration Guard

Chúng tôi đã viết một migration bổ sung tại [2026_08_02_000002_safe_guard_products_to_sku_rename.php](file:///c:/laragon/www/Hoa_Nam_Backend/Modules/Warehouse/database/migrations/2026_08_02_000002_safe_guard_products_to_sku_rename.php) hoạt động như một lớp phòng thủ tự động:

1. **Điều kiện kích hoạt**: Chỉ thực hiện thay đổi khi phát hiện môi trường bị kẹt ở schema cũ:
   - Bảng `products` vẫn tồn tại (`Schema::hasTable('products') === true`).
   - Bảng `sku` chưa tồn tại (`Schema::hasTable('sku') === false`).
2. **Quy trình chuyển đổi an toàn**: Bọc trong một Database Transaction:
   - Đổi tên bảng: `Schema::rename('products', 'sku')`.
   - Đổi tên các cột khóa chính và định danh: `id -> sku_id`, `code -> sku_code`, `name -> sku_name`.
   - Bổ sung thêm các cột mới phục vụ nghiệp vụ (như `brand_id`, `category_id`, `model_id`, `power_or_voltage`, `package_variant`, `version_code`, `warranty_applicable`, `specification`, `published_to_app`) kèm theo giá trị mặc định (default) thích hợp.
3. **Môi trường Fresh Install / Mới tinh**: Nếu bảng `sku` đã tồn tại sẵn, migration này sẽ tự động bỏ qua (no-op), đảm bảo không gây ra lỗi chạy lại hai lần (idempotency).

## Kết quả kiểm thử

Trong môi trường test tự động sử dụng SQLite in-memory (mỗi lần chạy test đều là fresh install từ đầu), nhánh no-op được kích hoạt và chạy qua toàn bộ test suite thành công mà không phát sinh bất kỳ xung đột nào.
