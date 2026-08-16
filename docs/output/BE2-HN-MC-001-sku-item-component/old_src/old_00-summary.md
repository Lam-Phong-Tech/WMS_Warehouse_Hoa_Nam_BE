# Báo cáo tổng hợp gỡ cờ P0/P1/P2 — WWHN-37 / HN-MC-001

## Traceability

- **Parent Temp Key**: HN-MC-001
- **Epic Temp Key**: HN-EPIC-04
- **Task cha**: WWHN-37 — [SKU] Mô hình/API cho SKU, item, component, mã vật lý và container
- **Vai trò**: BE2
- **Assignee Placeholder**: HN-BE-02
- **Output**: SKU/item backend (Bản evidence gỡ cờ)

Bản bằng chứng (evidence) của các lượt đánh giá trước đã được lưu trữ an toàn trong thư mục `old_src/` để đảm bảo tính lịch sử và tránh nhầm lẫn khi nộp.

## Phạm vi và Trạng thái gỡ cờ

Lượt gỡ cờ này đã hoàn tất việc xử lý toàn bộ các điểm phản hồi kỹ thuật từ Hội đồng đánh giá (Red Flag Matrix) thuộc vai trò và phạm vi mã nguồn của BE2:

| ID | Sev | Trạng thái | Tài liệu chi tiết | Mô tả giải pháp kỹ thuật |
|---|---|---|---|---|
| **BE2-P1-01** | P1 | **Đã xử lý** | [01-trace-chain-extension.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/01-trace-chain-extension.md) | Mở rộng chuỗi truy vết lai (hybrid trace chain) liên kết dữ liệu vật lý (Item, Physical Code, Container, Import Batch) với dữ liệu sổ cái biến động kho (Stock Movements) và chứng từ tương ứng (Stock Documents). |
| **BE2-P1-02** | P1 | **Đã xử lý** | [02-import-scope-disposition.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/02-import-scope-disposition.md) | Phân định ranh giới kiến trúc rõ ràng giữa lớp nghiệp vụ/truy vết (Metadata lưu tại `import_batches`) và lớp nạp dữ liệu hàng loạt từ file thô (chạy bất đồng bộ qua Queue). |
| **BE2-P1-03** | P1 | **Đã xử lý** | [03-branch-sync-status.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/03-branch-sync-status.md) | Thực hiện gộp (merge) hoàn chỉnh nhánh `origin/m1` vào nhánh `m1-be-2`, đồng bộ cấu trúc bảng số nhiều (plural) và xây dựng lớp tương thích ngược ảo (virtual attributes) giúp toàn bộ test suite chạy thông suốt. |
| **BE2-P1-04** | P1 | **Đã xử lý** | [04-products-to-sku-migration-guard.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/04-products-to-sku-migration-guard.md) | Xây dựng migration guard đổi tên bảng `products -> sku` an toàn, bổ sung các bước giả lập (dry-run), quy trình kiểm tra tính toàn vẹn số lượng (count) và kế hoạch sao lưu/phục hồi (backup/rollback). |
| **BE2-P1-05** | P1 | **Đã xử lý** | [05-contract-pin-sku.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/05-contract-pin-sku.md) | Cập nhật tài liệu OpenAPI và Postman collection mới nhất, chốt kích thước/mã băm SHA-256 chính xác, và tích hợp kiểm thử tự động tích hợp thông qua công cụ Newman. |
| **BE2-P2-01** | P2 | **Đã xử lý** | [06-commit-traceability-mapping.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/06-commit-traceability-mapping.md) | Chuẩn hóa lịch sử git commit trên nhánh phát triển tuân thủ quy tắc Conventional Commits dưới mã task `WWHN-171` giúp tự động hóa việc truy vết. |

## Kết quả kiểm thử tự động (Test Evidence)

- **Chạy toàn bộ test suite của hệ thống**:
  ```bash
  php artisan test
  ```
  Kết quả: **143 passed / 0 failed / 911 assertions** (100% tests thành công).

- **Chạy test kiểm thử riêng cho Item Instance và chuỗi trace mở rộng**:
  ```bash
  php artisan test tests/Feature/ItemInstanceTest.php
  ```
  Kết quả: **8 passed / 32 assertions** (chứng minh đúng chuỗi liên kết item -> physical code -> container -> import batch -> stock movements -> stock documents).

- **Chạy tích hợp API Contract tự động**:
  ```bash
  npm run api:smoke
  ```
  Kết quả: Newman chạy thành công 100% các kịch bản kiểm thử API khói trên môi trường local.
