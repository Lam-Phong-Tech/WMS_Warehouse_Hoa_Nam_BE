# Lưu ý khi apply migration

## Bắt buộc dùng `migrate:fresh`, không dùng `migrate` tăng dần

Vì migration `2026_01_01_000031_create_products_table.php` bị **sửa nội dung tại chỗ** (từ tạo bảng `products` sang tạo bảng `sku`) thay vì thêm migration ALTER/RENAME mới, Laravel sẽ không tự phát hiện thay đổi nếu migration này đã từng chạy trên một môi trường (`migrations` table chỉ lưu tên file, không lưu nội dung). Nếu môi trường đó chạy `php artisan migrate` thông thường, bảng vẫn sẽ là `products` cũ — **sai lệch với code**.

**Bắt buộc**: mọi môi trường (dev/CI/staging) đang có migration cũ phải chạy lại:

```bash
php artisan migrate:fresh --seed
```

Đây là quyết định đánh đổi được chấp nhận vì dự án đang **sprint-1, chưa có dữ liệu production** — evidence các task BE2 trước đó (`docs/output/BE2-HN-FND-002-master-danh-muc/`) cũng đều dùng `migrate:fresh --seed` làm quy trình chuẩn, không phải `migrate` tăng dần.

## Đã verify

- `migrate:fresh --seed` chạy sạch trên Postgres thật (docker) — 2 lần liên tiếp, không lỗi, không cần can thiệp thủ công (idempotent).
- `migrate:fresh` trên SQLite in-memory (test suite) — chạy trong mọi lần `RefreshDatabase` của 269 assertion, không lỗi.

## Nếu cần chuyển sang môi trường đã có dữ liệu thật trong tương lai

Khi dự án qua khỏi sprint-1 và có dữ liệu thật trong bảng `products`, sẽ cần viết migration ALTER riêng (rename table + copy dữ liệu + rename FK) thay vì sửa tại chỗ như hiện tại — điều này **ngoài phạm vi task WWHN-37** và nên có task riêng khi thời điểm đó đến.
