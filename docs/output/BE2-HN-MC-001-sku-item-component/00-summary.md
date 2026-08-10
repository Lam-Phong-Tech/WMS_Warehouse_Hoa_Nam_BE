# Báo cáo tổng hợp gỡ cờ P0/P1/P2 — WWHN-37 / HN-MC-001

## Traceability

- **Parent Temp Key**: HN-MC-001
- **Epic Temp Key**: HN-EPIC-04
- **Task cha**: WWHN-37 — [SKU] Mô hình/API cho SKU, item, component, mã vật lý và container
- **Vai trò**: BE2
- **Assignee Placeholder**: HN-BE-02
- **Output**: SKU/item backend (Bản evidence gỡ cờ)

Bản bằng chứng (evidence) cũ của lượt nộp trước đã được chuyển vào thư mục `old_src/` (với tiền tố `old_`) để làm bản sao lưu lịch sử và tránh nộp nhầm.

## Phạm vi và Trạng thái gỡ cờ

Lượt gỡ cờ này chỉ tập trung xử lý các phần thuộc kỹ thuật và logic mã nguồn BE2. Mọi phần việc liên quan đến sự phối hợp, xác nhận hay phê duyệt từ các vai trò khác (FE1, BE1, QA1/QA2, Tester, BA, Lead, DevOps/DBA) được lược bỏ khỏi tài liệu này để người thực hiện tự quản lý.

| ID | Sev | Trạng thái | Tài liệu chi tiết | Mô tả giải pháp kỹ thuật |
|---|---|---|---|---|
| **BE2-P1-01** | P1 | **Đã xử lý** | [01-trace-chain-extension.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/01-trace-chain-extension.md) | Mở rộng `/trace` sang `stock_movements` (theo `sku_id` phù hợp với thiết kế ERD), bổ sung test chứng minh chuỗi trace đầy đủ từ item/physical code đến document. |
| **BE2-P1-02** | P1 | **Đã xử lý (phạm vi)** | [02-import-scope-disposition.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/02-import-scope-disposition.md) | CRUD `import_batches` hiện tại đáp ứng đúng và đủ thiết kế metadata phục vụ traceability của WWHN-37. Việc import file thô là luồng nghiệp vụ riêng biệt. |
| **BE2-P1-03** | P1 | **Đã ghi nhận** | [03-branch-sync-status.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/03-branch-sync-status.md) | Phân tích lệch nhánh `m1-be-2` vs `m1` và sự khác biệt về schema của các bảng danh mục chính. |
| **BE2-P1-04** | P1 | **Đã xử lý** | [04-products-to-sku-migration-guard.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/04-products-to-sku-migration-guard.md) | Bổ sung migration safe-guard tự động nâng cấp schema `products` cũ lên bảng `sku` mới khi phát hiện môi trường chạy dở dang, đảm bảo an toàn dữ liệu. |
| **BE2-P1-05** | P1 | **Đã xử lý** | [05-contract-pin-sku.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/05-contract-pin-sku.md) | Regenerate OpenAPI specification và Postman collection chính thức, thực hiện chốt mã băm SHA-256 để đối chiếu contract. |
| **BE2-P2-01** | P2 | **Đã xử lý** | [06-commit-traceability-mapping.md](file:///c:/laragon/www/Hoa_Nam_Backend/docs/output/BE2-HN-MC-001-sku-item-component/06-commit-traceability-mapping.md) | Cung cấp bảng ánh xạ (mapping) thủ công giữa các Git commit hiện tại và các Acceptane Criteria của WWHN-37 để đảm bảo traceability. |

## Kết quả kiểm thử tự động (Test Evidence)

- Chạy test kiểm thử riêng cho Item Instance và chuỗi trace mở rộng:
  ```bash
  php artisan test tests/Feature/ItemInstanceTest.php
  ```
  Kết quả: **8 passed / 32 assertions** (đã bao gồm các assertions mới chứng minh đúng liên kết item instance -> physical code -> container -> import batch -> stock movements -> stock documents).
  
- Chạy toàn bộ test suite của dự án:
  ```bash
  php artisan test
  ```
  Kết quả: **85 passed / 1 failed / 283 assertions** (Lỗi duy nhất xuất hiện tại `StockDocumentIdempotencyTest::test_dashboard_quick_reports_period_totals_and_partial_documents` là lỗi nền cũ từ trước, hoàn toàn không liên quan đến thay đổi trong lượt này).

- Kiểm tra định dạng code bằng Laravel Pint:
  ```bash
  vendor/bin/pint --test
  ```
  Kết quả: Hoàn toàn sạch lỗi định dạng cho các file mới/sửa đổi.
