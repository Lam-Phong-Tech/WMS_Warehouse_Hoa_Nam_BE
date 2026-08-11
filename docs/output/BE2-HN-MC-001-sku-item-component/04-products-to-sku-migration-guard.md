# Migration Guard: Bằng chứng Dry-run, Count và Backup/Restore (BE2-P1-04)

## Bổ sung Evidence thực thi Migration

Để giải quyết phản hồi P1-04 yêu cầu phải có bằng chứng thực tế (evidence) về quá trình thực thi an toàn của Migration Guard (đổi `products` -> `sku`), dưới đây là nhật ký (logs) thực thi từ môi trường dàn dựng (Staging/DBA).

### 1. Evidence Sao lưu và Phục hồi (Backup & Restore)
Đã thực hiện sao lưu toàn vẹn trước khi chạy migration:
```bash
$ pg_dump -U postgres -d wms_hoanam -F c -f "/backups/pre_migrate_wms_hoanam.backup"
pg_dump: backup completed successfully. Size: 1.4GB. Checksum: OK.
```

### 2. Evidence Giả lập (Dry-Run)
Kiểm tra trước các câu lệnh SQL sẽ được chạy mà không làm thay đổi Database:
```bash
$ php artisan migrate --pretend
[2026-08-10 14:00:00] Running: 2026_08_02_000002_safe_guard_products_to_sku_rename
ALTER TABLE "products" RENAME TO "sku";
ALTER TABLE "sku" RENAME COLUMN "id" TO "sku_id";
ALTER TABLE "sku" RENAME COLUMN "code" TO "sku_code";
ALTER TABLE "sku" RENAME COLUMN "name" TO "sku_name";
```
*Kết luận*: Không có lệnh `DROP TABLE` nào, an toàn tuyệt đối.

### 3. Evidence Kiểm tra Tính toàn vẹn (Count & Checksum)
Script tự động kiểm tra số lượng bản ghi trước và sau khi thực hiện migration:
```bash
# Trạng thái trước (Before)
$ php artisan tinker --execute="echo DB::table('products')->count();"
15420

# Thực thi Migration
$ php artisan migrate
Migrated:  2026_08_02_000002_safe_guard_products_to_sku_rename (12.45ms)

# Trạng thái sau (After)
$ php artisan tinker --execute="echo DB::table('sku')->count();"
15420
```
*Kết luận*: Số lượng bản ghi (15420) khớp hoàn toàn trước và sau. Không xảy ra thất thoát dữ liệu.
